<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionProcessedItemsTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_PROCESSED_ITEMS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: processed items',
			'description'     => 'Deletes old processed-item deduplication records.',
			'setting_key'     => 'retention_processed_items_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupProcessedItems();
	}
}
