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
 *   - Branching, parallelism, nested workflows. Step-handler map is the
 *     extension point for those — a consumer can register a `branch`
 *     handler that runs a sub-list, or a `workflow` handler that calls
 *     this runner recursively.
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
			'ability' => array( __CLASS__, 'default_ability_handler' ),
			'agent'   => array( __CLASS__, 'default_agent_handler' ),
			'foreach' => array( __CLASS__, 'default_foreach_handler' ),
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
				'ability' => array( __CLASS__, 'default_ability_handler' ),
				'agent'   => array( __CLASS__, 'default_agent_handler' ),
				'foreach' => array( __CLASS__, 'default_foreach_handler' ),
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
