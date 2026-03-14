<?php
/**
 * System Agent - DEPRECATED.
 *
 * This class is a backward-compatible wrapper that delegates to
 * TaskRegistry and TaskScheduler. All new code should use those
 * classes directly.
 *
 * @package DataMachine\Engine\AI\System
 * @since 0.22.4
 * @deprecated 0.37.0 Use TaskRegistry and TaskScheduler instead.
 * @see \DataMachine\Engine\Tasks\TaskRegistry
 * @see \DataMachine\Engine\Tasks\TaskScheduler
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachine\Engine\Tasks\TaskScheduler;

/**
 * @deprecated 0.37.0 Use TaskRegistry and TaskScheduler directly.
 */
class SystemAgent {

	/**
	 * Singleton instance.
	 *
	 * @var SystemAgent|null
	 */
	private static ?SystemAgent $instance = null;

	/**
	 * Private constructor for singleton pattern.
	 *
	 * @deprecated 0.37.0
	 */
	private function __construct() {
		TaskRegistry::load();
	}

	/**
	 * Get singleton instance.
	 *
	 * @deprecated 0.37.0 Use TaskRegistry and TaskScheduler static methods directly.
	 * @return SystemAgent
	 */
	public static function getInstance(): SystemAgent {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Default chunk size for batch scheduling.
	 *
	 * @deprecated 0.37.0 Use TaskScheduler::BATCH_CHUNK_SIZE instead.
	 */
	const BATCH_CHUNK_SIZE = 10;

	/**
	 * Schedule an async task.
	 *
	 * @deprecated 0.37.0 Use TaskScheduler::schedule() instead.
	 *
	 * @param string $taskType    Task type identifier.
	 * @param array  $params      Task parameters to store in engine_data.
	 * @param array  $context     Context for routing results back.
	 * @param int    $parentJobId Parent job ID for hierarchy.
	 * @return int|false Job ID on success, false on failure.
	 */
	public function scheduleTask( string $taskType, array $params, array $context = array(), int $parentJobId = 0 ): int|false {
		return TaskScheduler::schedule( $taskType, $params, $context, $parentJobId );
	}

	/**
	 * Schedule a batch of tasks with chunked execution.
	 *
	 * @deprecated 0.37.0 Use TaskScheduler::scheduleBatch() instead.
	 *
	 * @param string $taskType   Task type identifier.
	 * @param array  $itemParams Array of parameter arrays, one per task.
	 * @param array  $context    Shared context for all tasks in the batch.
	 * @param int    $chunkSize  Items per chunk.
	 * @return array|false Batch info or false on failure.
	 */
	public function scheduleBatch( string $taskType, array $itemParams, array $context = array(), int $chunkSize = 0 ): array|false {
		return TaskScheduler::scheduleBatch( $taskType, $itemParams, $context, $chunkSize );
	}

	/**
	 * Process a batch chunk (Action Scheduler callback).
	 *
	 * @deprecated 0.37.0 Use TaskScheduler::processBatchChunk() instead.
	 *
	 * @param string $batchId Batch identifier.
	 */
	public function processBatchChunk( string $batchId ): void {
		TaskScheduler::processBatchChunk( $batchId );
	}

	/**
	 * Handle a scheduled task (Action Scheduler callback).
	 *
	 * @deprecated 0.37.0 Use TaskScheduler::handleTask() instead.
	 *
	 * @param int $jobId Job ID from DM Jobs table.
	 */
	public function handleTask( int $jobId ): void {
		TaskScheduler::handleTask( $jobId );
	}

	/**
	 * Get the status of a batch by its parent job ID.
	 *
	 * @deprecated 0.37.0 Use TaskScheduler::getBatchStatus() instead.
	 *
	 * @param int $batchJobId Parent batch job ID.
	 * @return array|null Batch status or null if not found.
	 */
	public function getBatchStatus( int $batchJobId ): ?array {
		return TaskScheduler::getBatchStatus( $batchJobId );
	}

	/**
	 * Cancel a running batch.
	 *
	 * @deprecated 0.37.0 Use TaskScheduler::cancelBatch() instead.
	 *
	 * @param int $batchJobId Parent batch job ID.
	 * @return bool True on success, false if not found or not a batch.
	 */
	public function cancelBatch( int $batchJobId ): bool {
		return TaskScheduler::cancelBatch( $batchJobId );
	}

	/**
	 * Find all batch parent jobs.
	 *
	 * @deprecated 0.37.0 Use TaskScheduler::listBatches() instead.
	 *
	 * @return array Array of batch job records.
	 */
	public function listBatches(): array {
		return TaskScheduler::listBatches();
	}

	/**
	 * Get registered task handlers.
	 *
	 * @deprecated 0.37.0 Use TaskRegistry::getHandlers() instead.
	 *
	 * @return array<string, string> Task type => handler class mappings.
	 */
	public function getTaskHandlers(): array {
		return TaskRegistry::getHandlers();
	}

	/**
	 * Get the full task registry with metadata for the admin UI.
	 *
	 * @deprecated 0.37.0 Use TaskRegistry::getRegistry() instead.
	 *
	 * @return array<string, array> Task type => metadata array.
	 */
	public function getTaskRegistry(): array {
		return TaskRegistry::getRegistry();
	}
}
