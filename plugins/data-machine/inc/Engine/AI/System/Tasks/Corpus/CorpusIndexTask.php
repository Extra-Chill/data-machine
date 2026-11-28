<?php
/**
 * Generic corpus index task.
 *
 * @package DataMachine\Engine\AI\System\Tasks\Corpus
 */

namespace DataMachine\Engine\AI\System\Tasks\Corpus;

defined( 'ABSPATH' ) || exit;

class CorpusIndexTask extends CorpusPacketTask {

	public function getTaskType(): string {
		return 'corpus_index';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Corpus Index',
			'description'     => 'Normalize corpus document work into DataPackets for product-owned indexing workflows.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On-demand via workflow system_task step',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
			'mutates'         => false,
			'params_schema'   => self::documentParamsSchema(),
		);
	}

	protected function getCorpusOperation(): string {
		return 'index';
	}

	protected function getOutputPacketType(): string {
		return 'corpus_document';
	}

	protected function getAcceptedItemKeys(): array {
		return array( 'documents', 'document', 'items', 'item' );
	}

	protected function getDocumentIdentifierKeys(): array {
		return array( 'document_id', 'id' );
	}

	protected static function documentParamsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'corpus'    => array( 'type' => 'object' ),
				'documents' => array( 'type' => 'array' ),
				'document'  => array( 'type' => 'object' ),
			),
		);
	}
}
