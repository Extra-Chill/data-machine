<?php
/**
 * Task Scheduler — Routes system tasks through the workflow engine.
 *
 * All task scheduling delegates to datamachine/execute-workflow. The task's
 * getWorkflow() method provides the step list; the engine handles job
 * creation, Action Scheduler dispatch, and step execution.
 *
 * Batch fan-out delegates the chunking loop to BatchScheduler — same
 * primitive that powers pipeline fan-out. State lives on the parent batch
 * job's engine_data (Redis-survivable), not transients.
 *
 * @package DataMachine\Engine\Tasks
 * @since 0.37.0
 * @since 0.72.0 Delegates to datamachine/execute-workflow; handleTask() removed.
 * @since 0.82.0 Chunking loop extracted to BatchScheduler; transient state replaced
 *               with engine_data-only persistence.
 */

namespace DataMachine\Engine\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

class TaskScheduler {

	/**
	 * Action Scheduler hook for processing batch chunks.
	 */
	public const BATCH_HOOK = 'datamachine_task_process_batch';

	/**
	 * Consumer context, passed to BatchScheduler so chunk_size /
	 * chunk_delay filter consumers can tell system-task fan-out apart
	 * from pipeline fan-out.
	 */
	public const BATCH_CONTEXT = 'task';

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

		// Resolve agent identity from the context. Callers without
		// agent_id/user_id continue to work — the resulting job runs
		// without an agent (matching pre-multi-agent behaviour).
		$context_user_id  = (int) ( $context['user_id'] ?? 0 );
		$context_agent_id = (int) ( $context['agent_id'] ?? 0 );

		// Mirror RunFlowAbility's engine_data['job'] shape so downstream
		// step types (AIStep, SystemTaskStep) can read agent identity
		// from the engine snapshot the same way they do for flow jobs.
		$job_snapshot = array(
			'user_id' => $context_user_id,
		);
		if ( $context_agent_id > 0 ) {
			$job_snapshot['agent_id'] = $context_agent_id;
		}

		$result = $ability->execute( array(
			'workflow'     => $workflow,
			'timestamp'    => $params['scheduled_at'] ?? null,
			'initial_data' => array(
				'task_type'     => $taskType,
				'task_params'   => $params,
				'task_context'  => $context,
				'parent_job_id' => $parentJobId,
				'user_id'       => $context_user_id,
				'agent_id'      => $context_agent_id,
				'job'           => $job_snapshot,
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
	 * Creates a parent batch job, hands the work list to BatchScheduler,
	 * and returns identifiers callers can use to query / cancel the batch.
	 * Each item later becomes its own standalone workflow job via schedule().
	 *
	 * Per-call chunk-size override is no longer accepted — chunking is
	 * controlled by the queue_tuning settings group (settable globally
	 * via Settings → General → Queue Performance, and overridable per
	 * context via the datamachine_batch_chunk_size filter).
	 *
	 * @param string $taskType   Task type identifier (must be registered).
	 * @param array  $itemParams Array of parameter arrays, one per task.
	 * @param array  $context    Shared context for all tasks in the batch.
	 * @return array{batch_id:string,batch_job_id:int,total:int,scheduled:int,chunk_size:int,job_ids?:array}|false Batch info or false on failure.
	 */
	public static function scheduleBatch( string $taskType, array $itemParams, array $context = array() ): array|false {
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

		$chunk_size = BatchScheduler::chunkSize( self::BATCH_CONTEXT );

		// Caller-supplied parent_job_id (e.g. a fan-out system task that
		// scheduled this batch) — children stamp this so the originating
		// job can walk them via Jobs::get_children for undo / status.
		$caller_parent_job_id = isset( $context['parent_job_id'] ) ? (int) $context['parent_job_id'] : 0;

		// Small batches: schedule directly, no batch overhead.
		if ( count( $itemParams ) <= $chunk_size ) {
			$job_ids = array();
			foreach ( $itemParams as $params ) {
				$job_id = self::schedule( $taskType, $params, $context, $caller_parent_job_id );
				if ( $job_id ) {
					$job_ids[] = $job_id;
				}
			}
			return array(
				'batch_id'     => 'direct',
				'batch_job_id' => 0,
				'total'        => count( $itemParams ),
				'scheduled'    => count( $job_ids ),
				'chunk_size'   => $chunk_size,
				'job_ids'      => $job_ids,
			);
		}

		// Create parent batch job for persistent tracking. When the
		// caller passed a parent_job_id, link this batch parent to it
		// so the chain caller → batch_parent is preserved even though
		// per-item children chain off the caller directly (below).
		$jobs_db          = new Jobs();
		$batch_id         = 'dm_batch_' . wp_generate_uuid4();
		$batch_create_args = array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'batch',
			'label'       => 'Batch: ' . ucfirst( str_replace( '_', ' ', $taskType ) ),
		);
		if ( $caller_parent_job_id > 0 ) {
			$batch_create_args['parent_job_id'] = $caller_parent_job_id;
		}
		$batch_job_id = $jobs_db->create_job( $batch_create_args );

		if ( ! $batch_job_id ) {
			do_action(
				'datamachine_log',
				'error',
				'TaskScheduler: failed to create batch parent job',
				array( 'task_type' => $taskType, 'total' => count( $itemParams ) )
			);
			return false;
		}

		$jobs_db->start_job( (int) $batch_job_id, JobStatus::PROCESSING );

		// Hand the work list to the shared chunking primitive. State
		// lives on this parent job's engine_data — no transients, no
		// eviction risk. The chunk extras include the caller's
		// parent_job_id (when present) so processBatchChunk can route
		// it through to per-item children — caller intent wins over
		// the batch parent for the per-item linkage.
		$result = BatchScheduler::start(
			(int) $batch_job_id,
			self::BATCH_HOOK,
			$itemParams,
			array(
				'task_type'            => $taskType,
				'context'              => $context,
				'batch_id'             => $batch_id,
				'caller_parent_job_id' => $caller_parent_job_id,
			),
			self::BATCH_CONTEXT,
			BatchScheduler::COMPLETION_STRATEGY_CHUNKS_SCHEDULED
		);

		// Surface task-specific identifiers alongside the BatchScheduler
		// metadata so getBatchStatus() and CLI consumers see the same
		// fields they read pre-extraction.
		$batch_engine_merge = array(
			'task_type' => $taskType,
			'batch_id'  => $batch_id,
		);
		if ( $caller_parent_job_id > 0 ) {
			$batch_engine_merge['caller_parent_job_id'] = $caller_parent_job_id;
		}
		datamachine_merge_engine_data( (int) $batch_job_id, $batch_engine_merge );

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Task batch scheduled: %s (%d items in chunks of %d)',
				$taskType,
				count( $itemParams ),
				$chunk_size
			),
			array(
				'batch_id'     => $batch_id,
				'batch_job_id' => $batch_job_id,
				'task_type'    => $taskType,
				'context'      => 'system',
				'total'        => count( $itemParams ),
				'chunk_size'   => $chunk_size,
			)
		);

		return array(
			'batch_id'     => $batch_id,
			'batch_job_id' => (int) $batch_job_id,
			'total'        => count( $itemParams ),
			'scheduled'    => 0, // Actual scheduling happens in chunks.
			'chunk_size'   => $chunk_size,
		);
	}

