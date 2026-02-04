<?php
/**
 * Data Machine Core Action Hooks
 *
 * Central registration for "button press" style action hooks that simplify
 * repetitive behaviors throughout the Data Machine plugin. These actions
 * provide consistent trigger points for common operations.
 *
 * ACTION HOOK PATTERNS:
 * - "Button Press" Style: Actions that multiple pathways can trigger
 * - Centralized Logic: Complex operations consolidated into single handlers
 * - Consistent Error Handling: Unified logging and validation patterns
 * - Service Discovery: Filter-based service access for architectural consistency
 *
 * Core Workflow and Utility Actions Registered:
 * - datamachine_run_flow_now: Central flow execution trigger for manual/scheduled runs
 * - datamachine_execute_step: Core step execution engine for Action Scheduler pipeline processing
 * - datamachine_schedule_next_step: Central pipeline step scheduling eliminating Action Scheduler duplication
 * - datamachine_mark_item_processed: Universal processed item marking across all handlers
 * - datamachine_fail_job: Central job failure handling with cleanup and logging
 * - datamachine_log: Central logging operations eliminating logger service discovery
 *
 * UTILITIES (Abilities API):
 * - LogAbilities: Log file operations (write, clear, read, metadata, level management)
 *
 * EXTENSIBILITY EXAMPLES:
 * External plugins can add: datamachine_transform, datamachine_validate, datamachine_backup, datamachine_migrate, datamachine_sync, datamachine_analyze
 *
 * ARCHITECTURAL BENEFITS:
 * - WordPress-native action registration: Direct add_action() calls, zero overhead
 * - External plugin extensibility: Standard WordPress action registration patterns
 * - Eliminates code duplication across multiple trigger points
 * - Provides single source of truth for complex operations
 * - Simplifies call sites from 40+ lines to single action calls
 *
 * @package DataMachine
 * @since 0.1.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Include organized action classes
require_once __DIR__ . '/ImportExport.php';
require_once __DIR__ . '/Engine.php';

/**
 * Register core Data Machine action hooks.
 *
 * @since 0.1.0
 */
