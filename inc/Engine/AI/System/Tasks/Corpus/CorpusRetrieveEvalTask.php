<?php
/**
 * Generic corpus retrieval evaluation task.
 *
 * @package DataMachine\Engine\AI\System\Tasks\Corpus
 */

namespace DataMachine\Engine\AI\System\Tasks\Corpus;

defined( 'ABSPATH' ) || exit;

class CorpusRetrieveEvalTask extends CorpusPacketTask {

	public function getTaskType(): string {
		return 'corpus_retrieve_eval';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Corpus Retrieve Eval',
			'description'     => 'Normalize retrieval-evaluation cases into DataPackets for product-owned eval workflows.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On-demand via workflow system_task step',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
			'mutates'         => false,
			'params_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'corpus'  => array( 'type' => 'object' ),
					'queries' => array( 'type' => 'array' ),
					'query'   => array( 'type' => 'object' ),
					'evals'   => array( 'type' => 'array' ),
					'eval'    => array( 'type' => 'object' ),
				),
			),
		);
	}

	protected function getCorpusOperation(): string {
		return 'retrieve_eval';
	}

	protected function getOutputPacketType(): string {
		return 'corpus_retrieval_eval';
	}

	protected function getAcceptedItemKeys(): array {
		return array( 'queries', 'query', 'evals', 'eval', 'items', 'item' );
	}
}
