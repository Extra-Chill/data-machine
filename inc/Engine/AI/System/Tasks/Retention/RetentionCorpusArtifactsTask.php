<?php

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

class RetentionCorpusArtifactsTask extends RetentionTask {

	public function getTaskType(): string {
		return RetentionCleanup::TASK_CORPUS_ARTIFACTS;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Retention: corpus indexing artifacts',
			'description'     => 'Deletes corpus indexing artifact files older than the configured retention window.',
			'setting_key'     => 'retention_corpus_artifacts_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	protected function runRetentionCleanup(): array {
		return RetentionCleanup::cleanupCorpusArtifacts();
	}
}
