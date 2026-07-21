<?php
/**
 * Execute Step Ability
 *
 * Executes a single step in a pipeline flow. Resolves step configuration,
 * runs the step class, evaluates success, and routes to the appropriate
 * outcome: next step, job completion, or failure.
 *
 * Backs the datamachine_execute_step action hook.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.30.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\FilesRepository\FileCleanup;
use DataMachine\Core\FilesRepository\FileRetrieval;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\StepExecutionResult;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Core\Steps\Step;
use DataMachine\Engine\StepNavigator;

defined( 'ABSPATH' ) || exit;

class ExecuteStepAbility {

	use EngineHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/execute-step ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/execute-step',
				array(
					'label'               => __( 'Execute Step', 'data-machine' ),
					'description'         => __( 'Execute a single pipeline step. Resolves config, runs the step, routes to next step or completion.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'job_id', 'flow_step_id' ),
						'properties' => array(
							'job_id'                => array(
								'type'        => 'integer',
								'description' => __( 'Job ID for the execution.', 'data-machine' ),
							),
							'flow_step_id'          => array(
								'type'        => 'string',
								'description' => __( 'Flow step ID to execute.', 'data-machine' ),
							),
							'operation_generation'  => array(
								'type'        => 'integer',
								'minimum'     => 0,
								'description' => __( 'Direct workflow execution generation.', 'data-machine' ),
							),
							'operation_claim_token' => array(
								'type'        => 'string',
								'description' => __( 'Direct workflow enqueue ownership token.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'          => array( 'type' => 'boolean' ),
							'step_success'     => array( 'type' => 'boolean' ),
							'outcome'          => array( 'type' => 'string' ),
							'stale_generation' => array( 'type' => 'boolean' ),
							'deferred'         => array( 'type' => 'boolean' ),
							'retryable'        => array( 'type' => 'boolean' ),
							'error'            => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute the execute-step ability.
	 *
	 * @param array $input Input with job_id and flow_step_id.
	 * @return array Result with step execution outcome.
	 */
	public function execute( array $input ): array {
		$job_id                = (int) ( $input['job_id'] ?? 0 );
		$flow_step_id          = (string) ( $input['flow_step_id'] ?? '' );
		$operation_generation  = max( 0, (int) ( $input['operation_generation'] ?? 0 ) );
		$operation_claim_token = (string) ( $input['operation_claim_token'] ?? '' );
		$job                   = $this->db_jobs->get_job( $job_id );

		if ( ! $job ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Job %d not found.', $job_id ),
			);
		}

		if ( $operation_generation > 0 ) {
			$admission = $this->operationGenerationAdmission( $job, $operation_generation, $operation_claim_token );
			if ( 'pending_commit' === $admission ) {
				return $this->deferOperationGeneration( $job_id, $flow_step_id, $operation_generation, $operation_claim_token );
			}
			if ( 'committed' !== $admission ) {
				return $this->staleOperationGeneration( $job_id, $operation_generation, 'is stale or was not durably committed' );
			}
		}

		$current_status = (string) ( $job['status'] ?? '' );
		if ( JobStatus::isStatusFinal( $current_status ) ) {
			return array(
				'success'        => false,
				'error'          => sprintf( 'Job %d has terminal status "%s"; step execution skipped.', $job_id, $current_status ),
				'terminal_state' => $current_status,
			);
		}

		// Transition job to 'processing' now that Action Scheduler is actually
		// executing it. For parent jobs this is a no-op (already processing via
		// RunFlowAbility). For batch child jobs this is the real transition from
		// 'pending' → 'processing', ensuring recover-stuck only catches jobs
		// that genuinely started but never finished.
		if ( ! $this->db_jobs->start_job( $job_id ) ) {
			$job_after_start      = $this->db_jobs->get_job( $job_id );
			$current_status       = is_array( $job_after_start ) ? (string) ( $job_after_start['status'] ?? '' ) : '';
			$terminal_after_start = JobStatus::isStatusFinal( $current_status );

			return array(
				'success'        => false,
				'error'          => $terminal_after_start ? sprintf( 'Job %d has terminal status "%s"; step execution skipped.', $job_id, $current_status ) : sprintf( 'Job %d could not be started from status "%s".', $job_id, $current_status ),
				'terminal_state' => $terminal_after_start ? $current_status : null,
			);
		}

		if ( $operation_generation > 0 ) {
			$job_before_side_effect = $this->db_jobs->get_job( $job_id );
			if ( ! is_array( $job_before_side_effect ) || 'committed' !== $this->operationGenerationAdmission( $job_before_side_effect, $operation_generation, $operation_claim_token ) ) {
				return $this->staleOperationGeneration( $job_id, $operation_generation, 'lost durable ownership before execution' );
			}
		}

		try {
			$engine_snapshot = datamachine_get_engine_data( $job_id );
			$engine          = new EngineData( $engine_snapshot, $job_id );

			$flow_step_config = $this->resolveFlowStepConfig( $engine, $flow_step_id, $job_id, $engine_snapshot );

			if ( ! isset( $flow_step_config['flow_id'] ) || empty( $flow_step_config['flow_id'] ) ) {
				do_action(
					'datamachine_fail_job',
					$job_id,
					'step_execution_failure',
					array(
						'flow_step_id' => $flow_step_id,
						'reason'       => 'missing_flow_id_in_step_config',
					)
				);
				return array(
					'success' => false,
					'error'   => 'Missing flow_id in step config.',
				);
			}

			$flow_id = $flow_step_config['flow_id'];

			/** @var array $context */
			$context     = datamachine_get_file_context( $flow_id );
			$retrieval   = new FileRetrieval();
			$dataPackets = $retrieval->retrieve_data_by_job_id( $job_id, $context );
			if ( empty( $dataPackets ) && 'direct' === $flow_id ) {
				$direct_step_data_packets = is_array( $engine_snapshot['direct_step_data_packets'][ $flow_step_id ] ?? null ) ? $engine_snapshot['direct_step_data_packets'][ $flow_step_id ] : array();
				if ( ! empty( $direct_step_data_packets ) ) {
					$dataPackets = $direct_step_data_packets;
				}
			}

			if ( ! isset( $flow_step_config['step_type'] ) || empty( $flow_step_config['step_type'] ) ) {
				do_action(
					'datamachine_fail_job',
					$job_id,
					'step_execution_failure',
					array(
						'flow_step_id' => $flow_step_id,
						'reason'       => 'missing_step_type_in_flow_step_config',
					)
				);
				return array(
					'success' => false,
					'error'   => 'Missing step_type in flow step config.',
				);
			}

			$step_type       = $flow_step_config['step_type'];
			$step_definition = $this->resolveStepDefinition( $step_type, $flow_step_id, $job_id );

			if ( ! $step_definition ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Step type "%s" not found in registry.', $step_type ),
				);
			}

			$step_class = $step_definition['class'] ?? '';
			$flow_step  = new $step_class();
			if ( ! $flow_step instanceof Step ) {
				throw new \RuntimeException( sprintf( 'Step class "%s" must extend DataMachine\\Core\\Steps\\Step.', $step_class ) );
			}

			$payload = array(
				'job_id'       => $job_id,
				'flow_step_id' => $flow_step_id,
				'data'         => $dataPackets,
				'engine'       => $engine,
			);

			$step_output = $flow_step->execute( $payload );
			if ( $operation_generation > 0 ) {
				$job_after_execution = $this->db_jobs->get_job( $job_id );
				if ( ! is_array( $job_after_execution ) || 'committed' !== $this->operationGenerationAdmission( $job_after_execution, $operation_generation, $operation_claim_token ) ) {
					return $this->staleOperationGeneration( $job_id, $operation_generation, 'was superseded during execution' );
				}
			}
			$execution_result = StepExecutionResult::fromStepOutput( $step_output, $step_type );
			$dataPackets      = $execution_result['packets'];
			$step_success     = (bool) $execution_result['success'];

			$payload['data'] = $dataPackets;
			$this->logStepExecutionResult( $execution_result, $job_id, $flow_step_id, $step_type );
			RunMetrics::recordStepResult(
				$job_id,
				$flow_step_id,
				array(
					'step_type'    => $step_type,
					'result'       => $execution_result['status'] ?? ( $step_success ? 'completed' : 'failed' ),
					'step_success' => $step_success,
					'packet_count' => $execution_result['packet_count'],
					'status'       => $execution_result['status'] ?? null,
					'reason'       => $execution_result['reason'] ?? null,
					'error'        => $execution_result['error'] ?? null,
					'step_result'  => $execution_result['step_result'] ?? array(),
				)
			);

			// Refresh engine data to capture changes made during step execution.
			$refreshed_engine_data = datamachine_get_engine_data( $job_id );
			$engine                = new EngineData( $refreshed_engine_data, $job_id );
			$status_override       = $engine->get( 'job_status' );

			do_action(
				'datamachine_log',
				'debug',
				'Engine: status_override check',
				array(
					'job_id'                 => $job_id,
					'status_override'        => $status_override,
					'has_override'           => ! empty( $status_override ),
					'engine_data_job_status' => $refreshed_engine_data['job_status'] ?? 'not_set',
				)
			);

			if ( $operation_generation > 0 ) {
				$job_before_routing = $this->db_jobs->get_job( $job_id );
				if ( ! is_array( $job_before_routing ) || 'committed' !== $this->operationGenerationAdmission( $job_before_routing, $operation_generation, $operation_claim_token ) ) {
					return $this->staleOperationGeneration( $job_id, $operation_generation, 'was superseded before routing' );
				}
			}

			$result = $this->routeAfterExecution(
				$job_id,
				$flow_step_id,
				$flow_id,
				$flow_step_config,
				$step_type,
				$step_class,
				$dataPackets,
				$payload,
				$step_success,
				$status_override,
				$execution_result
			);

			$recorded_status = $status_override ? $status_override : ( $result['outcome'] ?? null );

			RunMetrics::recordStepResult(
				$job_id,
				$flow_step_id,
				array(
					'step_type'    => $step_type,
					'result'       => $result['outcome'] ?? ( $step_success ? 'completed' : 'failed' ),
					'step_success' => $step_success,
					'packet_count' => $execution_result['packet_count'],
					'status'       => $recorded_status,
					'reason'       => $result['reason'] ?? ( $execution_result['reason'] ?? ( $result['error'] ?? null ) ),
					'error'        => $result['error'] ?? ( $execution_result['error'] ?? null ),
					'step_result'  => is_array( $execution_result['step_result'] ?? null ) ? $execution_result['step_result'] : array(),
				)
			);

			return $result;
		} catch ( \Throwable $e ) {
			if ( $operation_generation > 0 ) {
				$job_after_exception = $this->db_jobs->get_job( $job_id );
				if ( ! is_array( $job_after_exception ) || 'committed' !== $this->operationGenerationAdmission( $job_after_exception, $operation_generation, $operation_claim_token ) ) {
					return $this->staleOperationGeneration( $job_id, $operation_generation, 'threw after being superseded' );
				}
			}
			RunMetrics::recordStepResult(
				$job_id,
				$flow_step_id,
				array(
					'result'       => 'failed',
					'step_success' => false,
					'packet_count' => 0,
					'reason'       => 'throwable_exception_in_step_execution',
					'error'        => $e->getMessage(),
					'step_result'  => \DataMachine\Core\StepResult::fromExecutionResult(
						array(
							'status'      => 'failed',
							'packets'     => array(),
							'reason'      => 'throwable_exception_in_step_execution',
							'error'       => $e->getMessage(),
							'diagnostics' => array( 'flow_step_id' => $flow_step_id ),
						)
					),
				)
			);
			do_action(
				'datamachine_fail_job',
				$job_id,
				'step_execution_failure',
				array(
					'flow_step_id'      => $flow_step_id,
					'exception_message' => $e->getMessage(),
					'exception_trace'   => $e->getTraceAsString(),
					'reason'            => 'throwable_exception_in_step_execution',
				)
			);
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	private function operationGenerationAdmission( array $job, int $generation, string $token ): string {
		if ( $generation <= 0 ) {
			return 'committed';
		}
		if ( '' === $token || (int) ( $job['operation_generation'] ?? 0 ) !== $generation || ! hash_equals( $token, (string) ( $job['operation_claim_token'] ?? '' ) ) ) {
			return 'stale';
		}
		if ( 'enqueued' === ( $job['operation_state'] ?? '' ) && (int) ( $job['operation_action_id'] ?? 0 ) > 0 ) {
			return 'committed';
		}

		return 'enqueuing' === ( $job['operation_state'] ?? '' ) ? 'pending_commit' : 'stale';
	}

	private function deferOperationGeneration( int $job_id, string $flow_step_id, int $generation, string $token ): array {
		$action_id = function_exists( 'as_schedule_single_action' )
			? as_schedule_single_action(
				time() + 1,
				'datamachine_execute_step',
				array(
					'job_id'                => $job_id,
					'flow_step_id'          => $flow_step_id,
					'operation_generation'  => $generation,
					'operation_claim_token' => $token,
				),
				'data-machine'
			)
			: 0;

		return array(
			'success'   => false,
			'deferred'  => true,
			'retryable' => true,
			'error'     => $action_id > 0 ? 'Execution deferred until enqueue generation is durably committed.' : 'Execution generation is not durably committed and could not be deferred.',
		);
	}

	private function staleOperationGeneration( int $job_id, int $generation, string $reason ): array {
		return array(
			'success'          => false,
			'error'            => sprintf( 'Job %d execution generation %d %s.', $job_id, $generation, $reason ),
			'stale_generation' => true,
		);
	}

	/**
	 * Resolve flow step config, falling back to database lookup if missing from snapshot.
	 *
	 * @param EngineData $engine          Engine data instance.
	 * @param string     $flow_step_id    Flow step ID.
	 * @param int        $job_id          Job ID.
	 * @param array      $engine_snapshot Raw engine snapshot data.
	 * @return array Flow step config, or an empty array when not found.
	 */
	private function resolveFlowStepConfig( EngineData $engine, string $flow_step_id, int $job_id, array $engine_snapshot ): array {
		$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );

		if ( ! $flow_step_config ) {
			$flow_step_config = $this->db_flows->get_flow_step_config( $flow_step_id, $job_id, true );

			if ( $flow_step_config ) {
				$existing_flow_config                  = $engine_snapshot['flow_config'] ?? array();
				$existing_flow_config[ $flow_step_id ] = $flow_step_config;
				datamachine_merge_engine_data(
					$job_id,
					array(
						'flow_config' => $existing_flow_config,
					)
				);
			}
		}

		return $flow_step_config;
	}

	/**
	 * Resolve and validate step type definition from the abilities registry.
	 *
	 * @param string $step_type    Step type identifier.
	 * @param string $flow_step_id Flow step ID (for error context).
	 * @param int    $job_id       Job ID (for error context).
	 * @return array|null Step definition or null on failure.
	 */
	private function resolveStepDefinition( string $step_type, string $flow_step_id, int $job_id ): ?array {
		$step_type_abilities = new StepTypeAbilities();
		$step_definition     = $step_type_abilities->getStepType( $step_type );

		if ( ! $step_definition ) {
			do_action(
				'datamachine_fail_job',
				$job_id,
				'step_execution_failure',
				array(
					'flow_step_id' => $flow_step_id,
					'step_type'    => $step_type,
					'reason'       => 'step_type_not_found_in_registry',
				)
			);
			return null;
		}

		return $step_definition;
	}

	/**
	 * Evaluate whether the step execution was successful.
	 *
	 * @param array  $dataPackets  Returned data packets.
	 * @param int    $job_id       Job ID (for logging).
	 * @param string $flow_step_id Flow step ID (for logging).
	 * @return bool True if step succeeded.
	 */
	private function evaluateStepSuccess( array $dataPackets, int $job_id, string $flow_step_id, string $step_type = '' ): bool {
		$classification = StepExecutionResult::classify( $dataPackets, $step_type );
		$this->logStepExecutionResult( $classification, $job_id, $flow_step_id, $step_type );

		return (bool) $classification['success'];
	}

	/**
	 * Log non-successful step execution classification.
	 *
	 * @param array  $execution_result Normalized StepExecutionResult contract.
	 * @param int    $job_id           Job ID (for logging).
	 * @param string $flow_step_id     Flow step ID (for logging).
	 * @param string $step_type        Step type identifier.
	 */
	private function logStepExecutionResult( array $execution_result, int $job_id, string $flow_step_id, string $step_type = '' ): void {
		$success = (bool) ( $execution_result['success'] ?? false );

		if ( $success ) {
			return;
		}

		$status = $execution_result['status'] ?? 'failed';

		// completed_no_items is a success-family terminal status (an empty fetch
		// window, not a failure) — it already gets its own INFO log downstream
		// ("Step completed with no new items to process"). Warning here on top
		// of that INFO and the step's own info-level "no content" log would be
		// triple noise for one routine event. See #2873.
		if ( 'completed_no_items' === $status ) {
			return;
		}

		do_action(
			'datamachine_log',
			'warning',
			'Step execution did not produce a successful result',
			array(
				'job_id'       => $job_id,
				'flow_step_id' => $flow_step_id,
				'step_type'    => $step_type,
				'status'       => $status,
				'reason'       => $execution_result['reason'] ?? 'step_execution_failed',
				'packet_count' => $execution_result['packet_count'] ?? 0,
				'diagnostics'  => is_array( $execution_result['diagnostics'] ?? null ) ? $execution_result['diagnostics'] : array(),
			)
		);
	}

	/**
	 * Route execution after step completes.
	 *
	 * @param int    $job_id           Job ID.
	 * @param string $flow_step_id     Flow step ID.
	 * @param mixed  $flow_id          Flow ID.
	 * @param array  $flow_step_config Flow step configuration.
	 * @param string $step_type        Step type identifier.
	 * @param string $step_class       Step class name.
	 * @param array  $dataPackets      Returned data packets.
	 * @param array  $payload          Full step payload.
	 * @param bool   $step_success     Whether step succeeded.
	 * @param mixed  $status_override  Status override from engine data.
	 * @return array Result with outcome details.
	 */
	private function routeAfterExecution(
		int $job_id,
		string $flow_step_id,
		$flow_id,
		array $flow_step_config,
		string $step_type,
		string $step_class,
		array $dataPackets,
		array $payload,
		bool $step_success,
		$status_override,
		array $execution_result = array()
	): array {
		$pipeline_id = $flow_step_config['pipeline_id'] ?? null;

		// Waiting status: pipeline is parked at a webhook gate.
		if ( $status_override && JobStatus::isStatusWaiting( $status_override ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Pipeline parked in waiting state (webhook gate)',
				array(
					'job_id'       => $job_id,
					'pipeline_id'  => $pipeline_id,
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
				)
			);
			return array(
				'success'      => true,
				'step_success' => $step_success,
				'outcome'      => 'waiting',
			);
		}

		// Status override: complete with override status and clean up.
		if ( $status_override ) {
			$transition = $this->db_jobs->transition_job_status_result( $job_id, $status_override, true );
			if ( $transition['changed'] ) {
				$cleanup = new FileCleanup();
				$context = datamachine_get_file_context( $flow_id );
				$cleanup->cleanup_job_data_packets( $job_id, $context );
			}
			if ( JobStatus::isStatusSuccess( $status_override ) && ! JobStatus::isStatusSuccess( $transition['status'] ) ) {
				return array(
					'success'      => false,
					'step_success' => false,
					'outcome'      => 'claim_completion_failed',
					'reason'       => 'item_claim_completion_failed',
					'status'       => $transition['status'],
				);
			}

			do_action(
				'datamachine_log',
				'debug',
				'Engine: complete_job called with status_override',
				array(
					'job_id' => $job_id,
					'status' => $status_override,
				)
			);

			do_action(
				'datamachine_log',
				'info',
				'Pipeline execution completed with status override',
				array(
					'job_id'          => $job_id,
					'pipeline_id'     => $pipeline_id,
					'flow_id'         => $flow_id,
					'flow_step_id'    => $flow_step_id,
					'final_status'    => $status_override,
					'override_source' => 'engine_data',
				)
			);

			return array(
				'success'      => true,
				'step_success' => $step_success,
				'outcome'      => 'completed_override',
			);
		}

		// Success: advance to next step or complete.
		if ( $step_success ) {
			$navigator         = new StepNavigator();
			$next_flow_step_id = $navigator->get_next_flow_step_id( $flow_step_id, $payload );

			if ( $next_flow_step_id ) {
				$engine                = $payload['engine'] ?? null;
				$next_flow_step_config = $engine instanceof EngineData ? $engine->getFlowStepConfig( $next_flow_step_id ) : array();
				$transition_route      = self::resolveTransitionRoute( $flow_step_config, $next_flow_step_config, $dataPackets );

				if ( 'fail' === $transition_route['mode'] ) {
					$transition_failure_reason = $transition_route['reason'] ?? 'transition_route_failed';
					do_action(
						'datamachine_fail_job',
						$job_id,
						'step_execution_failure',
						array(
							'flow_step_id'      => $flow_step_id,
							'next_flow_step_id' => $next_flow_step_id,
							'class'             => $step_class,
							'reason'            => $transition_failure_reason,
						)
					);

					return array(
						'success'      => true,
						'step_success' => false,
						'outcome'      => 'failed',
						'reason'       => $transition_failure_reason,
						'error'        => $transition_failure_reason,
					);
				}

				$routed_packets = $transition_route['packets'];
				$packet_count   = count( $routed_packets );

				// Inline continuation: when a step produces 0-1 DataPackets,
				// schedule the next step directly on the same job instead of
				// creating child jobs. This eliminates recursive fan-out where
				// children spawn grandchildren (e.g., AI step → upsert step).
				//
				// Fan-out is only meaningful when a step produces MULTIPLE
				// packets that need parallel processing (e.g., fetch step
				// producing one packet per event). A single packet is just
				// the same job continuing to the next step.
				if ( 'inline' === $transition_route['mode'] || $packet_count <= 1 ) {
					$this->handleStepLifecycleInlineContinuation( $job_id, $flow_step_config, $routed_packets );

					do_action(
						'datamachine_schedule_next_step',
						$job_id,
						$next_flow_step_id,
						$routed_packets
					);

					return array(
						'success'      => true,
						'step_success' => true,
						'outcome'      => 'inline_continuation',
					);
				}

				// Express the packet fan-out as the generic Agents API
				// `parallel`-map step contract (Automattic/agents-api#389) and
				// dispatch through the single ParallelMapFanoutAdapter surface.
				// The adapter owns the entire decision: it applies the one
				// `wp_agent_workflow_should_fanout` gate and either fans the
				// routed packets into child jobs (shape:'map', backed by the
				// Action-Scheduler PipelineBatchScheduler) or, when the gate
				// declines, reports shape:'inline' so the packets continue on
				// this job. There is no second fan-out decision path here.
				$parallel_step = array(
					'id'    => $next_flow_step_id,
					'type'  => ParallelMapFanoutAdapter::STEP_TYPE,
					'items' => $routed_packets,
					'steps' => array( array( 'flow_step_id' => $next_flow_step_id ) ),
				);

				$engine_snapshot = datamachine_get_engine_data( $job_id );
				$adapter         = new ParallelMapFanoutAdapter();
				$parallel_result = $adapter->dispatch(
					$parallel_step,
					$job_id,
					$next_flow_step_id,
					$engine_snapshot
				);

				// Gate declined (shape:'inline'): collapse to inline
				// continuation — the routed packets continue on this job
				// instead of spawning children.
				if ( ParallelMapFanoutAdapter::SHAPE_INLINE === ( $parallel_result['shape'] ?? '' ) ) {
					$this->handleStepLifecycleInlineContinuation( $job_id, $flow_step_config, $routed_packets );

					do_action(
						'datamachine_schedule_next_step',
						$job_id,
						$next_flow_step_id,
						$routed_packets
					);

					return array(
						'success'      => true,
						'step_success' => true,
						'outcome'      => 'fanout_gated_inline',
						'parallel'     => $parallel_result,
					);
				}

				return array(
					'success'      => true,
					'step_success' => true,
					'outcome'      => 'batch_scheduled',
					'batch'        => $parallel_result['batch'] ?? array(),
					'parallel'     => $parallel_result,
				);
			}

			$transition = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
			if ( $transition['changed'] ) {
				$cleanup = new FileCleanup();
				$context = datamachine_get_file_context( $flow_id );
				$cleanup->cleanup_job_data_packets( $job_id, $context );
			}
			if ( ! JobStatus::isStatusSuccess( $transition['status'] ) ) {
				return array(
					'success'      => false,
					'step_success' => false,
					'outcome'      => 'claim_completion_failed',
					'reason'       => 'item_claim_completion_failed',
					'status'       => $transition['status'],
				);
			}

			do_action(
				'datamachine_log',
				'info',
				'Pipeline execution completed successfully',
				array(
					'job_id'             => $job_id,
					'pipeline_id'        => $pipeline_id,
					'flow_id'            => $flow_id,
					'flow_step_id'       => $flow_step_id,
					'final_packet_count' => count( $dataPackets ),
					'final_status'       => JobStatus::COMPLETED,
				)
			);

			return array(
				'success'      => true,
				'step_success' => true,
				'outcome'      => 'completed',
			);
		}

		$prior_status = $this->getPriorTerminalStatus( $job_id );
		if ( null !== $prior_status ) {
			do_action(
				'datamachine_log',
				'debug',
				'Step returned no data after job was already marked failed or rescheduled for retry',
				array(
					'job_id'       => $job_id,
					'pipeline_id'  => $pipeline_id,
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'step_class'   => $step_class,
					'step_type'    => $step_type,
					'job_status'   => $prior_status,
				)
			);

			return array(
				'success'      => true,
				'step_success' => false,
				'outcome'      => 'failed',
				'error'        => $prior_status,
			);
		}

		// completed_no_items is an execution status, not a packet-count guess.
		// Fetch/event_import legacy empty outputs classify this way, and explicit
		// result-shaped step returns can also choose it without emitting packets.
		if ( 'completed_no_items' === ( $execution_result['status'] ?? '' ) ) {
			$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED_NO_ITEMS );
			do_action(
				'datamachine_log',
				'info',
				'Step completed with no new items to process',
				array(
					'job_id'       => $job_id,
					'pipeline_id'  => $pipeline_id,
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'step_type'    => $step_type,
				)
			);
			return array(
				'success'      => true,
				'step_success' => false,
				'outcome'      => 'completed_no_items',
			);
		}

		// Non-fetch steps: empty data packet is an actual failure.
		// Some steps (notably AIStep) call datamachine_fail_job with a precise
		// reason and then return no packets. The defensive check here covers
		// two shapes:
		//   - The step's failure was finalized → status starts with `failed`.
		//   - The step's failure was caught by JobRetryPolicy, which parked the
		//     job back to `pending` and scheduled a retry via Action Scheduler.
		// In both cases the per-step failure has already been routed; firing
		// `datamachine_fail_job` again here would double-record the failure
		// and (when the second reason is non-retryable) trigger
		// `cleanup_job_data_packets` even though a retry is still pending,
		// orphaning the next attempt with no input data.
		do_action(
			'datamachine_log',
			'error',
			'Step execution failed - empty data packet',
			array(
				'job_id'       => $job_id,
				'pipeline_id'  => $pipeline_id,
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'step_class'   => $step_class,
				'step_type'    => $step_type,
			)
		);
		$empty_packet_reason = $this->getFailureReasonFromPackets( $dataPackets, $execution_result['reason'] ?? 'empty_data_packet_returned' );

		$failure_context = array(
			'flow_step_id' => $flow_step_id,
			'class'        => $step_class,
			'reason'       => $empty_packet_reason,
		);
		if ( ! empty( $execution_result['diagnostics'] ) && is_array( $execution_result['diagnostics'] ) ) {
			$failure_context['diagnostics'] = $execution_result['diagnostics'];
		}
		if ( ! empty( $execution_result['error'] ) ) {
			$failure_context['error_message'] = $execution_result['error'];
		}

		do_action(
			'datamachine_fail_job',
			$job_id,
			'step_execution_failure',
			$failure_context
		);

		return array(
			'success'      => true,
			'step_success' => false,
			'outcome'      => 'failed',
			'reason'       => $empty_packet_reason,
		);
	}

	/**
	 * Return a status marker when the step's prior failure is already routed.
	 *
	 * Some steps, notably AIStep, call datamachine_fail_job with a precise
	 * failure reason and then return no packets. Do not reclassify that empty
	 * packet list as a generic execution failure.
	 *
	 * Returns the persisted status string when:
	 *   - The job is already in a `failed` status (terminal failure routed).
	 *   - The job is in `pending` status with a future-dated retry recorded in
	 *     engine_data['retry']['next_retry_at'] — i.e. JobRetryPolicy already
	 *     accepted ownership of this failure and parked the job for retry.
	 *
	 * @param int $job_id Job ID.
	 * @return string|null Status marker, or null when no prior route exists.
	 */
	private function getPriorTerminalStatus( int $job_id ): ?string {
		if ( ! isset( $this->db_jobs ) ) {
			return null;
		}

		$job = $this->db_jobs->get_job( $job_id );
		if ( ! is_array( $job ) ) {
			return null;
		}

		$status = $job['status'] ?? '';
		if ( ! is_string( $status ) || '' === $status ) {
			return null;
		}

		if ( JobStatus::isStatusFailure( $status ) ) {
			return $status;
		}

		if ( JobStatus::PENDING === JobStatus::fromString( $status )->getBaseStatus() && ( $this->hasPendingRetry( $job_id ) || $this->hasPendingAIConcurrencyThrottle( $job_id ) ) ) {
			return $status;
		}

		return null;
	}

	/**
	 * Check whether the job has a future-dated retry recorded in engine_data.
	 *
	 * `JobRetryPolicy::recordRetry` writes `engine_data['retry']['next_retry_at']`
	 * as an ISO 8601 timestamp when scheduling a retry. Treat any non-empty value
	 * here as authoritative — the scheduler may not have fired yet, but the policy
	 * has already taken ownership of the failure path.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	private function hasPendingRetry( int $job_id ): bool {
		if ( ! function_exists( 'datamachine_get_engine_data' ) ) {
			return false;
		}

		$engine_data = datamachine_get_engine_data( $job_id );
		$retry       = is_array( $engine_data['retry'] ?? null ) ? $engine_data['retry'] : array();

		return ! empty( $retry['next_retry_at'] );
	}

	/**
	 * Check whether the job has a future AI concurrency defer recorded.
	 *
	 * AIStep parks above-limit work back to pending and reschedules the same
	 * step. Treat that as an already-routed outcome, not as an empty-packet
	 * failure, so throttling remains distinct from provider/model errors.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	private function hasPendingAIConcurrencyThrottle( int $job_id ): bool {
		if ( ! function_exists( 'datamachine_get_engine_data' ) ) {
			return false;
		}

		$engine_data = datamachine_get_engine_data( $job_id );
		$throttle    = is_array( $engine_data['ai_concurrency_throttle'] ?? null ) ? $engine_data['ai_concurrency_throttle'] : array();

		return ! empty( $throttle['next_retry_at'] );
	}

	/**
	 * Notify step lifecycle handlers that a step is continuing inline.
	 *
	 * @param int   $job_id           Job ID.
	 * @param array $flow_step_config Current flow step configuration.
	 * @param array $routed_packets   Packets routed to the next step.
	 */
	private function handleStepLifecycleInlineContinuation( int $job_id, array $flow_step_config, array $routed_packets ): void {
		do_action( 'datamachine_step_lifecycle_inline_continuation', $job_id, $flow_step_config, $routed_packets );
	}

	/**
	 * Resolve whether returned packets should continue inline, fan out, or fail.
	 *
	 * AI output headed into a handler-requiring step is a single logical
	 * conversation result. Keep matching handler completions together so
	 * PublishStep/UpsertStep can enforce their own multi-handler contracts.
	 *
	 * @param array $current_step_config Current flow step config.
	 * @param array $next_step_config    Next flow step config.
	 * @param array $dataPackets         Data packets returned from the current step.
	 * @return array{mode: string, packets: array, reason?: string}
	 */
	public static function resolveTransitionRoute( array $current_step_config, array $next_step_config, array $dataPackets ): array {
		$current_step_type = $current_step_config['step_type'] ?? '';
		$next_step_type    = $next_step_config['step_type'] ?? '';

		if ( 'ai' === $current_step_type && '' !== $next_step_type && FlowStepConfig::usesHandler( $next_step_config ) ) {
			$handler_packets = self::getHandlerPacketsForFanOut( $dataPackets );

			if ( empty( $handler_packets ) ) {
				return array(
					'mode'    => 'fail',
					'packets' => array(),
					'reason'  => 'handler_requiring_step_missing_handler_packets',
				);
			}

			return array(
				'mode'    => 'inline',
				'packets' => $handler_packets,
			);
		}

		return array(
			'mode'    => 'fanout',
			'packets' => self::filterPacketsForFanOut( $dataPackets ),
		);
	}

	/**
	 * Filter data packets to only those safe to fan out into child jobs.
	 *
	 * When the AI step produces multiple packets, the batch scheduler creates
	 * one child job per packet. Only 'ai_handler_complete' packets carry the
	 * handler result that downstream steps (UpsertStep, PublishStep) need via
	 * ToolResultFinder. Non-handler packets ('tool_result', 'ai_response')
	 * would create child jobs that fail with 'required_handler_tool_not_called'.
	 *
	 * The DataPacket structure stores the packet kind in the top-level 'type'
	 * key (set by DataPacket::__construct's third argument).
	 * 'metadata.source_type' carries the ORIGINAL input source_type (e.g.
	 * 'ticketmaster', 'web_scraper') — never 'ai_handler_complete'. The
	 * pre-#1096 implementation filtered on metadata.source_type, which was a
	 * silent no-op that let every packet fan out into doomed child jobs.
	 *
	 * After filtering to handler packets, duplicates are removed. When the
	 * AI conversation loop fails to terminate early (e.g. misconfigured
	 * handler_slugs), the AI may call the same handler tool multiple times
	 * across turns, producing duplicate ai_handler_complete packets. These
	 * would fan out into child jobs that all process the same data.
	 * Dedup keeps only the first packet per handler tool name.
	 * See: https://github.com/Extra-Chill/data-machine/issues/1108
	 *
	 * If filtering removes all packets, the originals are returned unchanged
	 * — the step may not require handlers, or the packets may use a different
	 * convention (backward compatibility).
	 *
	 * @param array $dataPackets Data packets returned from the step.
	 * @return array Packets safe to fan out.
	 */
	public static function filterPacketsForFanOut( array $dataPackets ): array {
		$handler_packets = self::getHandlerPacketsForFanOut( $dataPackets );

		if ( empty( $handler_packets ) ) {
			return $dataPackets;
		}

		return $handler_packets;
	}

	/**
	 * Return de-duplicated ai_handler_complete packets.
	 *
	 * @param array $dataPackets Data packets returned from the step.
	 * @return array Handler-complete packets only.
	 */
	private static function getHandlerPacketsForFanOut( array $dataPackets ): array {
		$handler_packets = array_values(
			array_filter(
				$dataPackets,
				static function ( $packet ) {
					return ( $packet['type'] ?? '' ) === 'ai_handler_complete';
				}
			)
		);

		if ( empty( $handler_packets ) ) {
			return array();
		}

		// Deduplicate handler packets by tool_name. When the AI calls
		// the same handler tool multiple times (e.g. upsert_event called
		// on consecutive turns because the conversation didn't terminate),
		// each call produces a separate ai_handler_complete packet with
		// the same tool_name but possibly varied parameters. Only the
		// first invocation per tool_name is kept — subsequent duplicates
		// would create child jobs processing identical data.
		$seen_tools = array();
		$deduped    = array();

		foreach ( $handler_packets as $packet ) {
			$tool_name = $packet['metadata']['tool_name'] ?? '';

			if ( '' === $tool_name ) {
				// No tool_name — keep unconditionally.
				$deduped[] = $packet;
				continue;
			}

			if ( isset( $seen_tools[ $tool_name ] ) ) {
				continue;
			}

			$seen_tools[ $tool_name ] = true;
			$deduped[]                = $packet;
		}

		return $deduped;
	}

	/**
	 * Extract failure reason from step packets.
	 *
	 * @param array  $dataPackets Data packets from step execution.
	 * @param string $default_value Default reason when none found.
	 * @return string
	 */
	private function getFailureReasonFromPackets( array $dataPackets, string $default_value ): string {
		foreach ( $dataPackets as $packet ) {
			$metadata = $packet['metadata'] ?? array();
			if ( empty( $metadata['failure_reason'] ) ) {
				continue;
			}

			$reason = $metadata['failure_reason'];
			if ( is_string( $reason ) && '' !== trim( $reason ) ) {
				return sanitize_key( str_replace( ' ', '_', trim( $reason ) ) );
			}
		}

		return $default_value;
	}
}
