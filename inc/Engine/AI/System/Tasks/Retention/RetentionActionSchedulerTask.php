<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionActionSchedulerTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_AS_ACTIONS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: Action Scheduler actions',
			'description'     => 'Deletes old completed, failed, and canceled Action Scheduler actions and logs.',
			'setting_key'     => 'retention_as_actions_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupActionSchedulerActions();
	}
}
