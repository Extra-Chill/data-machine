<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionLogsTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_LOGS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: pipeline logs',
			'description'     => 'Prunes old Data Machine log rows.',
			'setting_key'     => 'retention_logs_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupLogs();
	}
}
