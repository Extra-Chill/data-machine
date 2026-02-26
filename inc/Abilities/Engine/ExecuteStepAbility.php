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
use DataMachine\Engine\AI\AgentType;
use DataMachine\Engine\AI\AgentContext;
use DataMachine\Engine\StepNavigator;

defined( 'ABSPATH' ) || exit;

class ExecuteStepAbility {

	use EngineHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

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
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'job_id', 'flow_step_id' ),
						'properties' => array(
							'job_id'       => array(
								'type'        => 'integer',
								'description' => __( 'Job ID for the execution.', 'data-machine' ),
							),
							'flow_step_id' => array(
								'type'        => 'string',
								'description' => __( 'Flow step ID to execute.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'step_success' => array( 'type' => 'boolean' ),
							'outcome'      => array( 'type' => 'string' ),
							'error'        => array( 'type' => 'string' ),
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute the execute-step ability.
	 *
	 * @param array $input Input with job_id and flow_step_id.
	 * @return array Result with step execution outcome.
	 */
	public function execute( array $input ): array {
		AgentContext::set( AgentType::PIPELINE );

		$job_id       = (int) ( $input['job_id'] ?? 0 );
		$flow_step_id = (string) ( $input['flow_step_id'] ?? '' );

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

			$payload = array(
				'job_id'       => $job_id,
				'flow_step_id' => $flow_step_id,
				'data'         => $dataPackets,
				'engine'       => $engine,
			);

			$dataPackets = $flow_step->execute( $payload );

			if ( ! is_array( $dataPackets ) ) {
				do_action(
					'datamachine_fail_job',
					$job_id,
					'step_execution_failure',
					array(
						'flow_step_id' => $flow_step_id,
						'class'        => $step_class,
						'reason'       => 'non_array_payload_returned',
					)
				);
				return array(
					'success' => false,
					'error'   => 'Step returned non-array payload.',
				);
			}

			$payload['data'] = $dataPackets;
			$step_success    = $this->evaluateStepSuccess( $dataPackets, $job_id, $flow_step_id );

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

			return $this->routeAfterExecution(
				$job_id,
				$flow_step_id,
				$flow_id,
				$flow_step_config,
				$step_type,
				$step_class,
				$dataPackets,
				$payload,
				$step_success,
				$status_override
			);
		} catch ( \Throwable $e ) {
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

	/**
	 * Resolve flow step config, falling back to database lookup if missing from snapshot.
	 *
	 * @param EngineData $engine          Engine data instance.
	 * @param string     $flow_step_id    Flow step ID.
	 * @param int        $job_id          Job ID.
	 * @param array      $engine_snapshot Raw engine snapshot data.
	 * @return array|null Flow step config or null.
	 */
	private function resolveFlowStepConfig( EngineData $engine, string $flow_step_id, int $job_id, array $engine_snapshot ): ?array {
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
	private function evaluateStepSuccess( array $dataPackets, int $job_id, string $flow_step_id ): bool {
		$step_success = ! empty( $dataPackets );

		if ( $step_success ) {
			foreach ( $dataPackets as $packet ) {
				$metadata = $packet['metadata'] ?? array();
				if ( isset( $metadata['success'] ) && false === $metadata['success'] ) {
					$step_success = false;
					do_action(
						'datamachine_log',
						'warning',
						'Step returned failure packet',
						array(
							'job_id'        => $job_id,
							'flow_step_id'  => $flow_step_id,
							'packet_type'   => $packet['type'] ?? 'unknown',
							'error_message' => $packet['data']['body'] ?? 'No error message',
						)
					);
					break;
				}
			}
		}

		return $step_success;
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
		$status_override
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
			$this->db_jobs->complete_job( $job_id, $status_override );

			do_action(
				'datamachine_log',
				'debug',
				'Engine: complete_job called with status_override',
				array(
					'job_id' => $job_id,
					'status' => $status_override,
				)
			);

			$cleanup = new FileCleanup();
			$context = datamachine_get_file_context( $flow_id );
			$cleanup->cleanup_job_data_packets( $job_id, $context );

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
				do_action( 'datamachine_schedule_next_step', $job_id, $next_flow_step_id, $dataPackets );
				return array(
					'success'      => true,
					'step_success' => true,
					'outcome'      => 'next_step_scheduled',
				);
			}

			$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
			$cleanup = new FileCleanup();
			$context = datamachine_get_file_context( $flow_id );
			$cleanup->cleanup_job_data_packets( $job_id, $context );

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

		// Failure: check for "no new items" vs actual failure.
		$is_fetch_step = ( 'fetch' === $step_type );
		$has_history   = $this->db_processed_items->has_processed_items( $flow_step_id );

		if ( $is_fetch_step && $has_history ) {
			$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED_NO_ITEMS );
			do_action(
				'datamachine_log',
				'info',
				'Flow completed with no new items to process',
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

		// Actual failure.
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
				'has_history'  => $has_history,
			)
		);
		do_action(
			'datamachine_fail_job',
			$job_id,
			'step_execution_failure',
			array(
				'flow_step_id' => $flow_step_id,
				'class'        => $step_class,
				'reason'       => 'empty_data_packet_returned',
			)
		);

		return array(
			'success'      => true,
			'step_success' => false,
			'outcome'      => 'failed',
		);
	}
}
