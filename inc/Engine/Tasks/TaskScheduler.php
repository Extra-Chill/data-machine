<?php
/**
 * Task Scheduler - Routes system tasks through the workflow engine.
 *
 * All task scheduling now delegates to datamachine/execute-workflow.
 * The task's getWorkflow() method provides the step list; the engine
 * handles job creation, Action Scheduler dispatch, and step execution.
 *
 * This replaces the previous direct-to-Action-Scheduler path that used
 * the datamachine_task_handle hook and per-task execute() calls.
 *
 * @package DataMachine\Engine\Tasks
 * @since 0.37.0
 * @since 0.72.0 Delegates to datamachine/execute-workflow; handleTask() removed.
 */

namespace DataMachine\Engine\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

class TaskScheduler {

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
	 * Schedule an async task via the workflow engine.
	 *
	 * Resolves the task handler, calls getWorkflow() to build the step
	 * list, and delegates to datamachine/execute-workflow for execution.
	 *
	 * @param string $taskType    Task type identifier.
	 * @param array  $params      Task parameters passed to getWorkflow().
	 * @param array  $context     Context for routing results back (origin, IDs, etc.).
	 * @param int    $parentJobId Parent job ID for hierarchy (batch parent, pipeline parent).
	 * @return int|false Job ID on success, false on failure.
	 */
	public static function schedule( string $taskType, array $params, array $context = array(), int $parentJobId = 0 ): int|false {
		if ( ! TaskRegistry::isRegistered( $taskType ) ) {
			do_action(
				'datamachine_log',
				'error',
				"TaskScheduler: Unknown task type '{$taskType}'",
				array(
					'task_type' => $taskType,
					'context'   => 'system',
					'params'    => $params,
					'route'     => $context,
				)
			);
			return false;
		}

		// Resolve the task handler and build the workflow.
		$handler_class = TaskRegistry::getHandler( $taskType );

		if ( ! $handler_class || ! class_exists( $handler_class ) ) {
			do_action(
				'datamachine_log',
				'error',
				"TaskScheduler: Handler class not found for '{$taskType}'",
				array( 'task_type' => $taskType, 'handler_class' => $handler_class )
			);
			return false;
		}

		$handler  = new $handler_class();
		$workflow = $handler->getWorkflow( $params );

		if ( empty( $workflow['steps'] ) ) {
			do_action(
				'datamachine_log',
				'error',
				"TaskScheduler: getWorkflow() returned empty steps for '{$taskType}'",
				array( 'task_type' => $taskType, 'params' => $params )
			);
			return false;
		}

		// Delegate to the execute-workflow ability.
		$ability = wp_get_ability( 'datamachine/execute-workflow' );

		if ( ! $ability ) {
			do_action(
				'datamachine_log',
				'error',
				'TaskScheduler: datamachine/execute-workflow ability not available',
				array( 'task_type' => $taskType )
			);
			return false;
		}

		$result = $ability->execute( array(
			'workflow'     => $workflow,
			'timestamp'    => $params['scheduled_at'] ?? null,
			'initial_data' => array(
				'task_type'     => $taskType,
				'task_params'   => $params,
				'task_context'  => $context,
				'parent_job_id' => $parentJobId,
				'user_id'       => (int) ( $context['user_id'] ?? 0 ),
			),
		) );

		if ( empty( $result['success'] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'TaskScheduler: Workflow execution failed for ' . $taskType . ': ' . ( $result['error'] ?? 'Unknown error' ),
				array(
					'task_type' => $taskType,
					'context'   => 'system',
					'error'     => $result['error'] ?? '',
				)
			);
			return false;
		}

		$job_id = $result['job_id'] ?? 0;

		do_action(
			'datamachine_log',
			'info',
			"Task scheduled via workflow engine: {$taskType} (Job #{$job_id})",
			array(
				'job_id'         => $job_id,
				'task_type'      => $taskType,
				'execution_type' => $result['execution_type'] ?? 'immediate',
				'context'        => 'system',
				'params'         => $params,
				'route'          => $context,
			)
		);

		return $job_id;
	}

