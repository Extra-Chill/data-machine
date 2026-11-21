<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionBatchWorklistsTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_BATCH_WORKLISTS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: orphaned batch worklists',
			'description'     => 'Deletes batch worklist rows whose parent job no longer exists.',
			'setting_key'     => 'retention_batch_worklists_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupBatchWorklists();
	}
}
