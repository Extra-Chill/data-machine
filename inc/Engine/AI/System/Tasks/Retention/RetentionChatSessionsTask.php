<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionChatSessionsTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_CHAT_SESSIONS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: chat sessions',
			'description'     => 'Deletes old chat sessions and pipeline transcripts.',
			'setting_key'     => 'retention_chat_sessions_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupChatSessions();
	}
}
