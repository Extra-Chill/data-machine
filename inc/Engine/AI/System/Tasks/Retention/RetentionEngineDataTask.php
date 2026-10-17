<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionEngineDataTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_ENGINE_DATA;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: terminal-job engine_data',
			'description'     => 'Sheds the heavy engine_data working-state blob from terminal jobs shortly after completion, keeping the job ledger row.',
			'setting_key'     => 'retention_engine_data_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupEngineData();
	}
}
