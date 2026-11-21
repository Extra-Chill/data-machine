<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionFilesTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_FILES;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: repository files',
			'description'     => 'Deletes old Data Machine repository files according to file_retention_days.',
			'setting_key'     => 'retention_files_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupOldFiles();
	}
}
