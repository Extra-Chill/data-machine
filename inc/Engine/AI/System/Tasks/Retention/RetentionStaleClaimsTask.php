<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionStaleClaimsTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_STALE_CLAIMS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: stale Action Scheduler claims',
			'description'     => 'Deletes orphaned Action Scheduler claims older than the configured age.',
			'setting_key'     => 'retention_stale_claims_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupStaleClaims();
	}
}
