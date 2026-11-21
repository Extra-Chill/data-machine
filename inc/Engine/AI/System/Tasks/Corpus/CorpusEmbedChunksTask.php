<?php
/**
 * Generic corpus chunk embedding task.
 *
 * @package DataMachine\Engine\AI\System\Tasks\Corpus
 */

namespace DataMachine\Engine\AI\System\Tasks\Corpus;

defined( 'ABSPATH' ) || exit;

class CorpusEmbedChunksTask extends CorpusPacketTask {

	public function getTaskType(): string {
		return 'corpus_embed_chunks';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Corpus Embed Chunks',
			'description'     => 'Normalize corpus chunk work into DataPackets for product-owned embedding workflows.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On-demand via workflow system_task step',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
			'mutates'         => false,
			'params_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'corpus' => array( 'type' => 'object' ),
					'chunks' => array( 'type' => 'array' ),
					'chunk'  => array( 'type' => 'object' ),
				),
			),
		);
	}

	protected function getCorpusOperation(): string {
		return 'embed_chunks';
	}

	protected function getOutputPacketType(): string {
		return 'corpus_chunk';
	}

	protected function getAcceptedItemKeys(): array {
		return array( 'chunks', 'chunk', 'items', 'item' );
	}

	protected function getChunkIdentifierKeys(): array {
		return array( 'chunk_id', 'id' );
	}
}
