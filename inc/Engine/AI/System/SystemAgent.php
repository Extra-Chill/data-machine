<?php
/**
 * System Agent - Core async task orchestration.
 *
 * Manages async task scheduling and execution for tools that need background
 * processing. Integrates with DM Jobs for tracking and Action Scheduler for
 * execution timing. Routes completed results back to originating contexts.
 *
 * @package DataMachine\Engine\AI\System
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

class SystemAgent {

	/**
	 * Singleton instance.
	 *
	 * @var SystemAgent|null
	 */
	private static ?SystemAgent $instance = null;

	/**
	 * Registered task handlers.
	 *
	 * @var array<string, string> Task type => handler class name mapping.
	 */
	private array $taskHandlers = array();

	/**
	 * Private constructor for singleton pattern.
	 */
	private function __construct() {
		$this->loadTaskHandlers();
	}

	/**
	 * Get singleton instance.
	 *
	 * @return SystemAgent
	 */
	public static function getInstance(): SystemAgent {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Schedule an async task.
	 *
	 * Creates a DM Job record and schedules an Action Scheduler action for
	 * task execution. Returns the job ID for tracking purposes.
	 *
	 * @param string $taskType Task type identifier.
	 * @param array  $params   Task parameters to store in engine_data.
	 * @param array  $context  Context for routing results back (origin, IDs, etc.).
	 * @param array  $meta     Additional metadata merged into engine_data (e.g. batch_job_id).
	 * @return int|false Job ID on success, false on failure.
	 */
	public function scheduleTask( string $taskType, array $params, array $context = array(), array $meta = array() ): int|false {
		if ( ! isset( $this->taskHandlers[ $taskType ] ) ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: Unknown task type '{$taskType}'",
				array(
					'task_type'  => $taskType,
					'agent_type' => 'system',
					'params'     => $params,
					'context'    => $context,
				)
			);
			return false;
		}

		// Create DM Job — matches Jobs::create_job() schema
		$jobs_db = new Jobs();
		$job_id  = $jobs_db->create_job( array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => ucfirst( str_replace( '_', ' ', $taskType ) ),
		) );

		if ( ! $job_id ) {
			do_action(
				'datamachine_log',
				'error',
				'System Agent: Failed to create job for task',
				array(
					'task_type'  => $taskType,
					'agent_type' => 'system',
				)
			);
			return false;
		}

		// Store task params in engine_data
		$jobs_db->store_engine_data( (int) $job_id, array_merge( $params, array(
			'task_type'    => $taskType,
			'context'      => $context,
			'scheduled_at' => current_time( 'mysql' ),
		), $meta ) );

		// Mark job as processing
		$jobs_db->start_job( (int) $job_id, JobStatus::PROCESSING );

		// Schedule Action Scheduler action
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$args = array(
				'job_id' => $job_id,
			);

			$action_id = as_schedule_single_action(
				time(),
				'datamachine_system_agent_handle_task',
				$args,
				'data-machine'
			);

			if ( $action_id ) {
				do_action(
					'datamachine_log',
					'info',
					"System Agent task scheduled: {$taskType} (Job #{$job_id})",
					array(
						'job_id'     => $job_id,
						'action_id'  => $action_id,
						'task_type'  => $taskType,
						'agent_type' => 'system',
						'params'     => $params,
						'context'    => $context,
					)
				);

				return $job_id;
			} else {
				// Action Scheduler failed - mark job as failed
				$jobs_db->complete_job( $job_id, JobStatus::failed( 'Failed to schedule Action Scheduler action' )->toString() );
				return false;
			}
		} else {
			// Action Scheduler not available
			$jobs_db->complete_job( $job_id, JobStatus::failed( 'Action Scheduler not available' )->toString() );
			return false;
		}
	}

	/**
	 * Default chunk size for batch scheduling.
	 *
	 * Controls how many individual tasks are created per batch cycle.
	 * Between cycles, other task types can run in Action Scheduler.
	 */
	const BATCH_CHUNK_SIZE = 10;

	/**
	 * Delay in seconds between batch chunks.
	 *
	 * Gives Action Scheduler time to process other pending actions
	 * between bulk task chunks.
	 */
	const BATCH_CHUNK_DELAY = 30;

	/**
	 * Schedule a batch of tasks with chunked execution.
	 *
	 * Instead of creating hundreds of AS actions at once, stores all items
	 * in a transient and processes them in chunks. Between chunks, other
	 * task types can run — preventing queue flooding.
	 *
	 * @since 0.32.0
	 *
	 * @param string $taskType   Task type identifier (must be registered).
	 * @param array  $itemParams Array of parameter arrays, one per task.
	 * @param array  $context    Shared context for all tasks in the batch.
	 * @param int    $chunkSize  Items per chunk (default: BATCH_CHUNK_SIZE).
	 * @return array{batch_id: string, total: int, chunk_size: int}|false Batch info or false on failure.
	 */
	public function scheduleBatch( string $taskType, array $itemParams, array $context = array(), int $chunkSize = 0 ): array|false {
		if ( ! isset( $this->taskHandlers[ $taskType ] ) ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: Cannot schedule batch for unknown task type '{$taskType}'",
				array(
					'task_type'  => $taskType,
					'agent_type' => 'system',
					'count'      => count( $itemParams ),
				)
			);
			return false;
		}

		if ( empty( $itemParams ) ) {
			return false;
		}

		if ( $chunkSize <= 0 ) {
			$chunkSize = self::BATCH_CHUNK_SIZE;
		}

		// If small enough, just schedule directly — no batch overhead.
		if ( count( $itemParams ) <= $chunkSize ) {
			$job_ids = array();
			foreach ( $itemParams as $params ) {
				$job_id = $this->scheduleTask( $taskType, $params, $context );
				if ( $job_id ) {
					$job_ids[] = $job_id;
				}
			}
			return array(
				'batch_id'   => 'direct',
				'total'      => count( $itemParams ),
				'scheduled'  => count( $job_ids ),
				'chunk_size' => $chunkSize,
				'job_ids'    => $job_ids,
			);
		}

		// Generate batch ID and transient key.
		$batch_id      = 'dm_batch_' . wp_generate_uuid4();
		$transient_key = 'datamachine_batch_' . md5( $batch_id );

		// Create parent batch job for persistent tracking.
		$jobs_db      = new Jobs();
		$batch_job_id = $jobs_db->create_job( array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'batch',
			'label'       => 'Batch: ' . ucfirst( str_replace( '_', ' ', $taskType ) ),
		) );

		if ( $batch_job_id ) {
			$jobs_db->start_job( (int) $batch_job_id, JobStatus::PROCESSING );
			$jobs_db->store_engine_data( (int) $batch_job_id, array(
				'batch'           => true,
				'task_type'       => $taskType,
				'batch_id'        => $batch_id,
				'transient_key'   => $transient_key,
				'total'           => count( $itemParams ),
				'chunk_size'      => $chunkSize,
				'offset'          => 0,
				'tasks_scheduled' => 0,
				'started_at'      => current_time( 'mysql' ),
			) );
		}

		$batch_data = array(
			'batch_id'     => $batch_id,
			'batch_job_id' => $batch_job_id ?: 0,
			'task_type'    => $taskType,
			'context'      => $context,
			'items'        => $itemParams,
			'chunk_size'   => $chunkSize,
			'offset'       => 0,
			'total'        => count( $itemParams ),
			'created_at'   => current_time( 'mysql' ),
		);

		// Store with 4 hour TTL — enough time for large batches to complete.
		set_transient( $transient_key, $batch_data, 4 * HOUR_IN_SECONDS );

		// Schedule first chunk.
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			delete_transient( $transient_key );
			if ( $batch_job_id ) {
				$jobs_db->complete_job( $batch_job_id, JobStatus::failed( 'Action Scheduler not available' )->toString() );
			}
			return false;
		}

		$action_id = as_schedule_single_action(
			time(),
			'datamachine_system_agent_process_batch',
			array( 'batch_id' => $batch_id ),
			'data-machine'
		);

		if ( ! $action_id ) {
			delete_transient( $transient_key );
			if ( $batch_job_id ) {
				$jobs_db->complete_job( $batch_job_id, JobStatus::failed( 'Failed to schedule batch action' )->toString() );
			}
			return false;
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'System Agent batch scheduled: %s (%d items in chunks of %d)',
				$taskType,
				count( $itemParams ),
				$chunkSize
			),
			array(
				'batch_id'     => $batch_id,
				'batch_job_id' => $batch_job_id,
				'task_type'    => $taskType,
				'agent_type'   => 'system',
				'total'        => count( $itemParams ),
				'chunk_size'   => $chunkSize,
			)
		);

		return array(
			'batch_id'     => $batch_id,
			'batch_job_id' => $batch_job_id,
			'total'        => count( $itemParams ),
			'scheduled'    => 0, // Actual scheduling happens in chunks.
			'chunk_size'   => $chunkSize,
		);
	}

	/**
	 * Process a batch chunk (Action Scheduler callback).
	 *
	 * Pulls the next chunk of items from the batch transient, schedules
	 * individual tasks for each, then schedules the next chunk (if any)
	 * with a delay to allow other task types to execute between chunks.
	 *
	 * @since 0.32.0
	 *
	 * @param string $batchId Batch identifier.
	 */
	public function processBatchChunk( string $batchId ): void {
		$transient_key = 'datamachine_batch_' . md5( $batchId );
		$batch_data    = get_transient( $transient_key );

		if ( false === $batch_data || ! is_array( $batch_data ) ) {
			do_action(
				'datamachine_log',
				'warning',
				"System Agent: Batch {$batchId} not found or expired",
				array(
					'batch_id'   => $batchId,
					'agent_type' => 'system',
				)
			);
			return;
		}

		$task_type    = $batch_data['task_type'];
		$context      = $batch_data['context'] ?? array();
		$items        = $batch_data['items'] ?? array();
		$chunk_size   = $batch_data['chunk_size'] ?? self::BATCH_CHUNK_SIZE;
		$offset       = $batch_data['offset'] ?? 0;
		$total        = $batch_data['total'] ?? count( $items );
		$batch_job_id = $batch_data['batch_job_id'] ?? 0;

		// Check for cancellation via parent batch job.
		if ( $batch_job_id > 0 ) {
			$jobs_db    = new Jobs();
			$parent_job = $jobs_db->get_job( $batch_job_id );

			if ( $parent_job ) {
				$parent_data = $parent_job['engine_data'] ?? array();

				if ( ! empty( $parent_data['cancelled'] ) ) {
					delete_transient( $transient_key );
					$jobs_db->complete_job( $batch_job_id, 'cancelled' );

					do_action(
						'datamachine_log',
						'info',
						sprintf( 'System Agent batch cancelled: %s (at %d/%d)', $task_type, $offset, $total ),
						array(
							'batch_id'     => $batchId,
							'batch_job_id' => $batch_job_id,
							'task_type'    => $task_type,
							'agent_type'   => 'system',
							'offset'       => $offset,
							'total'        => $total,
						)
					);
					return;
				}
			}
		}

		// Build child job metadata for linking back to parent batch.
		$child_meta = array();
		if ( $batch_job_id > 0 ) {
			$child_meta['batch_job_id'] = $batch_job_id;
		}

		// Get current chunk.
		$chunk     = array_slice( $items, $offset, $chunk_size );
		$scheduled = 0;

		foreach ( $chunk as $params ) {
			$job_id = $this->scheduleTask( $task_type, $params, $context, $child_meta );
			if ( $job_id ) {
				++$scheduled;
			}
		}

		$new_offset = $offset + $chunk_size;

		// Update parent batch job progress.
		if ( $batch_job_id > 0 ) {
			if ( ! isset( $jobs_db ) ) {
				$jobs_db = new Jobs();
			}
			$parent_data = $jobs_db->retrieve_engine_data( $batch_job_id );
			$parent_data['offset']          = min( $new_offset, $total );
			$parent_data['tasks_scheduled'] = ( $parent_data['tasks_scheduled'] ?? 0 ) + $scheduled;
			$jobs_db->store_engine_data( $batch_job_id, $parent_data );
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'System Agent batch chunk processed: %s (%d/%d, scheduled %d)',
				$task_type,
				min( $new_offset, $total ),
				$total,
				$scheduled
			),
			array(
				'batch_id'     => $batchId,
				'batch_job_id' => $batch_job_id,
				'task_type'    => $task_type,
				'agent_type'   => 'system',
				'offset'       => $offset,
				'chunk_size'   => $chunk_size,
				'scheduled'    => $scheduled,
				'remaining'    => max( 0, $total - $new_offset ),
			)
		);

		// Schedule next chunk if items remain.
		if ( $new_offset < $total ) {
			$batch_data['offset'] = $new_offset;
			set_transient( $transient_key, $batch_data, 4 * HOUR_IN_SECONDS );

			as_schedule_single_action(
				time() + self::BATCH_CHUNK_DELAY,
				'datamachine_system_agent_process_batch',
				array( 'batch_id' => $batchId ),
				'data-machine'
			);
		} else {
			// Batch complete — clean up transient and mark parent job.
			delete_transient( $transient_key );

			if ( $batch_job_id > 0 ) {
				if ( ! isset( $jobs_db ) ) {
					$jobs_db = new Jobs();
				}
				$parent_data                 = $jobs_db->retrieve_engine_data( $batch_job_id );
				$parent_data['completed_at'] = current_time( 'mysql' );
				$jobs_db->store_engine_data( $batch_job_id, $parent_data );
				$jobs_db->complete_job( $batch_job_id, JobStatus::COMPLETED );
			}

			do_action(
				'datamachine_log',
				'info',
				sprintf( 'System Agent batch complete: %s (%d items)', $task_type, $total ),
				array(
					'batch_id'     => $batchId,
					'batch_job_id' => $batch_job_id,
					'task_type'    => $task_type,
					'agent_type'   => 'system',
					'total'        => $total,
				)
			);
		}
	}

	/**
	 * Get the status of a batch by its parent job ID.
	 *
	 * Reads the parent batch job and counts child job statuses.
	 *
	 * @since 0.33.0
	 *
	 * @param int $batchJobId Parent batch job ID.
	 * @return array|null Batch status or null if not found.
	 */
	public function getBatchStatus( int $batchJobId ): ?array {
		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $batchJobId );

		if ( ! $job ) {
			return null;
		}

		$engine_data = $job['engine_data'] ?? array();

		if ( empty( $engine_data['batch'] ) ) {
			return null;
		}

		// Count child jobs by status.
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';
		$child_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
					SUM(CASE WHEN status LIKE %s THEN 1 ELSE 0 END) as failed,
					SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
				FROM {$table}
				WHERE engine_data LIKE %s",
				'failed%',
				'%"batch_job_id":' . $batchJobId . '%'
			),
			ARRAY_A
		);

		return array(
			'batch_job_id'    => $batchJobId,
			'task_type'       => $engine_data['task_type'] ?? '',
			'total_items'     => $engine_data['total'] ?? 0,
			'offset'          => $engine_data['offset'] ?? 0,
			'chunk_size'      => $engine_data['chunk_size'] ?? self::BATCH_CHUNK_SIZE,
			'tasks_scheduled' => $engine_data['tasks_scheduled'] ?? 0,
			'status'          => $job['status'] ?? '',
			'started_at'      => $engine_data['started_at'] ?? '',
			'completed_at'    => $engine_data['completed_at'] ?? '',
			'cancelled'       => ! empty( $engine_data['cancelled'] ),
			'child_jobs'      => array(
				'total'      => (int) ( $child_stats['total'] ?? 0 ),
				'completed'  => (int) ( $child_stats['completed'] ?? 0 ),
				'failed'     => (int) ( $child_stats['failed'] ?? 0 ),
				'processing' => (int) ( $child_stats['processing'] ?? 0 ),
				'pending'    => (int) ( $child_stats['pending'] ?? 0 ),
			),
		);
	}

	/**
	 * Cancel a running batch.
	 *
	 * Sets the cancelled flag on the parent batch job. The next
	 * processBatchChunk() call will see it and stop scheduling.
	 *
	 * @since 0.33.0
	 *
	 * @param int $batchJobId Parent batch job ID.
	 * @return bool True on success, false if not found or not a batch.
	 */
	public function cancelBatch( int $batchJobId ): bool {
		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $batchJobId );

		if ( ! $job ) {
			return false;
		}

		$engine_data = $job['engine_data'] ?? array();

		if ( empty( $engine_data['batch'] ) ) {
			return false;
		}

		$engine_data['cancelled']    = true;
		$engine_data['cancelled_at'] = current_time( 'mysql' );
		$jobs_db->store_engine_data( $batchJobId, $engine_data );

		// Also delete the transient to prevent further chunk scheduling.
		$transient_key = $engine_data['transient_key'] ?? '';
		if ( ! empty( $transient_key ) ) {
			delete_transient( $transient_key );
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'System Agent batch cancelled: job #%d (%s)', $batchJobId, $engine_data['task_type'] ?? '' ),
			array(
				'batch_job_id' => $batchJobId,
				'task_type'    => $engine_data['task_type'] ?? '',
				'agent_type'   => 'system',
			)
		);

		return true;
	}

	/**
	 * Find all batch parent jobs.
	 *
	 * @since 0.33.0
	 *
	 * @return array Array of batch job records.
	 */
	public function listBatches(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		$results = $wpdb->get_results(
			"SELECT * FROM {$table}
			WHERE source = 'batch'
			ORDER BY created_at DESC
			LIMIT 50",
			ARRAY_A
		);

		if ( ! $results ) {
			return array();
		}

		// Decode engine_data JSON for each job.
		foreach ( $results as &$row ) {
			$row['engine_data'] = ! empty( $row['engine_data'] )
				? json_decode( $row['engine_data'], true )
				: array();
		}

		return $results;
	}

	/**
	 * Handle a scheduled task (Action Scheduler callback).
	 *
	 * Loads the job, determines the task type, and delegates to the
	 * appropriate handler for execution.
	 *
	 * @param int $jobId Job ID from DM Jobs table.
	 */
	public function handleTask( int $jobId ): void {
		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $jobId );

		if ( ! $job ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: Job {$jobId} not found",
				array(
					'job_id'     => $jobId,
					'agent_type' => 'system',
				)
			);
			return;
		}

		$engine_data = $job['engine_data'] ?? array();
		$task_type   = $engine_data['task_type'] ?? '';

		if ( empty( $task_type ) ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: No task type found in job {$jobId}",
				array(
					'job_id'      => $jobId,
					'agent_type'  => 'system',
					'engine_data' => $engine_data,
				)
			);

			$jobs_db->complete_job( $jobId, JobStatus::failed( 'No task type found' )->toString() );
			return;
		}

		if ( ! isset( $this->taskHandlers[ $task_type ] ) ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: Unknown task type '{$task_type}' for job {$jobId}",
				array(
					'job_id'     => $jobId,
					'task_type'  => $task_type,
					'agent_type' => 'system',
				)
			);

			$jobs_db->complete_job( $jobId, JobStatus::failed( "Unknown task type: {$task_type}" )->toString() );
			return;
		}

		// Instantiate and execute the task handler
		$handler_class = $this->taskHandlers[ $task_type ];

		try {
			$handler = new $handler_class();
			$handler->execute( $jobId, $engine_data );
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent task execution failed for job {$jobId}: " . $e->getMessage(),
				array(
					'job_id'         => $jobId,
					'task_type'      => $task_type,
					'agent_type'     => 'system',
					'handler_class'  => $handler_class,
					'exception'      => $e->getMessage(),
					'exception_file' => $e->getFile(),
					'exception_line' => $e->getLine(),
				)
			);

			// Mark job as failed due to exception
			$jobs_db->complete_job( $jobId, JobStatus::failed( 'Task execution exception: ' . $e->getMessage() )->toString() );
		}
	}

	/**
	 * Load task handlers from filter.
	 *
	 * Uses the datamachine_system_agent_tasks filter to allow registration
	 * of task type => handler class mappings.
	 */
	private function loadTaskHandlers(): void {
		/**
		 * Filter to register System Agent task handlers.
		 *
		 * @param array $handlers Task type => handler class name mapping.
		 */
		$this->taskHandlers = apply_filters( 'datamachine_system_agent_tasks', array() );
	}

	/**
	 * Get registered task handlers (for debugging/admin purposes).
	 *
	 * @return array<string, string> Task type => handler class mappings.
	 */
	public function getTaskHandlers(): array {
		return $this->taskHandlers;
	}

	/**
	 * Get the full task registry with metadata for the admin UI.
	 *
	 * Iterates registered handlers, reads static getTaskMeta() from each,
	 * and merges with current enabled state from PluginSettings.
	 *
	 * @return array<string, array> Task type => metadata array.
	 * @since 0.32.0
	 */
	public function getTaskRegistry(): array {
		$registry = array();

		foreach ( $this->taskHandlers as $task_type => $handler_class ) {
			$meta = array(
				'label'           => '',
				'description'     => '',
				'setting_key'     => null,
				'default_enabled' => true,
			);

			if ( method_exists( $handler_class, 'getTaskMeta' ) ) {
				$meta = array_merge( $meta, $handler_class::getTaskMeta() );
			}

			// Resolve current enabled state from settings.
			$enabled = true;
			if ( ! empty( $meta['setting_key'] ) ) {
				$enabled = (bool) \DataMachine\Core\PluginSettings::get(
					$meta['setting_key'],
					$meta['default_enabled']
				);
			}

			$registry[ $task_type ] = array(
				'task_type'       => $task_type,
				'label'           => $meta['label'] ?: ucfirst( str_replace( '_', ' ', $task_type ) ),
				'description'     => $meta['description'],
				'setting_key'     => $meta['setting_key'],
				'default_enabled' => $meta['default_enabled'],
				'enabled'         => $enabled,
			);
		}

		return $registry;
	}
}
