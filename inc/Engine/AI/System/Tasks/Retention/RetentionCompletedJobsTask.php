<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionCompletedJobsTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_COMPLETED_JOBS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: completed jobs',
			'description'     => 'Deletes completed job records older than the configured retention window.',
			'setting_key'     => 'retention_completed_jobs_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupCompletedJobs();
	}
}