	/**
	 * Process a batch chunk (Action Scheduler callback).
	 *
	 * Pre-0.82.0 this was called with a string $batchId and looked up
	 * state in a transient. The new BatchScheduler pipes the parent job
	 * ID instead — the state lives on its engine_data. The legacy
	 * string-key path is retained for one cycle so any in-flight
	 * Action Scheduler entries still resolve.
	 *
	 * @param int|string $parentJobIdOrBatchId Parent job ID (current) or
	 *                                         legacy batch ID (pre-0.82.0).
	 */
	public static function processBatchChunk( int|string $parentJobIdOrBatchId ): void {
		$parent_job_id = self::resolveParentJobId( $parentJobIdOrBatchId );

		if ( $parent_job_id <= 0 ) {
			do_action(
				'datamachine_log',
				'warning',
				'TaskScheduler: Batch parent not resolvable',
				array( 'arg' => $parentJobIdOrBatchId, 'context' => 'system' )
			);
			return;
		}

		$result = BatchScheduler::processChunk(
			$parent_job_id,
			static function ( array $params, array $extra, int $parent_id ): int|false {
				$task_type = (string) ( $extra['task_type'] ?? '' );
				$context   = is_array( $extra['context'] ?? null ) ? $extra['context'] : array();

				if ( '' === $task_type ) {
					return false;
				}

				// When the original caller passed parent_job_id in
				// $context, per-item children chain to it (caller
				// intent wins). Otherwise they chain to the batch
				// parent for grouping.
				$caller_parent_job_id = (int) ( $extra['caller_parent_job_id'] ?? 0 );
				$child_parent_id      = $caller_parent_job_id > 0 ? $caller_parent_job_id : $parent_id;

				return self::schedule( $task_type, $params, $context, $child_parent_id );
			}
		);

		$jobs_db       = new Jobs();
		$parent_engine = datamachine_get_engine_data( $parent_job_id );
		$task_type     = (string) ( $parent_engine['task_type'] ?? '' );
		$batch_id      = (string) ( $parent_engine['batch_id'] ?? '' );

		if ( $result['missing'] ) {
			do_action(
				'datamachine_log',
				'error',
				'TaskScheduler: batch state missing from engine_data',
				array(
					'parent_job_id' => $parent_job_id,
					'context'       => 'system',
				)
			);
			$jobs_db->complete_job(
				$parent_job_id,
				JobStatus::failed( 'batch_state_missing' )->toString()
			);
			return;
		}

		if ( $result['cancelled'] ) {
			$jobs_db->complete_job( $parent_job_id, 'cancelled' );

			do_action(
				'datamachine_log',
				'info',
				sprintf( 'Task batch cancelled: %s (at %d/%d)', $task_type, $result['offset'], $result['total'] ),
				array(
					'batch_id'     => $batch_id,
					'batch_job_id' => $parent_job_id,
					'task_type'    => $task_type,
					'context'      => 'system',
					'offset'       => $result['offset'],
					'total'        => $result['total'],
				)
			);
			return;
		}

		// Surface progress fields on the parent's engine_data using the
		// keys CLI / status consumers already know about.
		datamachine_merge_engine_data(
			$parent_job_id,
			array(
				'offset'          => $result['offset'],
				'tasks_scheduled' => ( $parent_engine['tasks_scheduled'] ?? 0 ) + $result['scheduled'],
			)
		);

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Task batch chunk processed: %s (%d/%d, scheduled %d)',
				$task_type,
				$result['offset'],
				$result['total'],
				$result['scheduled']
			),
			array(
				'batch_id'     => $batch_id,
				'batch_job_id' => $parent_job_id,
				'task_type'    => $task_type,
				'context'      => 'system',
				'offset'       => $result['offset'],
				'scheduled'    => $result['scheduled'],
				'remaining'    => max( 0, $result['total'] - $result['offset'] ),
			)
		);

		if ( ! $result['more'] ) {
			datamachine_merge_engine_data(
				$parent_job_id,
				array( 'completed_at' => current_time( 'mysql' ) )
			);
			$jobs_db->complete_job( $parent_job_id, JobStatus::COMPLETED );

			do_action(
				'datamachine_log',
				'info',
				sprintf( 'Task batch complete: %s (%d items)', $task_type, $result['total'] ),
				array(
					'batch_id'     => $batch_id,
					'batch_job_id' => $parent_job_id,
					'task_type'    => $task_type,
					'context'      => 'system',
					'total'        => $result['total'],
				)
			);
		}
	}

	/**
	 * Resolve the parent job ID for a chunk callback argument.
	 *
	 * BatchScheduler always passes the parent job ID. Legacy in-flight
	 * Action Scheduler entries (scheduled before 0.82.0) carry the
	 * string batch ID and are resolved by looking up the parent job
	 * whose engine_data['batch_id'] matches.
	 *
	 * @param int|string $arg Parent job ID or legacy batch ID string.
	 * @return int Parent job ID, or 0 when unresolvable.
	 */
	private static function resolveParentJobId( int|string $arg ): int {
		if ( is_int( $arg ) ) {
			return $arg;
		}

		// Numeric string from Action Scheduler — coerce.
		if ( ctype_digit( $arg ) ) {
			return (int) $arg;
		}

		// Legacy batch_id path. Look the parent up by engine_data field.
		// Only invoked for in-flight pre-migration jobs; new batches use
		// the int parent_job_id path.
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT job_id FROM {$table}
				WHERE source = 'batch'
				  AND engine_data LIKE %s
				ORDER BY job_id DESC
				LIMIT 1",
				'%' . $wpdb->esc_like( $arg ) . '%'
			)
		);
		// phpcs:enable

		return $row ? (int) $row : 0;
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

		// Pull total from batch_total (BatchScheduler) with fallback to
		// the legacy `total` key for any pre-migration rows.
		$total = (int) ( $engine_data['batch_total'] ?? $engine_data['total'] ?? 0 );

		return array(
			'batch_job_id'        => $batchJobId,
			'task_type'           => $engine_data['task_type'] ?? '',
			'completion_strategy' => $engine_data['batch_completion_strategy'] ?? '',
			'total_items'         => $total,
			'offset'              => $engine_data['offset'] ?? $engine_data['batch_offset'] ?? 0,
			'chunk_size'          => $engine_data['batch_chunk_size'] ?? BatchScheduler::DEFAULT_CHUNK_SIZE,
			'tasks_scheduled'     => $engine_data['tasks_scheduled'] ?? $engine_data['batch_scheduled'] ?? 0,
			'status'              => $job['status'] ?? '',
			'started_at'          => $engine_data['started_at'] ?? '',
			'completed_at'        => $engine_data['completed_at'] ?? '',
			'cancelled'           => ! empty( $engine_data['cancelled'] ),
			'child_jobs'          => array(
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
	 * Sets the cancelled flag on the parent batch job's engine_data.
	 * The next chunk callback sees it and stops creating children.
	 *
	 * @param int $batchJobId Parent batch job ID.
	 * @return bool True on success, false if not found or not a batch.
	 */
	public static function cancelBatch( int $batchJobId ): bool {
		$cancelled = BatchScheduler::cancel( $batchJobId );

		if ( ! $cancelled ) {
			return false;
		}

		$engine_data = datamachine_get_engine_data( $batchJobId );

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
