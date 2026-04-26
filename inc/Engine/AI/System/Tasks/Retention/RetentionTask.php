<?php
/**
 * Base class for retention SystemTasks.
 *
 * @package DataMachine\Engine\AI\System\Tasks\Retention
 * @since TBD
 */

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

abstract class RetentionTask extends SystemTask {

	final public function executeTask( int $jobId, array $params ): void {
		try {
			$result = $this->runRetentionCleanup();
			$this->completeJob(
				$jobId,
				array(
					'retention' => array_merge(
						array( 'task_type' => $this->getTaskType() ),
						$result
					),
				)
			);
		} catch ( \Throwable $e ) {
			$this->failJob( $jobId, $e->getMessage() );
		}
	}

	abstract protected function runRetentionCleanup(): array;
}
