<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionReclaimSpaceTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_RECLAIM_SPACE;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: reclaim table space',
			'description'     => 'Rebuilds owned InnoDB tables whose retention-freed space crosses both the free-bytes and free-ratio thresholds.',
			'setting_key'     => 'retention_reclaim_space_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::reclaimTableSpace();
	}
}
