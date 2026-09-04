<?php
/**
 * Workflow runner — executes a {@see WP_Agent_Workflow_Spec} step-by-step.
 *
 * The runner is intentionally narrow:
 *
 *   1. Validate inputs against the spec's input schema (presence + type).
 *   2. Walk steps in order. For each step:
 *        a. Resolve `${...}` bindings against `inputs` + earlier step outputs.
 *        b. Dispatch to a step-type handler (`ability`, `agent`, `foreach`,
 *           and `parallel` ship by default; consumers register more via the
 *           handler map).
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
 *   - Unconditional concurrency. The default `parallel` handler owns fanout
 *     orchestration (declare N branches, propagate a shared immutable context
 *     to each, collect every branch output, and optionally run one aggregator
 *     branch over the collected outputs). Real concurrent branch execution
 *     happens when `wp_agent_workflow_step_executor` returns a branch executor.
 *     Agents API ships an Action Scheduler executor that is selected when
 *     `as_enqueue_async_action()` exists; without that or a caller-supplied
 *     executor, `parallel` falls back to synchronous in-process execution.
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
	 *                                        - `artifacts` (array): artifact descriptors forwarded to the run result.
	 *                                        - `logs` (array): log entries forwarded to the run result.
	 * @return WP_Agent_Workflow_Run_Result
	 */
	public function run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): WP_Agent_Workflow_Run_Result {
		$started_at    = time();
		$run_id        = self::string_value( $options['run_id'] ?? self::generate_run_id() );
		$metadata      = (array) ( $options['metadata'] ?? array() );
		$evidence_refs = (array) ( $options['evidence_refs'] ?? array() );
		$artifacts     = (array) ( $options['artifacts'] ?? array() );
		$logs          = (array) ( $options['logs'] ?? array() );
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
			$replay,
			$artifacts,
			$logs
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

		return $this->run_step_loop( $spec, $context, $result, array(), 0, ! empty( $options['continue_on_error'] ) );
	}

	/**
	 * Resume a suspended run from its persisted suspension frame.
	 *
	 * Reloads the run via the recorder, rebuilds the run context from the
	 * frame's `context_snapshot`, restores the already-executed step records,
	 * and continues the step loop from `step_index` (the suspended step's
	 * final output must already be spliced into its record by the caller —
	 * see {@see agents_reconcile_workflow_branch()}). The `_suspension`
	 * metadata is cleared so the resumed run is not treated as still parked.
	 *
	 * @since 0.5.0
	 *
	 * @param string       $run_id  The suspended run's id.
	 * @param array<mixed> $options Runtime options. Recognized: `continue_on_error`.
	 * @return WP_Agent_Workflow_Run_Result
	 */
	public function resume( string $run_id, array $options = array() ): WP_Agent_Workflow_Run_Result {
		if ( null === $this->recorder ) {
			return self::resume_error_result( $run_id, 'workflow_resume_no_recorder', 'A recorder is required to resume a suspended run.' );
		}

		$result = $this->recorder->find( $run_id );
		if ( null === $result ) {
			return self::resume_error_result( $run_id, 'workflow_resume_run_not_found', sprintf( 'No suspended run was found for run_id `%s`.', $run_id ) );
		}
		if ( ! $result->is_suspended() ) {
			// Idempotency guard: an already-resumed (or never-suspended) run is
			// returned as-is so a duplicate resume is a harmless no-op.
			return $result;
		}

		$suspension = $result->get_suspension();
		$spec       = self::spec_from_result( $result );
		if ( null === $spec ) {
			return self::resume_error_result( $run_id, 'workflow_resume_spec_unavailable', 'The suspended run has no replayable spec snapshot to resume from.' );
		}

		$snapshot = is_array( $suspension['context_snapshot'] ?? null ) ? $suspension['context_snapshot'] : array();
		$context  = new WP_Agent_Workflow_Run_Context(
			array(
				'inputs'              => is_array( $snapshot['inputs'] ?? null ) ? $snapshot['inputs'] : $result->get_inputs(),
				'steps'               => is_array( $snapshot['steps'] ?? null ) ? $snapshot['steps'] : array(),
				'vars'                => is_array( $snapshot['vars'] ?? null ) ? $snapshot['vars'] : array(),
				'_workflow_run_id'    => $run_id,
				'_workflow_store_key' => self::RUN_CONTROL_STORE,
			)
		);

		/** @var array<int,array<string,mixed>> $step_records */
		$step_records = array();
		foreach ( $result->get_steps() as $record ) {
			if ( is_array( $record ) ) {
				$step_records[] = self::string_keyed_array( $record );
			}
		}

		$resume_index      = self::int_value( $suspension['step_index'] ?? 0 ) + 1;
		$continue_on_error = ! empty( $options['continue_on_error'] );

		// Clear the suspension frame so the resumed run is no longer parked and
		// the table-free per-run row (metadata._suspension) is not carried
		// forward once the run reaches a terminal outcome.
		$metadata = $result->get_metadata();
		unset( $metadata['_suspension'] );
		$result = $result->with(
			array(
				'status'   => WP_Agent_Workflow_Run_Result::STATUS_RUNNING,
				'metadata' => $metadata,
				'steps'    => $step_records,
			)
		);

		return $this->run_step_loop( $spec, $context, $result, $step_records, $resume_index, $continue_on_error );
	}

	/**
	 * The shared, indexed, resumable step loop. Both `run()` (start at 0) and
	 * `resume()` (start at `step_index + 1`) drive it. Walks the spec's steps
	 * from `$start_index`, records each outcome, gates on the `'pending'`
	 * suspend marker BEFORE the failure gate, and finalizes the run.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Agent_Workflow_Spec        $spec              Workflow spec.
	 * @param WP_Agent_Workflow_Run_Context $context           Run context (already rebuilt on resume).
	 * @param WP_Agent_Workflow_Run_Result  $result            Current run result.
	 * @param array<int,array<string,mixed>> $step_records     Step records completed so far.
	 * @param int                           $start_index       Index in $spec->get_steps() to begin at.
	 * @param bool                          $continue_on_error Keep running after a failed step.
	 * @return WP_Agent_Workflow_Run_Result
	 */
	private function run_step_loop( WP_Agent_Workflow_Spec $spec, WP_Agent_Workflow_Run_Context $context, WP_Agent_Workflow_Run_Result $result, array $step_records, int $start_index, bool $continue_on_error ): WP_Agent_Workflow_Run_Result {
		$executor = new WP_Agent_Workflow_Step_Executor( $this->step_handlers );
		$steps    = array_values( $spec->get_steps() );

		$failed        = false;
		$failure_error = array();
		foreach ( $step_records as $existing ) {
			if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED !== ( $existing['status'] ?? '' ) && 'pending' !== ( $existing['status'] ?? '' ) ) {
				$failed        = true;
				$failure_error = is_array( $existing['error'] ?? null ) ? $existing['error'] : array();
			}
		}

		$step_count = count( $steps );
		for ( $step_index = $start_index; $step_index < $step_count; $step_index++ ) {
			$step = $steps[ $step_index ];

			if ( self::is_cancel_requested( $result->get_run_id() ) ) {
				$result = self::cancelled_result( $result, $step_records );
				if ( $this->recorder ) {
					$this->recorder->update( $result );
				}
				return $result;
			}

			$record         = self::string_keyed_array( $executor->execute( $step, $context ) );
			$step_records[] = $record;

			if ( self::is_cancel_requested( $result->get_run_id() ) ) {
				$result = self::cancelled_result( $result, $step_records );
				if ( $this->recorder ) {
					$this->recorder->update( $result );
				}
				return $result;
			}

			// Pending / suspend gate — BEFORE the failure gate. The step asked
			// to park the run mid-flight (its handler returned a `_suspend`
			// directive; the step executor recorded status `'pending'`). Persist
			// a table-free suspension frame in metadata._suspension and return
			// SUSPENDED. The addressable run stays live (RUNNING at the
			// run-control layer) — it is finished only after resume reaches a
			// terminal step-loop exit.
			if ( 'pending' === ( $record['status'] ?? '' ) ) {
				$suspension = self::build_suspension_frame( $step_index, $step, $record, $context );
				$result     = $result->with(
					array(
						'status'   => WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED,
						'steps'    => $step_records,
						'metadata' => $result->get_metadata() + array( '_suspension' => $suspension ),
					)
				);
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

		/**
		 * Fires when a workflow run reaches a terminal state through the step loop.
		 *
		 * This is the single funnel for a run finishing — whether it ran straight
		 * through in one request ({@see run()}) or completed via an async resume
		 * after its parallel branches reconciled ({@see resume()}). It lets an
		 * async consumer react to completion WITHOUT block-polling the recorder:
		 * a consumer that dispatched an async fanout can return immediately from
		 * its own worker and do its finalization here instead, so it never holds a
		 * queue claim while waiting on the very branches it dispatched.
		 *
		 * @since 0.5.0
		 *
		 * @param WP_Agent_Workflow_Run_Result $result The terminal run result.
		 * @param string                       $run_id The run id.
		 */
		do_action( 'wp_agent_workflow_run_completed', $result, $result->get_run_id() );

		return $result;
	}

	/**
	 * Build the table-free suspension frame persisted under
	 * `metadata._suspension`. Everything a later reconcile + resume needs to
	 * continue deterministically: the position to resume AT, the dispatched
	 * branch handles, the aggregate plan, and a frozen context snapshot. The
	 * already-executed step records live in the run result's `steps[]` (via
	 * the recorder), so they are not duplicated here.
	 *
	 * @since 0.5.0
	 *
	 * @param int                           $step_index The suspended step's index.
	 * @param array<mixed>                  $step       The suspended step (resolved).
	 * @param array<mixed>                  $record     The `'pending'` step record (carries `suspend`).
	 * @param WP_Agent_Workflow_Run_Context $context    The run context at suspend time.
	 * @return array<string,mixed>
	 */
	private static function build_suspension_frame( int $step_index, array $step, array $record, WP_Agent_Workflow_Run_Context $context ): array {
		$directive = is_array( $record['suspend'] ?? null ) ? $record['suspend'] : array();
		$snapshot  = $context->to_array();

		return array(
			'step_index'       => $step_index,
			'step_id'          => self::string_value( $record['id'] ?? ( $step['id'] ?? '' ) ),
			'executor_id'      => self::string_value( $directive['executor'] ?? '' ),
			'reason'           => self::string_value( $directive['reason'] ?? '' ),
			'handles'          => is_array( $directive['handles'] ?? null ) ? array_values( $directive['handles'] ) : array(),
			'aggregate'        => is_array( $directive['aggregate'] ?? null ) ? $directive['aggregate'] : array(),
			'context_snapshot' => array(
				'inputs' => is_array( $snapshot['inputs'] ?? null ) ? $snapshot['inputs'] : array(),
				'steps'  => is_array( $snapshot['steps'] ?? null ) ? $snapshot['steps'] : array(),
				'vars'   => is_array( $snapshot['vars'] ?? null ) ? $snapshot['vars'] : array(),
			),
			'completed'        => array(),
		);
	}

	/**
	 * Rebuild the spec from a run result's replay snapshot so a resume can
	 * walk the exact steps the run started with.
	 *
	 * @since 0.5.0
	 */
	private static function spec_from_result( WP_Agent_Workflow_Run_Result $result ): ?WP_Agent_Workflow_Spec {
		$replay   = $result->get_replay_metadata();
		$snapshot = is_array( $replay['workflow_spec_snapshot'] ?? null ) ? $replay['workflow_spec_snapshot'] : array();
		if ( empty( $snapshot ) ) {
			return null;
		}

		$spec = WP_Agent_Workflow_Spec::from_array( $snapshot );
		return $spec instanceof WP_Agent_Workflow_Spec ? $spec : null;
	}

	/**
	 * Build a terminal failed result for an un-resumable run.
	 *
	 * @since 0.5.0
	 */
	private static function resume_error_result( string $run_id, string $code, string $message ): WP_Agent_Workflow_Run_Result {
		return new WP_Agent_Workflow_Run_Result(
			$run_id,
			'',
			WP_Agent_Workflow_Run_Result::STATUS_FAILED,
			array(),
			array(),
			array(),
			array(
				'code'    => $code,
				'message' => $message,
			),
			time(),
			time(),
			array()
		);
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
	 * This is the substrate's fanout-orchestration primitive: a scatter, collect,
	 * and return over role-scoped branches, with an OPTIONAL in-run aggregator
	 * branch. It is NOT real concurrency: PHP ships no threads here and this
	 * runner is synchronous. What the handler owns is the reusable contract —
	 * declare a set of branches, give every branch the SAME shared immutable
	 * context, run them through the same step-handler dispatch the runner already
	 * uses, and collect each branch output keyed by its role. A branch MAY be
	 * flagged `is_aggregator`, in which case it runs AFTER its siblings and
	 * receives their collected outputs, and its output becomes the step's final
	 * output; when no branch is an aggregator the step simply returns the
	 * collected branch outputs and any composition is the consumer's concern.
	 * Whether branches *physically* run in parallel (Action Scheduler, sandboxed
	 * subprocesses, loopback requests) is a consumer-provided executor concern
	 * layered on top of this contract; the substrate declares the
	 * scatter/collect/return flow (plus the optional aggregate pass), not the
	 * concurrency mechanism.
	 *
	 * One declarative step expresses BOTH fanout shapes:
	 *
	 *   1. parallel-map — run the same nested `steps` across N resolved `items`
	 *      (the non-sequential sibling of `foreach`); collect each branch result.
	 *      Shape: { type:'parallel', items, steps, as?, index_as? }.
	 *
	 *   2. parallel-roles — run a set of role-scoped `branches` in parallel, each
	 *      receiving a shared immutable `context`, and collect their outputs keyed
	 *      by role. If AT MOST ONE branch is flagged `is_aggregator`, that branch
	 *      runs after the siblings, receives `${vars.branch_outputs.*}`, and its
	 *      output becomes the step's final output. With zero aggregators the step
	 *      returns the collected branch outputs for the consumer to compose.
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
	 *     is_aggregator:           bool,              // OPTIONAL: at most one branch is the aggregator
	 *     steps:                   array              // nested steps the branch runs
	 *   }
	 *
	 * Every branch reads the shared context under `${vars.context.*}`; a
	 * branch CANNOT mutate it for siblings — each branch gets its own
	 * deep-copied snapshot. An aggregator branch additionally receives every
	 * sibling's collected output under `${vars.branch_outputs.*}` (keyed by
	 * role) and its own role focus under `${vars.role.*}`.
	 *
	 * Adaptive gate: before fanning out, the `wp_agent_workflow_should_fanout`
	 * filter is consulted ( default true ) so a consumer can decide WHETHER to
	 * fan out for a given step + context (e.g. a complexity score). The
	 * substrate embeds no heuristic of its own; when the gate returns false the
	 * step short-circuits to a skipped result.
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

		if ( ! $has_branches && ! $has_items ) {
			return new \WP_Error(
				'workflow_parallel_shape_invalid',
				'parallel step must declare either `branches` (roles) or `items` (map).'
			);
		}

		// Resolve the branch executor (the concurrency seam). Core supplies a
		// low-priority default that returns null unless Action Scheduler (or a
		// caller override) is present. When no executor is selected the handler
		// runs the in-process v0.5.0 loops — byte-for-byte today's behavior, no
		// suspend, no reconcile.
		$executor = apply_filters( 'wp_agent_workflow_step_executor', null, $step, $context );
		if ( ! $executor instanceof WP_Agent_Workflow_Branch_Executor ) {
			return $has_branches
				? self::run_parallel_roles( $step, $context, $handlers )
				: self::run_parallel_map( $step, $context, $handlers );
		}

		// An executor is selected: dispatch the branches for out-of-band
		// execution and return a `_suspend` directive so the runner parks the
		// run mid-flight and resumes it once the branches reconcile.
		return self::dispatch_parallel_async( $executor, $step, $context, $handlers, $has_branches );
	}

	/**
	 * Build branch descriptors for the selected executor, dispatch them, and
	 * return the `_suspend` directive the step executor recognizes. If the
	 * executor returns handles that are already all-complete (a synchronous
	 * executor), the run never suspends — collect + aggregate inline and return
	 * a terminal result, exactly like the sync loops.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Agent_Workflow_Branch_Executor $executor     Selected branch executor.
	 * @param array<mixed>                      $step         Resolved parallel step.
	 * @param array<mixed>                      $context      Resolution context.
	 * @param array<string,mixed>               $handlers     Step-type handler map.
	 * @param bool                              $has_branches Whether this is the roles shape.
	 * @return array<mixed>|\WP_Error
	 */
	private static function dispatch_parallel_async( WP_Agent_Workflow_Branch_Executor $executor, array $step, array $context, array $handlers, bool $has_branches ) {
		$plan = $has_branches
			? self::build_roles_dispatch_plan( $step )
			: self::build_map_dispatch_plan( $step );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// Stamp the run + step identity onto every branch descriptor so a
		// self-contained descriptor riding in an out-of-band payload (e.g. the AS
		// action payload) knows which run/step to reconcile against without
		// re-reading the spec. This is the durable-descriptor contract the
		// executor depends on (§2.4 / §3.1).
		$run_id  = self::string_value( $context['_workflow_run_id'] ?? '' );
		$step_id = self::string_value( $step['id'] ?? '' );
		foreach ( $plan['branches'] as $branch_index => $descriptor ) {
			$descriptor['run_id']              = $run_id;
			$descriptor['step_id']             = $step_id;
			$plan['branches'][ $branch_index ] = $descriptor;
		}

		// Pass the run/step identity alongside the shared context (in a reserved
		// envelope, not merged into it) so an executor's dispatch() can address
		// the run without the identity leaking into `${vars.context.*}`.
		$dispatch_context = array(
			'_workflow_run_id'  => $run_id,
			'_workflow_step_id' => $step_id,
			'shared_context'    => $plan['shared_context'],
		);

		$handles = $executor->dispatch( $plan['branches'], $dispatch_context );

		// An executor may FAIL the dispatch (e.g. the AS executor when an async
		// enqueue is rejected). A failed dispatch is a HARD step failure — the run
		// must NOT suspend against a branch set that was never fully enqueued, or it
		// would hang draining an empty queue. Surface the error so the step fails
		// fast with a descriptive message.
		if ( is_wp_error( $handles ) ) {
			return $handles;
		}

		// A synchronous executor may return already-complete handles; then the
		// run must NOT suspend — collect + aggregate inline and return terminal.
		if ( $executor->are_all_complete( $handles ) ) {
			$branch_results = $executor->collect( $handles );
			return self::aggregate_branch_results( $plan['aggregate'], $branch_results, $handlers );
		}

		return array(
			'_suspend' => array(
				'reason'    => 'parallel_branches_dispatched',
				'executor'  => $executor->id(),
				'handles'   => array_values( $handles ),
				'aggregate' => $plan['aggregate'],
			),
		);
	}

	/**
	 * Build the dispatch plan for the roles shape: one branch descriptor per
	 * sibling role, the collect plan (with its OPTIONAL aggregator branch), and
	 * the shared immutable context. Mirrors the sync `run_parallel_roles()`
	 * validation so the async path rejects the same malformed specs.
	 *
	 * @since 0.5.0
	 *
	 * @param array<mixed> $step Resolved parallel step.
	 * @return array{branches:array<int,array<string,mixed>>,shared_context:array<string,mixed>,aggregate:array<string,mixed>}|\WP_Error
	 */
	private static function build_roles_dispatch_plan( array $step ) {
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

		// At most one branch may be the aggregator. Zero aggregators is valid
		// (scatter-collect-return); one aggregator runs after the siblings over
		// their collected outputs.
		$aggregator       = null;
		$aggregator_roles = array();
		$sibling_branches = array();
		foreach ( $branch_specs as $branch_spec ) {
			if ( ! empty( $branch_spec['is_aggregator'] ) ) {
				$aggregator         = $branch_spec;
				$aggregator_roles[] = self::string_value( $branch_spec['role'] ?? '' );
				continue;
			}
			$sibling_branches[] = $branch_spec;
		}
		if ( count( $aggregator_roles ) > 1 ) {
			return new \WP_Error(
				'workflow_parallel_aggregator_invalid',
				sprintf(
					'parallel-roles step may declare at most one aggregator branch (`is_aggregator` true); found %d.',
					count( $aggregator_roles )
				)
			);
		}

		$shared_context = is_array( $step['context'] ?? null ) ? self::string_keyed_array( $step['context'] ) : array();

		/** @var array<int,array<string,mixed>> $descriptors */
		$descriptors = array();
		foreach ( $sibling_branches as $branch_spec ) {
			$role = self::string_value( $branch_spec['role'] ?? '' );
			if ( '' === $role ) {
				return new \WP_Error(
					'workflow_parallel_branch_role_missing',
					'each parallel branch must declare a non-empty `role`.'
				);
			}
			$descriptors[] = self::role_branch_descriptor( self::string_keyed_array( $branch_spec ), $shared_context );
		}

		return array(
			'branches'       => $descriptors,
			'shared_context' => $shared_context,
			'aggregate'      => array(
				'mode'            => 'roles',
				'aggregator_role' => null !== $aggregator ? self::string_value( $aggregator['role'] ?? '' ) : '',
				'aggregator_spec' => null !== $aggregator ? $aggregator : array(),
				'shared_context'  => $shared_context,
				'shape'           => 'roles',
			),
		);
	}

	/**
	 * Build the dispatch plan for the map shape: one branch descriptor per
	 * resolved item, no aggregator (reconcile just collects).
	 *
	 * @since 0.5.0
	 *
	 * @param array<mixed> $step Resolved parallel step.
	 * @return array{branches:array<int,array<string,mixed>>,shared_context:array<string,mixed>,aggregate:array<string,mixed>}|\WP_Error
	 */
	private static function build_map_dispatch_plan( array $step ) {
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

		$descriptors = array();
		foreach ( array_values( $items ) as $index => $item ) {
			$descriptors[] = array(
				'key'               => (string) $index,
				'index'            => $index,
				'item'             => $item,
				'steps'            => $steps,
				'branch_vars'      => array(
					$as       => $item,
					$index_as => $index,
				),
				'continue_on_error' => $continue_on_error,
			);
		}

		return array(
			'branches'       => $descriptors,
			'shared_context' => array(),
			'aggregate'      => array(
				'mode'  => 'map',
				'shape' => 'map',
			),
		);
	}

	/**
	 * Build a self-contained descriptor for one role-scoped branch. The
	 * descriptor is the payload an executor runs later; it carries the branch's
	 * nested steps, its scoped vars (shared context + role contract), and the
	 * required / continue-on-error policy.
	 *
	 * @since 0.5.0
	 *
	 * @param array<mixed>        $branch_spec    Branch / role contract.
	 * @param array<string,mixed> $shared_context Shared immutable context snapshot.
	 * @return array<string,mixed>
	 */
	private static function role_branch_descriptor( array $branch_spec, array $shared_context ): array {
		$role          = self::string_value( $branch_spec['role'] ?? '' );
		$role_contract = $branch_spec;
		unset( $role_contract['steps'] );

		return array(
			'key'               => $role,
			'role'             => $role,
			'required'         => ! empty( $branch_spec['required'] ),
			'steps'            => is_array( $branch_spec['steps'] ?? null ) ? $branch_spec['steps'] : array(),
			'branch_vars'      => array(
				'context' => $shared_context,
				'role'    => $role_contract,
			),
			'continue_on_error' => ! empty( $branch_spec['continue_on_error'] ),
		);
	}

	/**
	 * Collect the reconciled branch outputs once every branch is terminal,
	 * producing the parallel step's output in the SAME shape the sync loops
	 * return. Shared by the reconcile entry point
	 * ({@see agents_reconcile_workflow_branch()}) and the synchronous-executor
	 * inline path.
	 *
	 * For `roles`: collect each branch output keyed by role. When the plan names
	 * an OPTIONAL aggregator branch, that branch runs after the siblings (cheap
	 * synthesis, not N slow AI calls) with `${vars.branch_outputs.*}` populated
	 * from the reconciled sibling results, and its output becomes the step's
	 * `final`. With no aggregator the step just returns the collected
	 * `branch_outputs` for the consumer to compose. For `map`: collect into the
	 * `{ shape:'map', count, branches }` envelope (never an aggregator).
	 *
	 * @since 0.5.0
	 *
	 * @param array<string,mixed> $aggregate      Aggregate plan (§2.5).
	 * @param array<string,mixed> $branch_results Reconciled BranchResult[] keyed by role|index.
	 * @param array<string,mixed> $handlers       Step-type handler map.
	 * @return array<mixed>|\WP_Error
	 */
	public static function aggregate_branch_results( array $aggregate, array $branch_results, array $handlers ) {
		$mode = self::string_value( $aggregate['mode'] ?? '' );

		if ( 'map' === $mode ) {
			$branches = array();
			foreach ( $branch_results as $key => $result ) {
				$result     = is_array( $result ) ? $result : array();
				$branches[] = array(
					'index'  => is_numeric( $key ) ? (int) $key : $key,
					'item'   => $result['item'] ?? null,
					'steps'  => is_array( $result['steps'] ?? null ) ? $result['steps'] : array(),
					'output' => $result['output'] ?? null,
				);
			}
			return array(
				'shape'    => 'map',
				'count'    => count( $branches ),
				'branches' => $branches,
			);
		}

		// roles: collect sibling outputs keyed by role.
		$aggregator     = is_array( $aggregate['aggregator_spec'] ?? null ) ? self::string_keyed_array( $aggregate['aggregator_spec'] ) : array();
		$aggregator_key = self::string_value( $aggregate['aggregator_role'] ?? '' );

		$branch_outputs = array();
		foreach ( $branch_results as $key => $result ) {
			if ( '' !== $aggregator_key && (string) $key === $aggregator_key ) {
				continue;
			}
			$result                          = is_array( $result ) ? $result : array();
			$branch_outputs[ (string) $key ] = $result['output'] ?? null;
		}

		// No aggregator branch: scatter-collect-return. The consumer composes the
		// collected branch outputs downstream.
		if ( empty( $aggregator ) ) {
			return array(
				'shape'          => 'roles',
				'branch_outputs' => $branch_outputs,
			);
		}

		// Optional aggregator branch: run it over the collected sibling outputs;
		// its output becomes the step's final output.
		$shared_context = is_array( $aggregate['shared_context'] ?? null ) ? self::string_keyed_array( $aggregate['shared_context'] ) : array();
		$executor       = new WP_Agent_Workflow_Step_Executor( $handlers );
		$run            = self::run_role_branch( $aggregator, $shared_context, $branch_outputs, array(), $executor, $handlers );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$branch_outputs[ $aggregator_key ] = $run['output'];

		return array(
			'shape'          => 'roles',
			'aggregator'     => $aggregator_key,
			'branch_outputs' => $branch_outputs,
			'final'          => $run['output'],
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
	 * parallel-roles shape: run each role-scoped branch against a shared
	 * immutable context and collect outputs keyed by role. If at most one branch
	 * is flagged `is_aggregator`, run that branch AFTER the siblings with their
	 * collected outputs under `${vars.branch_outputs.*}` so it can synthesize the
	 * final result; with no aggregator, return the collected branch outputs for
	 * the consumer to compose.
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

		// At most one branch may be the aggregator (optional).
		$aggregator_roles = array();
		foreach ( $branch_specs as $branch_spec ) {
			if ( ! empty( $branch_spec['is_aggregator'] ) ) {
				$aggregator_roles[] = self::string_value( $branch_spec['role'] ?? '' );
			}
		}
		if ( count( $aggregator_roles ) > 1 ) {
			return new \WP_Error(
				'workflow_parallel_aggregator_invalid',
				sprintf(
					'parallel-roles step may declare at most one aggregator branch (`is_aggregator` true); found %d.',
					count( $aggregator_roles )
				)
			);
		}

		// Shared immutable context: deep-copied per branch so a branch cannot
		// mutate it for its siblings or an aggregator. Arrays are copied by
		// value in PHP, so a fresh array per branch is a genuine snapshot.
		$shared_context = is_array( $step['context'] ?? null ) ? $step['context'] : array();
		$executor       = new WP_Agent_Workflow_Step_Executor( $handlers );

		$branch_outputs = array();
		$branch_records = array();
		$aggregator     = null;

		// Scatter: every non-aggregator branch, each against its own snapshot of
		// the shared context.
		foreach ( $branch_specs as $branch_spec ) {
			if ( ! empty( $branch_spec['is_aggregator'] ) ) {
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

		// No aggregator branch: return the collected outputs for the consumer to
		// compose downstream.
		if ( null === $aggregator ) {
			return array(
				'shape'          => 'roles',
				'branch_outputs' => $branch_outputs,
				'branch_records' => $branch_records,
			);
		}

		// Optional aggregator pass: same shared context snapshot plus every
		// sibling's collected output under `${vars.branch_outputs.*}`.
		$aggregator_run = self::run_role_branch( $aggregator, $shared_context, $branch_outputs, $context, $executor, $handlers );
		if ( is_wp_error( $aggregator_run ) ) {
			return $aggregator_run;
		}

		$aggregator_role                    = self::string_value( $aggregator['role'] ?? '' );
		$branch_outputs[ $aggregator_role ] = $aggregator_run['output'];
		$branch_records[ $aggregator_role ] = $aggregator_run['record'];

		return array(
			'shape'          => 'roles',
			'aggregator'     => $aggregator_role,
			'branch_outputs' => $branch_outputs,
			'branch_records' => $branch_records,
			'final'          => $aggregator_run['output'],
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
	public static function run_branch_steps( array $steps, WP_Agent_Workflow_Run_Context $branch_context, WP_Agent_Workflow_Step_Executor $executor, array $handlers, bool $continue_on_error, string $branch_label ) {
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

	/**
	 * Coerce a value to an int for numeric-only frame fields.
	 *
	 * @param mixed $value Value to normalize.
	 */
	private static function int_value( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Keep only string keys, giving PHPStan a precise `array<string,mixed>`.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}
}