	/**
	 * Schedule a batch of tasks with chunked execution.
	 *
	 * Instead of creating hundreds of workflow jobs at once, stores all items
	 * in a transient and processes them in chunks. Between chunks, other
	 * task types can run — preventing queue flooding.
	 *
	 * Each item becomes its own standalone workflow job via schedule().
	 *
	 * @param string $taskType   Task type identifier (must be registered).
	 * @param array  $itemParams Array of parameter arrays, one per task.
	 * @param array  $context    Shared context for all tasks in the batch.
	 * @param int    $chunkSize  Items per chunk (default: BATCH_CHUNK_SIZE).
	 * @return array{batch_id: string, total: int, chunk_size: int}|false Batch info or false on failure.
	 */
	public static function scheduleBatch( string $taskType, array $itemParams, array $context = array(), int $chunkSize = 0 ): array|false {
		if ( ! TaskRegistry::isRegistered( $taskType ) ) {
			do_action(
				'datamachine_log',
				'error',
				"TaskScheduler: Cannot schedule batch for unknown task type '{$taskType}'",
				array(
					'task_type' => $taskType,
					'context'   => 'system',
					'count'     => count( $itemParams ),
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
				$job_id = self::schedule( $taskType, $params, $context );
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
			'batch_job_id' => $batch_job_id ? $batch_job_id : 0,
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
			'datamachine_task_process_batch',
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
				'Task batch scheduled: %s (%d items in chunks of %d)',
				$taskType,
				count( $itemParams ),
				$chunkSize
			),
			array(
				'batch_id'     => $batch_id,
				'batch_job_id' => $batch_job_id,
				'task_type'    => $taskType,
				'context'      => 'system',
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
	 * @param string $batchId Batch identifier.
	 */
	public static function processBatchChunk( string $batchId ): void {
		$transient_key = 'datamachine_batch_' . md5( $batchId );
		$batch_data    = get_transient( $transient_key );

		if ( false === $batch_data || ! is_array( $batch_data ) ) {
			do_action(
				'datamachine_log',
				'warning',
				"TaskScheduler: Batch {$batchId} not found or expired",
				array(
					'batch_id' => $batchId,
					'context'  => 'system',
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
						sprintf( 'Task batch cancelled: %s (at %d/%d)', $task_type, $offset, $total ),
						array(
							'batch_id'     => $batchId,
							'batch_job_id' => $batch_job_id,
							'task_type'    => $task_type,
							'context'      => 'system',
							'offset'       => $offset,
							'total'        => $total,
						)
					);
					return;
				}
			}
		}

		// Get current chunk.
		$chunk     = array_slice( $items, $offset, $chunk_size );
		$scheduled = 0;

		foreach ( $chunk as $params ) {
			$job_id = self::schedule( $task_type, $params, $context, $batch_job_id );
			if ( $job_id ) {
				++$scheduled;
			}
		}

		$new_offset = $offset + $chunk_size;

		// Update parent batch job progress.
		if ( $batch_job_id > 0 ) {
			$progress_db                    = $jobs_db ?? new Jobs();
			$parent_data                    = $progress_db->retrieve_engine_data( $batch_job_id );
			$parent_data['offset']          = min( $new_offset, $total );
			$parent_data['tasks_scheduled'] = ( $parent_data['tasks_scheduled'] ?? 0 ) + $scheduled;
			$progress_db->store_engine_data( $batch_job_id, $parent_data );
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Task batch chunk processed: %s (%d/%d, scheduled %d)',
				$task_type,
				min( $new_offset, $total ),
				$total,
				$scheduled
			),
			array(
				'batch_id'     => $batchId,
				'batch_job_id' => $batch_job_id,
				'task_type'    => $task_type,
				'context'      => 'system',
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
				'datamachine_task_process_batch',
				array( 'batch_id' => $batchId ),
				'data-machine'
			);
		} else {
			// Batch complete — clean up transient and mark parent job.
			delete_transient( $transient_key );

			if ( $batch_job_id > 0 ) {
				$complete_db                 = $jobs_db ?? new Jobs();
				$parent_data                 = $complete_db->retrieve_engine_data( $batch_job_id );
				$parent_data['completed_at'] = current_time( 'mysql' );
				$complete_db->store_engine_data( $batch_job_id, $parent_data );
				$complete_db->complete_job( $batch_job_id, JobStatus::COMPLETED );
			}

			do_action(
				'datamachine_log',
				'info',
				sprintf( 'Task batch complete: %s (%d items)', $task_type, $total ),
				array(
					'batch_id'     => $batchId,
					'batch_job_id' => $batch_job_id,
					'task_type'    => $task_type,
					'context'      => 'system',
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
	 * @param int $batchJobId Parent batch job ID.
	 * @return array|null Batch status or null if not found.
	 */
	public static function getBatchStatus( int $batchJobId ): ?array {
		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $batchJobId );

		if ( ! $job ) {
			return null;
		}

		$engine_data = $job['engine_data'] ?? array();

		if ( empty( $engine_data['batch'] ) ) {
			return null;
		}

		// Count child jobs by status via parent_job_id column.
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$child_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
					SUM(CASE WHEN status LIKE %s THEN 1 ELSE 0 END) as failed,
					SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
				FROM {$table}
				WHERE parent_job_id = %d",
				'failed%',
				$batchJobId
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

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
	 * @param int $batchJobId Parent batch job ID.
	 * @return bool True on success, false if not found or not a batch.
	 */
	public static function cancelBatch( int $batchJobId ): bool {
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
			sprintf( 'Task batch cancelled: job #%d (%s)', $batchJobId, $engine_data['task_type'] ?? '' ),
			array(
				'batch_job_id' => $batchJobId,
				'task_type'    => $engine_data['task_type'] ?? '',
				'context'      => 'system',
			)
		);

		return true;
	}

	/**
	 * Find all batch parent jobs.
	 *
	 * @return array Array of batch job records.
	 */
	public static function listBatches(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$results = $wpdb->get_results(
			"SELECT * FROM {$table}
			WHERE source = 'batch'
			ORDER BY created_at DESC
			LIMIT 50",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

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
}