function datamachine_register_core_actions() {

	add_action(
		'datamachine_mark_item_processed',
		function ( $flow_step_id, $source_type, $item_identifier, $job_id ) {
			$job_id = (int) $job_id;

			if ( ! isset( $flow_step_id ) || ! isset( $source_type ) || ! isset( $item_identifier ) ) {
				do_action(
					'datamachine_log',
					'error',
					'datamachine_mark_item_processed called with missing required parameters',
					array(
						'flow_step_id'       => $flow_step_id,
						'source_type'        => $source_type,
						'item_identifier'    => substr( $item_identifier ?? '', 0, 50 ) . '...',
						'job_id'             => $job_id,
						'parameter_provided' => func_num_args() >= 4,
					)
				);
				return;
			}

			if ( empty( $job_id ) || ! is_numeric( $job_id ) || $job_id <= 0 ) {
				do_action(
					'datamachine_log',
					'error',
					'datamachine_mark_item_processed called without valid job_id',
					array(
						'flow_step_id'       => $flow_step_id,
						'source_type'        => $source_type,
						'item_identifier'    => substr( $item_identifier, 0, 50 ) . '...',
						'job_id'             => $job_id,
						'job_id_type'        => gettype( $job_id ),
						'parameter_provided' => func_num_args() >= 4,
					)
				);
				return;
			}

			$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
			$success            = $db_processed_items->add_processed_item( $flow_step_id, $source_type, $item_identifier, $job_id );

			return $success;
		},
		10,
		4
	);

	// Central job failure hook - handles job failure with cleanup and logging
	add_action(
		'datamachine_fail_job',
		function ( $job_id, $reason, $context_data = array() ) {
			$job_id = (int) $job_id;

			if ( empty( $job_id ) || $job_id <= 0 ) {
				do_action(
					'datamachine_log',
					'error',
					'datamachine_fail_job called without valid job_id',
					array(
						'job_id' => $job_id,
						'reason' => $reason,
					)
				);
				return false;
			}

			$db_jobs            = new \DataMachine\Core\Database\Jobs\Jobs();
			$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();

			// Use most specific reason: context_data['reason'] > $reason > null
			$specific_reason = $context_data['reason'] ?? $reason;
			$status          = \DataMachine\Core\JobStatus::failed( $specific_reason );
			$success         = $db_jobs->complete_job( $job_id, $status->toString() );

			// Re-queue logic: If a queued prompt was popped but the job failed, add it back to the end of the queue.
			$engine_data = \datamachine_get_engine_data( $job_id );
			if ( isset( $engine_data['queued_prompt_backup'] ) && is_array( $engine_data['queued_prompt_backup'] ) ) {
				$backup = $engine_data['queued_prompt_backup'];
				if ( ! empty( $backup['prompt'] ) && ! empty( $backup['flow_id'] ) && ! empty( $backup['flow_step_id'] ) ) {
					$queue_ability = new \DataMachine\Abilities\Flow\QueueAbility();
					$result        = $queue_ability->executeQueueAdd(
						array(
							'flow_id'      => (int) $backup['flow_id'],
							'flow_step_id' => (string) $backup['flow_step_id'],
							'prompt'       => $backup['prompt'],
						)
					);

					if ( ! empty( $result['success'] ) ) {
						// Only clear backup after successful re-queue.
						unset( $engine_data['queued_prompt_backup'] );
						\datamachine_set_engine_data( $job_id, $engine_data );
						do_action(
							'datamachine_log',
							'info',
							'Prompt re-queued to back due to job failure',
							array(
								'job_id'       => $job_id,
								'flow_id'      => (int) $backup['flow_id'],
								'flow_step_id' => (string) $backup['flow_step_id'],
								'prompt'       => $backup['prompt'],
							)
						);
					} else {
						// Re-queue failed - keep backup so operator can recover manually.
						do_action(
							'datamachine_log',
							'error',
							'Failed to re-queue prompt after job failure - backup retained in engine_data',
							array(
								'job_id'       => $job_id,
								'flow_id'      => (int) $backup['flow_id'],
								'flow_step_id' => (string) $backup['flow_step_id'],
								'prompt'       => $backup['prompt'],
								'queue_error'  => $result['error'] ?? 'unknown',
							)
						);
					}
				}
			}

			if ( ! $success ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to mark job as failed in database',
					array(
						'job_id' => $job_id,
						'reason' => $reason,
					)
				);
				return false;
			}

			$db_processed_items->delete_processed_items( array( 'job_id' => $job_id ) );

			$cleanup_files = \DataMachine\Core\PluginSettings::get( 'cleanup_job_data_on_failure', true );
			$files_cleaned = false;

			if ( $cleanup_files ) {
				$job = $db_jobs->get_job( $job_id );
				if ( $job && function_exists( 'datamachine_get_file_context' ) ) {
					$cleanup       = new \DataMachine\Core\FilesRepository\FileCleanup();
					$context       = datamachine_get_file_context( $job['flow_id'] );
					$deleted_count = $cleanup->cleanup_job_data_packets( $job_id, $context );
					$files_cleaned = $deleted_count > 0;
				}
			}

			do_action(
				'datamachine_log',
				'error',
				'Job marked as failed',
				array(
					'job_id'                  => $job_id,
					'failure_reason'          => $reason,
					'triggered_by'            => 'datamachine_fail_job',
					'context_data'            => $context_data,
					'processed_items_cleaned' => true,
					'files_cleanup_enabled'   => $cleanup_files,
					'files_cleaned'           => $files_cleaned,
				)
			);

			return true;
		},
		10,
		3
	);

	// Update flow health cache when jobs complete - enables efficient problem flow detection
	add_action(
		'datamachine_job_complete',
		function ( $job_id, $status ) {
			$jobs_ops = new \DataMachine\Core\Database\Jobs\JobsOperations();
			$jobs_ops->update_flow_health_cache( $job_id, $status );
		},
		10,
		2
	);

	// Central logging hook - delegates to abilities-based logging
	add_action(
		'datamachine_log',
		function ( $operation, $param2 = null, $param3 = null, &$result = null ) {
			$management_operations = array( 'clear_all', 'cleanup', 'set_level' );

			if ( in_array( $operation, $management_operations, true ) ) {
				switch ( $operation ) {
					case 'clear_all':
						if ( class_exists( 'WP_Ability' ) ) {
							$ability        = wp_get_ability( 'datamachine/clear-logs' );
							$ability_result = $ability->execute( array( 'agent_type' => 'all' ) );
							$result         = is_wp_error( $ability_result ) ? false : $ability_result['success'];
						} else {
							$result = datamachine_clear_all_log_files();
						}
						return $result;

					case 'cleanup':
						$max_size_mb  = $param2 ?? 10;
						$max_age_days = $param3 ?? 30;
						$result       = datamachine_cleanup_log_files( $max_size_mb, $max_age_days );
						return $result;

					case 'set_level':
						$result = datamachine_set_log_level( $param2, $param3 );
						return $result;
				}
			}

			$context      = $param3 ?? array();
			$valid_levels = datamachine_get_valid_log_levels();

			if ( ! in_array( $operation, $valid_levels, true ) ) {
				if ( class_exists( 'WP_Ability' ) ) {
					$ability = wp_get_ability( 'datamachine/write-to-log' );
					$result  = $ability->execute(
						array(
							'level'   => $operation,
							'message' => $param2,
							'context' => $context,
						)
					);
					$result  = is_wp_error( $result ) ? false : true;
					return $result;
				}
				return false;
			}

			$function_name = 'datamachine_log_' . $operation;
			if ( function_exists( $function_name ) ) {
				$function_name( $param2, $context );
				return true;
			}

			return false;
		},
		10,
		4
	);

	\DataMachine\Engine\Actions\ImportExport::register();
	datamachine_register_execution_engine();
}
