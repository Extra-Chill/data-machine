<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionPacketStoreTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_PACKET_STORE;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: DataPacket store',
			'description'     => 'Deletes content-addressed DataPacket files no live job references once they outlive the longest job retention window. Bounded and resumable per run.',
			'setting_key'     => 'retention_packet_store_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupPacketStore();
	}
}
