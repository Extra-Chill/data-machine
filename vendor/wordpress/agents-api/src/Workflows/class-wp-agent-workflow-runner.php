<?php
/**
 * Workflow runner — executes a {@see WP_Agent_Workflow_Spec} step-by-step.
 *
 * The runner is intentionally narrow:
 *
 *   1. Validate inputs against the spec's input schema (presence + type).
 *   2. Walk steps in order. For each step:
 *        a. Resolve `${...}` bindings against `inputs` + earlier step outputs.
 *        b. Dispatch to a step-type handler (`ability` / `agent` ship by
 *           default; consumers register more via the handler map).
 *        c. Record the per-step outcome (status, output, error, timing).
 *        d. If the step failed and the spec didn't opt into `continue_on_error`,
 *           short-circuit the run.
 *   3. Update the recorder once at start, once per step, and once at end.
 *   4. Return a final {@see WP_Agent_Workflow_Run_Result}.
 *
 * What the runner does NOT do:
 *   - Branching, nested workflows. Step-handler map is the extension point
 *     for those — a consumer can register a `branch` handler that runs a
 *     sub-list, or a `workflow` handler that calls this runner recursively.
 *   - Real concurrency. The runner is synchronous and single-process; PHP
 *     ships no threads here. The `parallel` step type expresses *fanout
 *     orchestration* (declare N branches, propagate a shared immutable
 *     context to each, collect every branch output, hand them to an
 *     aggregator) — not parallel execution. Whether branches actually run on
 *     separate processes (Action Scheduler, sandboxed subprocesses, loopback)
 *     is a consumer-supplied executor concern; the substrate owns the
 *     dispatch/collect/aggregate contract, not the concurrency mechanism.
 *   - Triggering. Triggers are wired separately
 *     ({@see WP_Agent_Workflow_Action_Scheduler_Bridge} for cron, and a
 *     consumer-registered listener for `wp_action`). The runner only
 *     executes; it doesn't schedule or hook.
 *   - Storage. Specs come in as Spec instances (often pulled from a
 *     {@see WP_Agent_Workflow_Store} or registry); recorders persist
 *     run history.
 *
 * Usage:
 *
 *     $runner = new WP_Agent_Workflow_Runner( $recorder, $step_handlers );
 *     $result = $runner->run( $spec, [ 'comment_id' => 42 ] );
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

use AgentsAPI\AI\Abilities\WP_Agent_Ability_Dispatcher;
use AgentsAPI\AI\WP_Agent_Run_Control;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class WP_Agent_Workflow_Runner {

	public const RUN_CONTROL_STORE = 'agents_api_workflow_run_control';

	/**
	 * @var array<string,mixed> Step type → handler candidate. Each callable handler
	 *                          receives ( array $resolved_step, array $context )
	 *                          and returns array|WP_Error. Consumers extend via
	 *                          the constructor or the filter.
	 */
	protected array $step_handlers;

	/**
	 * @param array<string,mixed> $step_handlers Step type handler candidates.
	 */
	public function __construct(
		protected ?WP_Agent_Workflow_Run_Recorder $recorder = null,
		array $step_handlers = array()
	) {
		$defaults = array(
			'ability'  => array( __CLASS__, 'default_ability_handler' ),
			'agent'    => array( __CLASS__, 'default_agent_handler' ),
			'foreach'  => array( __CLASS__, 'default_foreach_handler' ),
			'parallel' => array( __CLASS__, 'default_parallel_handler' ),
		);

		/**
		 * Filter the step-type handler map. Consumers add new step types
		 * (`branch`, `parallel`, `workflow`, …) by registering a callable
		 * here. Default `ability` and `agent` handlers can also be replaced
		 * if a consumer wants to substitute a different ability / agent
		 * runtime.
		 *
		 * @since 0.103.0
		 *
		 * @param array<string,mixed> $handlers Default + caller-supplied handlers.
		 */
		$this->step_handlers = (array) apply_filters(
			'wp_agent_workflow_step_handlers',
			array_merge( $defaults, $step_handlers )
		);
	}

	/**
	 * Execute a workflow.
	 *
	 * @since 0.103.0
	 *
	 * @param WP_Agent_Workflow_Spec $spec
	 * @param array<mixed>                  $inputs Caller-supplied inputs. Required
	 *                                       inputs missing here cause an early
	 *                                       failure with a structured error.
	 * @param array<mixed>                  $options Runtime options:
	 *                                        - `run_id` (string, optional): caller-suggested run id.
	 *                                        - `continue_on_error` (bool): keep running after a failed step. Default false.
	 *                                        - `metadata` (array): forwarded to the run result.
	 *                                        - `evidence_refs` (array): neutral artifact/log references forwarded to the run result.
	 * @return WP_Agent_Workflow_Run_Result
	 */
	public function run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): WP_Agent_Workflow_Run_Result {
		$started_at    = time();
		$run_id        = self::string_value( $options['run_id'] ?? self::generate_run_id() );
		$metadata      = (array) ( $options['metadata'] ?? array() );
		$evidence_refs = (array) ( $options['evidence_refs'] ?? array() );
		$replay        = self::build_replay_metadata( $spec );

		// Build the initial RUNNING result and persist via recorder->start()
		// before doing anything else. Even if input validation fails on the
		// next line we want a `start → update(failed)` lifecycle so recorders
		// never see `start()` called with an already-terminal status.
		$result = new WP_Agent_Workflow_Run_Result(
			$run_id,
			$spec->get_id(),
			WP_Agent_Workflow_Run_Result::STATUS_RUNNING,
			$inputs,
			array(),
			array(),
			array(),
			$started_at,
			0,
			$metadata,
			$evidence_refs,
			$replay
		);

		if ( $this->recorder ) {
			$persisted = $this->recorder->start( $result );
			if ( is_wp_error( $persisted ) ) {
				// Recorder unavailable on entry — return a failed result without
				// running steps. The caller still gets the in-memory record so
				// observability hooks fire; the step pipeline does not run.
				return $result->with(
					array(
						'status'   => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
						'error'    => array(
							'code'    => 'recorder_start_failed',
							'message' => $persisted->get_error_message(),
						),
						'ended_at' => time(),
					)
				);
			}
			if ( '' !== $persisted ) {
				$result = $result->with( array( 'run_id' => $persisted ) );
			}
		}

		WP_Agent_Run_Control::start_run(
			self::RUN_CONTROL_STORE,
			$result->get_run_id(),
			array(
				'workflow_id' => $spec->get_id(),
				'metadata'    => $metadata,
			)
		);

		// Validate inputs against the spec's input declarations.
		$input_error = self::validate_inputs( $spec, $inputs );
		if ( null !== $input_error ) {
			$terminal = $result->with(
				array(
					'status'   => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
					'error'    => $input_error,
					'ended_at' => time(),
				)
			);
			if ( $this->recorder ) {
				$this->recorder->update( $terminal );
			}
			WP_Agent_Run_Control::finish_run( self::RUN_CONTROL_STORE, $result->get_run_id(), WP_Agent_Run_Control::STATUS_FAILED );
			return $terminal;
		}

		$context  = new WP_Agent_Workflow_Run_Context(
			array(
				'inputs'              => $inputs,
				'steps'               => array(),
				'vars'                => array(),
				'_workflow_run_id'    => $result->get_run_id(),
				'_workflow_store_key' => self::RUN_CONTROL_STORE,
			)
		);
		$executor = new WP_Agent_Workflow_Step_Executor( $this->step_handlers );

		$step_records      = array();
		$continue_on_error = ! empty( $options['continue_on_error'] );
		$failed            = false;
		$failure_error     = array();

		foreach ( $spec->get_steps() as $step ) {
			if ( self::is_cancel_requested( $result->get_run_id() ) ) {
				$result = self::cancelled_result( $result, $step_records );
				if ( $this->recorder ) {
					$this->recorder->update( $result );
				}
				return $result;
			}

			$record         = $executor->execute( $step, $context );
			$step_records[] = $record;

			if ( self::is_cancel_requested( $result->get_run_id() ) ) {
				$result = self::cancelled_result( $result, $step_records );
				if ( $this->recorder ) {
					$this->recorder->update( $result );
				}
				return $result;
			}

			if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED !== $record['status'] ) {
				$failed        = true;
				$failure_error = $record['error'];
				$result        = $result->with( array( 'steps' => $step_records ) );
				if ( $this->recorder ) {
					$this->recorder->update( $result );
				}
				if ( ! $continue_on_error ) {
					break;
				}
				continue;
			}

			$result = $result->with( array( 'steps' => $step_records ) );
			if ( $this->recorder ) {
				$this->recorder->update( $result );
			}
		}

		// Final aggregated output: every step's output keyed by id, plus a
		// convenience `last` shortcut that points at the last step's output
		// **only when that last step succeeded**. With `continue_on_error`
		// the last step in the list may be a failed one, in which case
		// `last` is intentionally absent — callers should reach for
		// `$result->get_output()['steps'][<id>]` when partial-failure
		// semantics matter.
		$final_output = array(
			'steps' => array(),
		);
		foreach ( $step_records as $rec ) {
			$final_output['steps'][ self::string_value( $rec['id'] ?? null ) ] = $rec['output'] ?? null;
		}
		$last = end( $step_records );
		if ( false !== $last && WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED === $last['status'] ) {
			$final_output['last'] = $last['output'];
		}

		$result = $result->with(
			array(
				'status'   => $failed ? WP_Agent_Workflow_Run_Result::STATUS_FAILED : WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
				'output'   => $final_output,
				'error'    => $failure_error,
				'ended_at' => time(),
			)
		);

		if ( $this->recorder ) {
			$this->recorder->update( $result );
		}
		WP_Agent_Run_Control::finish_run(
			self::RUN_CONTROL_STORE,
			$result->get_run_id(),
			$failed ? WP_Agent_Run_Control::STATUS_FAILED : WP_Agent_Run_Control::STATUS_SUCCEEDED
		);
		return $result;
	}

	/** @phpstan-impure */
	private static function is_cancel_requested( string $run_id ): bool {
		return WP_Agent_Run_Control::cancel_requested( self::RUN_CONTROL_STORE, $run_id );
	}

	/**
	 * @param array<mixed> $step_records Step records completed before cancellation was observed.
	 */
	private static function cancelled_result( WP_Agent_Workflow_Run_Result $result, array $step_records ): WP_Agent_Workflow_Run_Result {
		WP_Agent_Run_Control::finish_run( self::RUN_CONTROL_STORE, $result->get_run_id(), WP_Agent_Run_Control::STATUS_CANCELLED );

		return $result->with(
			array(
				'status'   => WP_Agent_Workflow_Run_Result::STATUS_CANCELLED,
				'steps'    => $step_records,
				'error'    => array(
					'code'    => 'cancel_requested',
					'message' => 'Workflow run cancellation was requested.',
				),
				'ended_at' => time(),
			)
		);
	}

	/**
	 * Validate inputs against the spec's declared input schemas.
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $inputs Caller-supplied inputs.
	 * @return array{code:string,message:string,data?:mixed}|null
	 */
	private static function validate_inputs( WP_Agent_Workflow_Spec $spec, array $inputs ): ?array {
		foreach ( $spec->get_inputs() as $name => $schema ) {
			$required = is_array( $schema ) && ! empty( $schema['required'] );
			$present  = array_key_exists( $name, $inputs );

			if ( $required && ! $present ) {
				return array(
					'code'    => 'missing_required_input',
					'message' => sprintf( 'workflow `%s` requires input `%s`', $spec->get_id(), $name ),
					'data'    => array( 'input' => $name ),
				);
			}
		}
		return null;
	}

	/**
	 * Build deterministic metadata for recorders and replay tooling.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_replay_metadata( WP_Agent_Workflow_Spec $spec ): array {
		$spec_snapshot = $spec->to_array();

		return array(
			'run_record_schema_version' => 1,
			'workflow_spec_version'     => $spec->get_version(),
			'workflow_spec_hash'        => hash( 'sha256', self::canonical_json( $spec_snapshot ) ),
			'workflow_spec_snapshot'    => $spec_snapshot,
		);
	}

	/**
	 * JSON encode arrays with sorted object keys so equivalent specs hash the same.
	 *
	 * @param mixed $value Value to encode.
	 */
	private static function canonical_json( $value ): string {
		$normalized = self::sort_recursive( $value );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $normalized ) : json_encode( $normalized );

		if ( false === $encoded ) {
			return '';
		}

		return $encoded;
	}

	/**
	 * @param mixed $value Value to sort.
	 * @return mixed
	 */
	private static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::sort_recursive( $child );
		}

		return $value;
	}

	/**
	 * Default `ability` step handler: invokes a registered Abilities API
	 * ability with the step's `args` (post-binding-resolution). Returns
	 * the ability's output as the step output.
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $step    Resolved step (bindings already expanded).
	 * @param array<mixed> $context Resolution context (unused here).
	 * @return array<mixed>|WP_Error
	 */
	public static function default_ability_handler( array $step, array $context ) {
		unset( $context );
		$ability_name = self::string_value( $step['ability'] ?? null );
		$args         = (array) ( $step['args'] ?? array() );
		$result       = WP_Agent_Ability_Dispatcher::dispatch( $ability_name, $args );
		if ( is_wp_error( $result ) && 'ability_not_found' === $result->get_error_code() ) {
			return new \WP_Error(
				'unknown_ability',
				sprintf( 'no ability registered as `%s`', $ability_name )
			);
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_array( $result ) ? $result : array( 'value' => $result );
	}

	/**
	 * Default `agent` step handler: calls the canonical `agents/chat`
	 * dispatcher (per agents-api#100) with the step's agent slug + message.
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $step    Resolved step (bindings already expanded).
	 * @param array<mixed> $context Resolution context (unused here).
	 * @return array<mixed>|WP_Error
	 */
	public static function default_agent_handler( array $step, array $context ) {
		unset( $context );
		$input  = array(
			'agent'      => self::string_value( $step['agent'] ?? null ),
			'message'    => self::string_value( $step['message'] ?? null ),
			'session_id' => $step['session_id'] ?? null,
		);
		$result = WP_Agent_Ability_Dispatcher::dispatch( 'agents/chat', $input );
		if ( is_wp_error( $result ) && 'ability_not_found' === $result->get_error_code() ) {
			return new \WP_Error(
				'agents_chat_missing',
				'agents/chat ability is not registered.'
			);
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_array( $result ) ? $result : array( 'reply' => self::string_value( $result ) );
	}

	/**
	 * Default `foreach` step handler. Iterates over a resolved array and runs
	 * an inline list of workflow steps with `${vars.<as>.*}` available.
	 *
	 * @since 0.107.0
	 *
	 * @param array<mixed> $step    Resolved outer foreach step.
	 * @param array<mixed> $context Resolution context.
	 * @return array<mixed>|WP_Error
	 */
	public static function default_foreach_handler( array $step, array $context ) {
		$items = $step['items'] ?? array();
		if ( ! is_array( $items ) ) {
			return new \WP_Error(
				'workflow_foreach_items_invalid',
				'foreach step `items` must resolve to an array.'
			);
		}

		$steps = $step['steps'] ?? array();
		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return new \WP_Error(
				'workflow_foreach_steps_invalid',
				'foreach step must include a non-empty nested `steps` list.'
			);
		}

		$as_value          = self::string_value( $step['as'] ?? null );
		$index_as_value    = self::string_value( $step['index_as'] ?? null );
		$as                = '' !== $as_value ? $as_value : 'item';
		$index_as          = '' !== $index_as_value ? $index_as_value : 'index';
		$continue_on_error = ! empty( $step['continue_on_error'] );
		$handlers          = is_array( $context['_workflow_step_handlers'] ?? null )
			? $context['_workflow_step_handlers']
			: self::default_step_handlers();
		/** @var array<string,mixed> $handlers */
		$executor   = new WP_Agent_Workflow_Step_Executor( $handlers );
		$iterations = array();

		foreach ( array_values( $items ) as $index => $item ) {
			if ( self::foreach_cancel_requested( $context ) ) {
				return new \WP_Error( 'cancel_requested', 'Workflow run cancellation was requested.' );
			}

			$iteration_context = ( new WP_Agent_Workflow_Run_Context( $context ) )->with_vars(
				array(
					$as       => $item,
					$index_as => $index,
				)
			);
			$step_outputs      = array();
			$last_output       = null;

			foreach ( $steps as $nested_step ) {
				if ( self::foreach_cancel_requested( $context ) ) {
					return new \WP_Error( 'cancel_requested', 'Workflow run cancellation was requested.' );
				}

				if ( ! is_array( $nested_step ) ) {
					return new \WP_Error(
						'workflow_foreach_step_invalid',
						sprintf( 'foreach nested step at index %d must be an array.', $index )
					);
				}

				$nested_id = self::string_value( $nested_step['id'] ?? null );
				$type      = self::string_value( $nested_step['type'] ?? null );
				$handler   = $handlers[ $type ] ?? null;
				if ( '' === $nested_id || ! is_callable( $handler ) ) {
					$error = new \WP_Error(
						'workflow_foreach_step_unhandled',
						sprintf( 'foreach nested step `%s` cannot be handled.', '' !== $nested_id ? $nested_id : (string) $index )
					);
					if ( ! $continue_on_error ) {
						return $error;
					}
					$step_outputs[ $nested_id ] = array(
						'error' => array(
							'code'    => $error->get_error_code(),
							'message' => $error->get_error_message(),
						),
					);
					continue;
				}

				$nested_record = $executor->execute( $nested_step, $iteration_context );

				if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED !== $nested_record['status'] ) {
					$error = is_array( $nested_record['error'] ?? null ) ? $nested_record['error'] : array();
					if ( ! $continue_on_error ) {
						return new \WP_Error(
							self::string_value( $error['code'] ?? 'workflow_foreach_step_failed' ),
							self::string_value( $error['message'] ?? 'foreach nested step failed.' ),
							$error['data'] ?? null
						);
					}
					$last_output = array(
						'error' => $error,
					);
				} else {
					$last_output = $nested_record['output'];
				}

				$step_outputs[ $nested_id ] = $last_output;
			}

			$iterations[] = array(
				'index' => $index,
				'item'  => $item,
				'steps' => $step_outputs,
				'last'  => $last_output,
			);
		}

		return array(
			'count'      => count( $iterations ),
			'iterations' => $iterations,
		);
	}

	/**
	 * Default `parallel` step handler — generic agent fanout.
	 *
	 * This is the substrate's fanout-orchestration primitive. It is NOT real
	 * concurrency: PHP ships no threads here and this runner is synchronous.
	 * What the handler owns is the reusable contract that two product plugins
	 * independently reinvented — declare a set of branches, give every branch
	 * the SAME shared immutable context, run them through the same step-handler
	 * dispatch the runner already uses, collect each branch output keyed by its
	 * role, then hand all sibling outputs to a designated aggregator branch that
	 * fuses them into the final result. Whether branches *physically* run in
	 * parallel (Action Scheduler, sandboxed subprocesses, loopback requests) is a
	 * consumer-provided executor concern layered on top of this contract; the
	 * substrate declares the dispatch/collect/aggregate flow, not the
	 * concurrency mechanism. The reusable, real part is the fanout
	 * orchestration, and that is all this claims.
	 *
	 * One declarative step expresses BOTH proven fanout shapes:
	 *
	 *   1. parallel-map — run the same nested `steps` across N resolved `items`
	 *      (the non-sequential sibling of `foreach`); collect each branch result.
	 *      Shape: { type:'parallel', items, steps, as?, index_as? }.
	 *
	 *   2. parallel-roles + aggregate — run a set of role-scoped `branches` in
	 *      parallel, each receiving a shared immutable `context`, then hand all
	 *      branch outputs to the branch flagged `can_write_final_bundle` (the
	 *      aggregator), which emits the fused final output.
	 *      Shape: { type:'parallel', context, branches:[ <branch contract> ] }.
	 *
	 * Branch / role contract (parallel-roles shape):
	 *
	 *   {
	 *     role:                    string,            // branch identifier
	 *     goal_focus:              string,            // what this branch is responsible for
	 *     shared_context_contract: string,            // how the branch must consume the shared context
	 *     expected_output:         { ref, shape },    // declared output ref + shape
	 *     required:                bool,              // a failing required branch fails the step
	 *     can_write_final_bundle:  bool,              // exactly one branch is the aggregator
	 *     steps:                   array              // nested steps the branch runs
	 *   }
	 *
	 * Every branch reads the shared context under `${vars.context.*}`; a
	 * branch CANNOT mutate it for siblings — each branch gets its own
	 * deep-copied snapshot. The aggregator additionally receives every
	 * sibling's collected output under `${vars.branch_outputs.*}` (keyed by
	 * role) and its own role focus under `${vars.role.*}`.
	 *
	 * Adaptive gate: before fanning out, the `wp_agent_workflow_should_fanout`
	 * filter is consulted ( default true ) so a consumer can decide WHETHER to
	 * fan out for a given step + context (e.g. a complexity score). The
	 * substrate embeds no heuristic of its own; when the gate returns false the
	 * step short-circuits to a skipped, non-fused result.
	 *
	 * @since 0.4.0
	 *
	 * @param array<mixed> $step    Resolved parallel step.
	 * @param array<mixed> $context Resolution context.
	 * @return array<mixed>|WP_Error
	 */
	public static function default_parallel_handler( array $step, array $context ) {
		$handlers = is_array( $context['_workflow_step_handlers'] ?? null )
			? $context['_workflow_step_handlers']
			: self::default_step_handlers();
		/** @var array<string,mixed> $handlers */

		// Adaptive gate: a consumer can decide whether to fan out at all.
		// Default true — the substrate ships no complexity heuristic.
		$should_fanout = (bool) apply_filters( 'wp_agent_workflow_should_fanout', true, $step, $context );
		if ( ! $should_fanout ) {
			return array(
				'fanned_out' => false,
				'reason'     => 'fanout_gate_declined',
			);
		}

		$has_branches = isset( $step['branches'] ) && is_array( $step['branches'] );
		$has_items    = array_key_exists( 'items', $step );

		if ( $has_branches ) {
			return self::run_parallel_roles( $step, $context, $handlers );
		}

		if ( $has_items ) {
			return self::run_parallel_map( $step, $context, $handlers );
		}

		return new \WP_Error(
			'workflow_parallel_shape_invalid',
			'parallel step must declare either `branches` (roles+aggregate) or `items` (map).'
		);
	}

	/**
	 * parallel-map shape: run the same nested `steps` across every resolved
	 * `item`, collecting each branch's outputs. Same scoped-vars contract as
	 * `foreach` (`${vars.<as>.*}`), but framed as independent branches rather
	 * than an ordered iteration.
	 *
	 * @since 0.4.0
	 *
	 * @param array<mixed>        $step     Resolved parallel step.
	 * @param array<mixed>        $context  Resolution context.
	 * @param array<string,mixed> $handlers Step-type handler map.
	 * @return array<mixed>|WP_Error
	 */
	private static function run_parallel_map( array $step, array $context, array $handlers ) {
		$items = $step['items'] ?? array();
		if ( ! is_array( $items ) ) {
			return new \WP_Error(
				'workflow_parallel_items_invalid',
				'parallel-map step `items` must resolve to an array.'
			);
		}

		$steps = $step['steps'] ?? array();
		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return new \WP_Error(
				'workflow_parallel_steps_invalid',
				'parallel-map step must include a non-empty nested `steps` list.'
			);
		}

		$as_value          = self::string_value( $step['as'] ?? null );
		$index_as_value    = self::string_value( $step['index_as'] ?? null );
		$as                = '' !== $as_value ? $as_value : 'item';
		$index_as          = '' !== $index_as_value ? $index_as_value : 'index';
		$continue_on_error = ! empty( $step['continue_on_error'] );
		$executor          = new WP_Agent_Workflow_Step_Executor( $handlers );
		$branches          = array();

		foreach ( array_values( $items ) as $index => $item ) {
			if ( self::foreach_cancel_requested( $context ) ) {
				return new \WP_Error( 'cancel_requested', 'Workflow run cancellation was requested.' );
			}

			$branch_context = self::branch_context( $context )->with_vars(
				array(
					$as       => $item,
					$index_as => $index,
				)
			);

			$branch_result = self::run_branch_steps( $steps, $branch_context, $executor, $handlers, $continue_on_error, (string) $index );
			if ( is_wp_error( $branch_result ) ) {
				return $branch_result;
			}

			$branches[] = array(
				'index'  => $index,
				'item'   => $item,
				'steps'  => $branch_result['steps'],
				'output' => $branch_result['last'],
			);
		}

		return array(
			'shape'    => 'map',
			'count'    => count( $branches ),
			'branches' => $branches,
		);
	}

	/**
	 * parallel-roles + aggregate shape: run each role-scoped branch against a
	 * shared immutable context, collect outputs keyed by role, then run the
	 * aggregator branch (`can_write_final_bundle` true) with sibling outputs
	 * available so it can fuse the final result.
	 *
	 * @since 0.4.0
	 *
	 * @param array<mixed>        $step     Resolved parallel step.
	 * @param array<mixed>        $context  Resolution context.
	 * @param array<string,mixed> $handlers Step-type handler map.
	 * @return array<mixed>|WP_Error
	 */
	private static function run_parallel_roles( array $step, array $context, array $handlers ) {
		$branch_specs = array();
		foreach ( (array) $step['branches'] as $branch_spec ) {
			if ( ! is_array( $branch_spec ) ) {
				return new \WP_Error(
					'workflow_parallel_branch_invalid',
					'parallel branch entries must be arrays.'
				);
			}
			$branch_specs[] = $branch_spec;
		}

		if ( empty( $branch_specs ) ) {
			return new \WP_Error(
				'workflow_parallel_branches_empty',
				'parallel-roles step must declare a non-empty `branches` list.'
			);
		}

		// Exactly one branch must be the aggregator.
		$aggregator_roles = array();
		foreach ( $branch_specs as $branch_spec ) {
			if ( ! empty( $branch_spec['can_write_final_bundle'] ) ) {
				$aggregator_roles[] = self::string_value( $branch_spec['role'] ?? '' );
			}
		}
		if ( 1 !== count( $aggregator_roles ) ) {
			return new \WP_Error(
				'workflow_parallel_aggregator_invalid',
				sprintf(
					'parallel-roles step must declare exactly one aggregator branch (`can_write_final_bundle` true); found %d.',
					count( $aggregator_roles )
				)
			);
		}

		// Shared immutable context: deep-copied per branch so a branch cannot
		// mutate it for its siblings or the aggregator. Arrays are copied by
		// value in PHP, so a fresh array per branch is a genuine snapshot.
		$shared_context = is_array( $step['context'] ?? null ) ? $step['context'] : array();
		$executor       = new WP_Agent_Workflow_Step_Executor( $handlers );

		$branch_outputs = array();
		$branch_records = array();
		$aggregator     = null;

		// First pass: every non-aggregator branch, each against its own
		// snapshot of the shared context.
		foreach ( $branch_specs as $branch_spec ) {
			if ( ! empty( $branch_spec['can_write_final_bundle'] ) ) {
				$aggregator = $branch_spec;
				continue;
			}

			$run = self::run_role_branch( $branch_spec, $shared_context, array(), $context, $executor, $handlers );
			if ( is_wp_error( $run ) ) {
				return $run;
			}

			$role                    = self::string_value( $branch_spec['role'] ?? '' );
			$branch_outputs[ $role ] = $run['output'];
			$branch_records[ $role ] = $run['record'];
		}

		// Aggregator pass: same shared context snapshot plus every sibling's
		// collected output under `${vars.branch_outputs.*}`.
		if ( null === $aggregator ) {
			return new \WP_Error(
				'workflow_parallel_aggregator_missing',
				'parallel-roles step is missing its aggregator branch.'
			);
		}

		$aggregator_run = self::run_role_branch( $aggregator, $shared_context, $branch_outputs, $context, $executor, $handlers );
		if ( is_wp_error( $aggregator_run ) ) {
			return $aggregator_run;
		}

		$aggregator_role                    = self::string_value( $aggregator['role'] ?? '' );
		$branch_outputs[ $aggregator_role ] = $aggregator_run['output'];
		$branch_records[ $aggregator_role ] = $aggregator_run['record'];

		return array(
			'shape'           => 'roles',
			'aggregator'      => $aggregator_role,
			'branch_outputs'  => $branch_outputs,
			'branch_records'  => $branch_records,
			'final'           => $aggregator_run['output'],
		);
	}

	/**
	 * Run a single role-scoped branch. Exposes the shared immutable context
	 * under `${vars.context.*}`, the branch's own role contract under
	 * `${vars.role.*}`, and (for the aggregator) sibling outputs under
	 * `${vars.branch_outputs.*}`. Returns the branch's collected step outputs
	 * and a normalized last output.
	 *
	 * @since 0.4.0
	 *
	 * @param array<mixed>        $branch_spec    Branch / role contract.
	 * @param array<mixed>        $shared_context Shared immutable context (snapshot).
	 * @param array<mixed>        $sibling_outputs Sibling branch outputs keyed by role (aggregator only).
	 * @param array<mixed>        $context        Resolution context.
	 * @param WP_Agent_Workflow_Step_Executor $executor Step executor.
	 * @param array<string,mixed> $handlers       Step-type handler map.
	 * @return array{output:mixed,record:array<mixed>}|WP_Error
	 */
	private static function run_role_branch( array $branch_spec, array $shared_context, array $sibling_outputs, array $context, WP_Agent_Workflow_Step_Executor $executor, array $handlers ) {
		$role = self::string_value( $branch_spec['role'] ?? '' );
		if ( '' === $role ) {
			return new \WP_Error(
				'workflow_parallel_branch_role_missing',
				'each parallel branch must declare a non-empty `role`.'
			);
		}

		$steps = $branch_spec['steps'] ?? array();
		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return new \WP_Error(
				'workflow_parallel_branch_steps_invalid',
				sprintf( 'parallel branch `%s` must include a non-empty nested `steps` list.', $role )
			);
		}

		$continue_on_error = ! empty( $branch_spec['continue_on_error'] );

		// Snapshot the shared context by value so branch step execution cannot
		// leak mutations to siblings. The role contract (minus its nested
		// steps) and sibling outputs round out the scoped vars.
		$role_contract = $branch_spec;
		unset( $role_contract['steps'] );

		$branch_vars = array(
			'context' => $shared_context,
			'role'    => $role_contract,
		);
		if ( ! empty( $sibling_outputs ) ) {
			$branch_vars['branch_outputs'] = $sibling_outputs;
		}

		$branch_context = self::branch_context( $context )->with_vars( $branch_vars );

		$branch_result = self::run_branch_steps( $steps, $branch_context, $executor, $handlers, $continue_on_error, $role );
		if ( is_wp_error( $branch_result ) ) {
			// A failing required branch fails the whole step; a non-required
			// branch surfaces its error as the branch output instead.
			if ( empty( $branch_spec['required'] ) ) {
				return array(
					'output' => array(
						'error' => array(
							'code'    => $branch_result->get_error_code(),
							'message' => $branch_result->get_error_message(),
						),
					),
					'record' => array(
						'role'   => $role,
						'steps'  => array(),
						'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
					),
				);
			}
			return $branch_result;
		}

		return array(
			'output' => $branch_result['last'],
			'record' => array(
				'role'   => $role,
				'steps'  => $branch_result['steps'],
				'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
			),
		);
	}

	/**
	 * Run an inline list of nested steps for one branch and collect their
	 * outputs. Shared by both fanout shapes. Returns the per-step outputs
	 * keyed by step id plus the last output, or a WP_Error.
	 *
	 * @since 0.4.0
	 *
	 * @param array<mixed>        $steps             Nested step list.
	 * @param WP_Agent_Workflow_Run_Context $branch_context Branch run context.
	 * @param WP_Agent_Workflow_Step_Executor $executor    Step executor.
	 * @param array<string,mixed> $handlers          Step-type handler map.
	 * @param bool                $continue_on_error Keep running after a nested failure.
	 * @param string              $branch_label      Branch identifier for error messages.
	 * @return array{steps:array<string,mixed>,last:mixed}|WP_Error
	 */
	private static function run_branch_steps( array $steps, WP_Agent_Workflow_Run_Context $branch_context, WP_Agent_Workflow_Step_Executor $executor, array $handlers, bool $continue_on_error, string $branch_label ) {
		$step_outputs = array();
		$last_output  = null;

		foreach ( $steps as $nested_step ) {
			if ( ! is_array( $nested_step ) ) {
				return new \WP_Error(
					'workflow_parallel_step_invalid',
					sprintf( 'parallel branch `%s` nested step must be an array.', $branch_label )
				);
			}

			$nested_id = self::string_value( $nested_step['id'] ?? null );
			$type      = self::string_value( $nested_step['type'] ?? null );
			$handler   = $handlers[ $type ] ?? null;
			if ( '' === $nested_id || ! is_callable( $handler ) ) {
				$error = new \WP_Error(
					'workflow_parallel_step_unhandled',
					sprintf( 'parallel branch `%s` nested step `%s` cannot be handled.', $branch_label, '' !== $nested_id ? $nested_id : '(unnamed)' )
				);
				if ( ! $continue_on_error ) {
					return $error;
				}
				$last_output                = array(
					'error' => array(
						'code'    => $error->get_error_code(),
						'message' => $error->get_error_message(),
					),
				);
				$step_outputs[ $nested_id ] = $last_output;
				continue;
			}

			$nested_record = $executor->execute( $nested_step, $branch_context );

			if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED !== $nested_record['status'] ) {
				$error = is_array( $nested_record['error'] ?? null ) ? $nested_record['error'] : array();
				if ( ! $continue_on_error ) {
					return new \WP_Error(
						self::string_value( $error['code'] ?? 'workflow_parallel_step_failed' ),
						self::string_value( $error['message'] ?? 'parallel branch nested step failed.' ),
						$error['data'] ?? null
					);
				}
				$last_output = array( 'error' => $error );
			} else {
				$last_output = $nested_record['output'];
			}

			$step_outputs[ $nested_id ] = $last_output;
		}

		return array(
			'steps' => $step_outputs,
			'last'  => $last_output,
		);
	}

	/**
	 * Build a fresh branch run context from the outer resolution context.
	 * Branch step outputs are isolated per branch (a fresh `steps` map) so
	 * sibling branches never see each other's `${steps.*}` bindings; the
	 * coherence channel between branches is the explicit shared context and,
	 * for the aggregator, `${vars.branch_outputs.*}`.
	 *
	 * @param array<mixed> $context Outer resolution context.
	 */
	private static function branch_context( array $context ): WP_Agent_Workflow_Run_Context {
		$context['steps'] = array();
		$context['vars']  = array();
		return new WP_Agent_Workflow_Run_Context( $context );
	}

	/**
	 * @param array<mixed> $context Resolution context.
	 * @phpstan-impure
	 */
	private static function foreach_cancel_requested( array $context ): bool {
		$run_id    = self::string_value( $context['_workflow_run_id'] ?? null );
		$store_key = self::string_value( $context['_workflow_store_key'] ?? null );
		return '' !== $run_id && '' !== $store_key && WP_Agent_Run_Control::cancel_requested( $store_key, $run_id );
	}

	/**
	 * Return the filtered default handler map for nested step execution.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_step_handlers(): array {
		/** @var array<string,mixed> $handlers */
		$handlers = (array) apply_filters(
			'wp_agent_workflow_step_handlers',
			array(
				'ability'  => array( __CLASS__, 'default_ability_handler' ),
				'agent'    => array( __CLASS__, 'default_agent_handler' ),
				'foreach'  => array( __CLASS__, 'default_foreach_handler' ),
				'parallel' => array( __CLASS__, 'default_parallel_handler' ),
			)
		);

		return $handlers;
	}

	/**
	 * Generate a run id when the caller didn't supply one. Prefers the
	 * WordPress UUID helper when available, falls back to a uniqid-based
	 * value otherwise.
	 *
	 * @since 0.103.0
	 */
	private static function generate_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return 'wf_' . uniqid( '', true );
	}

	/**
	 * Return a string only for values that can safely be represented as text.
	 *
	 * @param mixed $value Value to normalize.
	 */
	private static function string_value( $value ): string {
		if ( is_scalar( $value ) || $value instanceof \Stringable ) {
			return (string) $value;
		}

		return '';
	}
}
