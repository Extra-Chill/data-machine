<?php
/**
 * Four-action execution engine.
 *
 * Execution cycle: datamachine_run_flow_now → datamachine_execute_step → datamachine_schedule_next_step
 * Scheduling cycle: datamachine_run_flow_later → Action Scheduler → datamachine_run_flow_now
 *
 * @package DataMachine\Engine\Actions
 */

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\JobStatus;
use DataMachine\Engine\AI\AgentType;
use DataMachine\Engine\AI\AgentContext;

/**
 * Normalize stored configuration blobs into arrays.
 */
function datamachine_normalize_engine_config( $config ): array {
	if ( is_array( $config ) ) {
		return $config;
	}

	if ( is_string( $config ) ) {
		$decoded = json_decode( $config, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	return array();
}

/**
 * Get file context array from flow ID
 *
 * @param int|string $flow_id Flow ID or 'direct' for ephemeral workflows
 * @return array Context array with pipeline/flow metadata
 */
function datamachine_get_file_context( int|string $flow_id ): array {
	return \DataMachine\Api\Files::get_file_context( $flow_id );
}

/**
 * Register execution engine action hooks.
 *
 * Registers the four core execution actions:
 * - datamachine_run_flow_now
 * - datamachine_execute_step
 * - datamachine_schedule_next_step
 * - datamachine_run_flow_later
 */
function datamachine_register_execution_engine() {

	/**
	 * Execute flow immediately.
	 *
	 * Loads flow/pipeline configurations and schedules the first step for execution.
	 * Creates a job record if one is not provided (for scheduled/recurring flows).
	 *
	 * @param int $flow_id Flow ID to execute
	 * @param int|null $job_id Pre-created job ID (optional, for API-triggered executions)
	 * @return bool True on success, false on failure
	 */
	add_action(
		'datamachine_run_flow_now',
		function ( $flow_id, $job_id = null ) {
			// Set pipeline agent context for all logging during flow execution
			AgentContext::set( AgentType::PIPELINE );

			$db_flows = new \DataMachine\Core\Database\Flows\Flows();
			$db_jobs  = new \DataMachine\Core\Database\Jobs\Jobs();

			$flow = $db_flows->get_flow( $flow_id );
			if ( ! $flow ) {
				do_action( 'datamachine_log', 'error', 'Flow execution failed - flow not found', array( 'flow_id' => $flow_id ) );
				return false;
			}

			$pipeline_id = (int) $flow['pipeline_id'];

			// Use provided job_id or create new one (for scheduled/recurring flows)
			if ( ! $job_id ) {
				$job_id = $db_jobs->create_job(
					array(
						'pipeline_id' => $pipeline_id,
						'flow_id'     => $flow_id,
						'source'      => 'pipeline',
						'label'       => $flow['flow_name'] ?? null,
					)
				);
				if ( ! $job_id ) {
					do_action(
						'datamachine_log',
						'error',
						'Job creation failed - database insert failed',
						array(
							'flow_id'     => $flow_id,
							'pipeline_id' => $pipeline_id,
						)
					);
					return false;
				}
				do_action(
					'datamachine_log',
					'debug',
					'Job created',
					array(
						'job_id'      => $job_id,
						'flow_id'     => $flow_id,
						'pipeline_id' => $pipeline_id,
					)
				);
			}

			// Transition job from pending to processing
			$db_jobs->start_job( $job_id );

			$flow_config       = $flow['flow_config'] ?? array();
			$scheduling_config = $flow['scheduling_config'] ?? array();

			// Load pipeline config
			$db_pipelines    = new \DataMachine\Core\Database\Pipelines\Pipelines();
			$pipeline        = $db_pipelines->get_pipeline( $pipeline_id );
			$pipeline_config = $pipeline['pipeline_config'] ?? array();

			$flow_config     = datamachine_normalize_engine_config( $flow_config );
			$pipeline_config = datamachine_normalize_engine_config( $pipeline_config );

			$engine_snapshot = array(
				'job'             => array(
					'job_id'      => $job_id,
					'flow_id'     => $flow_id,
					'pipeline_id' => $pipeline_id,
					'created_at'  => current_time( 'mysql', true ),
				),
				'flow'            => array(
					'name'        => $flow['flow_name'] ?? '',
					'description' => $flow['flow_description'] ?? '',
					'scheduling'  => $scheduling_config,
				),
				'pipeline'        => array(
					'name'        => $pipeline['pipeline_name'] ?? '',
					'description' => $pipeline['pipeline_description'] ?? '',
				),
				'flow_config'     => $flow_config,
				'pipeline_config' => $pipeline_config,
			);

			datamachine_set_engine_data( $job_id, $engine_snapshot );

			$first_flow_step_id = null;
			foreach ( $flow_config as $flow_step_id => $config ) {
				if ( ( $config['execution_order'] ?? -1 ) === 0 ) {
					$first_flow_step_id = $flow_step_id;
					break;
				}
			}

			if ( ! $first_flow_step_id ) {
				do_action(
					'datamachine_log',
					'error',
					'Flow execution failed - no first step found',
					array(
						'job_id'      => $job_id,
						'pipeline_id' => $pipeline_id,
						'flow_id'     => $flow_id,
					)
				);
				return false;
			}

			do_action( 'datamachine_schedule_next_step', $job_id, $first_flow_step_id, array() );

			do_action(
				'datamachine_log',
				'info',
				'Flow execution started successfully',
				array(
					'flow_id'    => $flow_id,
					'job_id'     => $job_id,
					'first_step' => $first_flow_step_id,
				)
			);

			return true;
		},
		10,
		2
	);

	/**
	 * Execute a single step in a pipeline flow.
	 *
	 * @param int $job_id Job ID for the execution
	 * @param string $flow_step_id Flow step ID to execute
	 * @param array|null $data Input data for the step
	 * @return bool True on success, false on failure
	 */
	add_action(
		'datamachine_execute_step',
		function ( $job_id, string $flow_step_id, ?array $dataPackets = null ) {
			// Set pipeline agent context for all logging during step execution
			AgentContext::set( AgentType::PIPELINE );

			$job_id  = (int) $job_id;
			$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();

			try {
				$engine_snapshot = datamachine_get_engine_data( $job_id );
				$engine          = new \DataMachine\Core\EngineData( $engine_snapshot, $job_id );

				$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );

				if ( ! $flow_step_config ) {
					$db_flows         = new \DataMachine\Core\Database\Flows\Flows();
					$flow_step_config = $db_flows->get_flow_step_config( $flow_step_id, $job_id, true );

					if ( $flow_step_config ) {
						$existing_flow_config                  = $engine_snapshot['flow_config'] ?? array();
						$existing_flow_config[ $flow_step_id ] = $flow_step_config;
						datamachine_merge_engine_data(
							$job_id,
							array(
								'flow_config' => $existing_flow_config,
							)
						);
						$engine = new \DataMachine\Core\EngineData( datamachine_get_engine_data( $job_id ), $job_id );
					}
				}

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
					return false;
				}

				$flow_id = $flow_step_config['flow_id'];

				/** @var array $context */
				$context = datamachine_get_file_context( $flow_id );

				$retrieval   = new \DataMachine\Core\FilesRepository\FileRetrieval();
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
					return false;
				}

				$step_type           = $flow_step_config['step_type'];
				$step_type_abilities = new \DataMachine\Abilities\StepTypeAbilities();
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
					return false;
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
					return false;
				}

				$payload['data'] = $dataPackets;

				// Check for step success: non-empty packets AND no failure indicators
				$step_success = ! empty( $dataPackets );

				// Check if any packet explicitly indicates failure (metadata.success === false)
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

				// Refresh engine data to capture any changes made during step execution (e.g., job_status from skip_item)
				$refreshed_engine_data = datamachine_get_engine_data( $job_id );
				$engine                = new \DataMachine\Core\EngineData( $refreshed_engine_data, $job_id );

				// Check for status override from tools (e.g., skip_item sets agent_skipped)
				// If set, complete the job immediately without scheduling next step
				$status_override = $engine->get( 'job_status' );

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

				if ( $status_override ) {
					$complete_result = $db_jobs->complete_job( $job_id, $status_override );

					do_action(
						'datamachine_log',
						'debug',
						'Engine: complete_job called with status_override',
						array(
							'job_id' => $job_id,
							'status' => $status_override,
							'result' => $complete_result,
						)
					);
					$cleanup = new \DataMachine\Core\FilesRepository\FileCleanup();
					$context = datamachine_get_file_context( $flow_id );
					$cleanup->cleanup_job_data_packets( $job_id, $context );
					do_action(
						'datamachine_log',
						'info',
						'Pipeline execution completed with status override',
						array(
							'job_id'          => $job_id,
							'pipeline_id'     => $flow_step_config['pipeline_id'] ?? null,
							'flow_id'         => $flow_id,
							'flow_step_id'    => $flow_step_id,
							'final_status'    => $status_override,
							'override_source' => 'engine_data',
						)
					);
				} elseif ( $step_success ) {
					$navigator         = new \DataMachine\Engine\StepNavigator();
					$next_flow_step_id = $navigator->get_next_flow_step_id( $flow_step_id, $payload );

					if ( $next_flow_step_id ) {
						do_action( 'datamachine_schedule_next_step', $job_id, $next_flow_step_id, $dataPackets );
					} else {
						$db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
						$cleanup = new \DataMachine\Core\FilesRepository\FileCleanup();
						$context = datamachine_get_file_context( $flow_id );
						$cleanup->cleanup_job_data_packets( $job_id, $context );
						do_action(
							'datamachine_log',
							'info',
							'Pipeline execution completed successfully',
							array(
								'job_id'             => $job_id,
								'pipeline_id'        => $flow_step_config['pipeline_id'] ?? null,
								'flow_id'            => $flow_id,
								'flow_step_id'       => $flow_step_id,
								'final_packet_count' => count( $dataPackets ),
								'final_status'       => JobStatus::COMPLETED,
							)
						);
					}
				} else {
					// Check if this is a fetch step with processed items history
					// If so, empty result means "no new items" not "failure"
					$is_fetch_step      = ( 'fetch' === $step_type );
					$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
					$has_history        = $db_processed_items->has_processed_items( $flow_step_id );

					if ( $is_fetch_step && $has_history ) {
						// Flow has processed items before - this is "no new items", not a failure
						$db_jobs->complete_job( $job_id, JobStatus::COMPLETED_NO_ITEMS );
						do_action(
							'datamachine_log',
							'info',
							'Flow completed with no new items to process',
							array(
								'job_id'       => $job_id,
								'pipeline_id'  => $flow_step_config['pipeline_id'] ?? null,
								'flow_id'      => $flow_id,
								'flow_step_id' => $flow_step_id,
								'step_type'    => $step_type,
							)
						);
					} else {
						// First run with no items, or non-fetch step failed
						do_action(
							'datamachine_log',
							'error',
							'Step execution failed - empty data packet',
							array(
								'job_id'       => $job_id,
								'pipeline_id'  => $flow_step_config['pipeline_id'] ?? null,
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
					}
				}

				return $step_success;
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
				return false;
			}
		},
		10,
		3
	);

	/**
	 * Schedule next step in flow execution.
	 *
	 * Stores data packet in repository if needed, then schedules
	 * the step execution via Action Scheduler.
	 *
	 * @param int $job_id Job ID for the execution
	 * @param string $flow_step_id Flow step ID to schedule
	 * @param array $dataPackets Data packets to pass to the next step
	 * @return bool True on successful scheduling, false on failure
	 */
	add_action(
		'datamachine_schedule_next_step',
		function ( $job_id, $flow_step_id, $dataPackets = array() ) {
			$job_id = (int) $job_id; // Ensure job_id is int for database operations

			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				return false;
			}

			// Store data by job_id (if present)
			if ( ! empty( $dataPackets ) ) {
				$engine_snapshot  = datamachine_get_engine_data( $job_id );
				$engine           = new \DataMachine\Core\EngineData( $engine_snapshot, $job_id );
				$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );

				$flow_id = (int) ( $flow_step_config['flow_id'] ?? ( $engine->getJobContext()['flow_id'] ?? 0 ) );

				if ( $flow_id <= 0 ) {
					do_action(
						'datamachine_log',
						'error',
						'Flow ID missing during data storage',
						array(
							'job_id'       => $job_id,
							'flow_step_id' => $flow_step_id,
						)
					);
					return false;
				}

				$context = datamachine_get_file_context( $flow_id );

				$storage = new \DataMachine\Core\FilesRepository\FileStorage();
				$storage->store_data_packet( $dataPackets, $job_id, $context );
			}

			// Action Scheduler only receives IDs
			$action_id = as_schedule_single_action(
				time(),
				'datamachine_execute_step',
				array(
					'job_id'       => $job_id,
					'flow_step_id' => $flow_step_id,
				),
				'data-machine'
			);

			if ( ! empty( $dataPackets ) ) {
				do_action(
					'datamachine_log',
					'debug',
					'Next step scheduled via Action Scheduler',
					array(
						'job_id'       => $job_id,
						'flow_step_id' => $flow_step_id,
						'action_id'    => $action_id,
						'success'      => ( false !== $action_id ),
					)
				);
			}

			return false !== $action_id;
		},
		10,
		3
	);

	/**
	 * Schedule flow execution for later.
	 *
	 * Handles both one-time execution at specific timestamps and
	 * recurring execution at defined intervals. Use 'manual' to
	 * clear existing schedules.
	 *
	 * @param int $flow_id Flow ID to schedule
	 * @param string|int $interval_or_timestamp Either 'manual', numeric timestamp, or interval key
	 */
	add_action(
		'datamachine_run_flow_later',
		function ( $flow_id, $interval_or_timestamp ) {
			$db_flows = new \DataMachine\Core\Database\Flows\Flows();

			// 1. Always unschedule existing to prevent duplicates
			if ( function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
			}

			// 2. Handle 'manual' case
			if ( 'manual' === $interval_or_timestamp ) {
				$scheduling_config = array( 'interval' => 'manual' );
				$db_flows->update_flow_scheduling( $flow_id, $scheduling_config );

				do_action(
					'datamachine_log',
					'info',
					'Flow schedule cleared (set to manual)',
					array(
						'flow_id'    => $flow_id,
					)
				);
				return;
			}

			// 3. Determine if timestamp (numeric) or interval string
			if ( is_numeric( $interval_or_timestamp ) ) {
				// One-time execution at specific timestamp
				if ( function_exists( 'as_schedule_single_action' ) ) {
					$action_id = as_schedule_single_action(
						$interval_or_timestamp,
						'datamachine_run_flow_now',
						array( $flow_id ),
						'data-machine'
					);

					// Update database with scheduling configuration
					$scheduling_config = array(
						'interval'       => 'one_time',
						'timestamp'      => $interval_or_timestamp,
						'scheduled_time' => wp_date( 'c', $interval_or_timestamp ),
					);
					$db_flows->update_flow_scheduling( $flow_id, $scheduling_config );

					do_action(
						'datamachine_log',
						'info',
						'Flow scheduled for one-time execution',
						array(
							'flow_id'        => $flow_id,
							'timestamp'      => $interval_or_timestamp,
							'scheduled_time' => wp_date( 'c', $interval_or_timestamp ),
							'action_id'      => $action_id,
						)
					);
				}
			} else {
				// Recurring execution
				$intervals        = apply_filters( 'datamachine_scheduler_intervals', array() );
				$interval_seconds = $intervals[ $interval_or_timestamp ]['seconds'] ?? null;

				if ( ! $interval_seconds ) {
					do_action(
						'datamachine_log',
						'error',
						'Invalid schedule interval',
						array(
							'flow_id'             => $flow_id,
							'interval'            => $interval_or_timestamp,
							'available_intervals' => array_keys( $intervals ),
						)
					);
					return;
				}

				if ( function_exists( 'as_schedule_recurring_action' ) ) {
					$action_id = as_schedule_recurring_action(
						time() + $interval_seconds,
						$interval_seconds,
						'datamachine_run_flow_now',
						array( $flow_id ),
						'data-machine'
					);

					// Update database with scheduling configuration
					$scheduling_config = array(
						'interval'         => $interval_or_timestamp,
						'interval_seconds' => $interval_seconds,
						'first_run'        => wp_date( 'c', time() + $interval_seconds ),
					);
					$db_flows->update_flow_scheduling( $flow_id, $scheduling_config );

					do_action(
						'datamachine_log',
						'info',
						'Flow scheduled for recurring execution',
						array(
							'flow_id'          => $flow_id,
							'interval'         => $interval_or_timestamp,
							'interval_seconds' => $interval_seconds,
							'first_run'        => wp_date( 'c', time() + $interval_seconds ),
							'action_id'        => $action_id,
						)
					);
				}
			}
		},
		10,
		2
	);
} // End datamachine_register_execution_engine()
