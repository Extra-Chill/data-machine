<?php
/**
 * Stale claim reconciliation SystemTask.
 *
 * Recurring engine task that finds `processing` jobs whose claim has no
 * live Action Scheduler action and whose activity is stale — crashed runs
 * that would otherwise orphan until an operator runs
 * `wp datamachine jobs recover-stuck` — and resumes them from their last
 * completed step or terminalizes them with an explicit reason.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since TBD
 */

namespace DataMachine\Engine\AI\System\Tasks;

use DataMachine\Core\StaleClaimReconciler;

defined( 'ABSPATH' ) || exit;

class StaleClaimReconciliationTask extends SystemTask {

	public function requiresAgentContext(): bool {
		return false;
	}

	public function getTaskType(): string {
		return 'stale_claim_reconciliation';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Stale job-claim reconciliation',
			'description'     => 'Detects processing jobs with no live scheduler action and stale activity (crashed runs), resuming them from their last completed step or terminalizing them with an explicit reason.',
			'setting_key'     => 'stale_claim_reconciliation_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		try {
			$result = ( new StaleClaimReconciler() )->reconcile();
			$this->completeJob(
				$jobId,
				array(
					'reconciliation' => array_merge(
						array( 'task_type' => $this->getTaskType() ),
						$result
					),
				)
			);
		} catch ( \Throwable $e ) {
			$this->failJob( $jobId, $e->getMessage() );
		}
	}
}
