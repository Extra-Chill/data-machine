<?php
/**
 * Generic corpus refresh task.
 *
 * @package DataMachine\Engine\AI\System\Tasks\Corpus
 */

namespace DataMachine\Engine\AI\System\Tasks\Corpus;

defined( 'ABSPATH' ) || exit;

class CorpusRefreshTask extends CorpusIndexTask {

	public function getTaskType(): string {
		return 'corpus_refresh';
	}

	public static function getTaskMeta(): array {
		$meta                = parent::getTaskMeta();
		$meta['label']       = 'Corpus Refresh';
		$meta['description'] = 'Normalize changed corpus document work into DataPackets for product-owned refresh workflows.';
		return $meta;
	}

	protected function getCorpusOperation(): string {
		return 'refresh';
	}
}
