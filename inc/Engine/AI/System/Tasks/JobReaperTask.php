<?php
/**
 * Job reaper system task.
 *
 * Recurring (every 15 minutes, per site) caller of the recover-stuck-jobs
 * ability over pending + processing scope. All behavior lives in
 * {@see JobReaper}; this class is the system-task seam. Dry-run by default:
 * see the `job_reaper_mode` setting.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.180.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Jobs\JobReaper;

class JobReaperTask extends SystemTask {

	public const TASK_TYPE = 'job_reaper';

	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	/**
	 * Pure internal maintenance: no agent or user identity is needed.
	 */
	public function requiresAgentContext(): bool {
		return false;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'            => 'Job reaper',
			'description'      => 'Recovers pending and processing jobs that no live scheduler path can advance. Dry-run by default; set job_reaper_mode to apply, or off.',
			'setting_key'      => null,
			'default_enabled'  => true,
			'supports_run'     => true,
			'mutates'          => true,
			'supports_dry_run' => false,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		try {
			$run = JobReaper::run();
			$this->completeJob( $jobId, array( 'job_reaper' => $this->summary( $run ) ) );
		} catch ( \Throwable $e ) {
			$this->failJob( $jobId, $e->getMessage() );
		}
	}

	/**
	 * Compact per-run outcome stored on the task's own job row.
	 *
	 * @param array<string,mixed> $run Recorded run from JobReaper::run().
	 * @return array<string,mixed>
	 */
	private function summary( array $run ): array {
		if ( JobReaper::MODE_OFF === ( $run['mode'] ?? '' ) ) {
			return array(
				'mode' => JobReaper::MODE_OFF,
				'ran'  => false,
			);
		}

		return array(
			'mode'      => (string) ( $run['mode'] ?? '' ),
			'ran'       => true,
			'success'   => ! empty( $run['success'] ),
			'error'     => (string) ( $run['error'] ?? '' ),
			'would_act' => (int) ( $run['would_act'] ?? 0 ),
			'acted'     => (int) ( $run['acted'] ?? 0 ),
			'guarded'   => (int) ( $run['guarded'] ?? 0 ),
			'verdicts'  => is_array( $run['verdicts'] ?? null ) ? $run['verdicts'] : array(),
		);
	}
}
