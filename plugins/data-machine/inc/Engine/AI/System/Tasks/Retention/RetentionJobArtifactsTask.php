<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionJobArtifactsTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_JOB_ARTIFACTS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: job artifacts',
			'description'     => 'Deletes job artifact files (tool traces, transcripts, scoped artifacts) older than their retention window, in bounded resumable batches.',
			'setting_key'     => 'retention_job_artifacts_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupJobArtifacts();
	}
}
