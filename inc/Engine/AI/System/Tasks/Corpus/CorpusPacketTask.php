<?php
/**
 * Generic corpus workflow packet task base.
 *
 * @package DataMachine\Engine\AI\System\Tasks\Corpus
 */

namespace DataMachine\Engine\AI\System\Tasks\Corpus;

use DataMachine\Core\JobStatus;
use DataMachine\Engine\AI\System\Tasks\SystemTask;

defined( 'ABSPATH' ) || exit;

abstract class CorpusPacketTask extends SystemTask {

	/**
	 * Corpus workflow operation represented by this task.
	 */
	abstract protected function getCorpusOperation(): string;

	/**
	 * Packet type emitted for each normalized item.
	 */
	abstract protected function getOutputPacketType(): string;

	/**
	 * Accepted parameter keys that may contain work items.
	 *
	 * @return array<int,string>
	 */
	abstract protected function getAcceptedItemKeys(): array;

	/**
	 * Execute the task by normalizing corpus work items into DataPackets.
	 *
	 * @param int                 $jobId  Job ID.
	 * @param array<string,mixed> $params Task params.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$input  = $this->normalizeInput( $params );
		$corpus = $this->normalizeCorpus( $input );
		$items  = $this->normalizeItems( $input );

		$packets = array();
		foreach ( $items as $item ) {
			$packets[] = $this->buildPacket( $corpus, $item );
		}

		$result = array(
			'output_data_packets'    => $packets,
			'replace_data_packets'   => ! empty( $input['replace_data_packets'] ),
			'suppress_result_packet' => ! empty( $input['suppress_result_packet'] ),
			'operation'              => $this->getCorpusOperation(),
			'corpus'                 => $corpus,
			'corpus_id'              => (string) ( $corpus['id'] ?? '' ),
			'corpus_ref'             => (string) ( $corpus['ref'] ?? '' ),
			'document_ids'           => $this->collectIdentifiers( $items, $this->getDocumentIdentifierKeys() ),
			'chunk_ids'              => $this->collectIdentifiers( $items, $this->getChunkIdentifierKeys() ),
			'item_count'             => count( $items ),
			'packet_count'           => count( $packets ),
			'completed_at'           => current_time( 'mysql' ),
		);

		if ( empty( $items ) ) {
			$result['job_status'] = JobStatus::COMPLETED_NO_ITEMS;
		}

		$this->completeJob( $jobId, $result );
	}

	/**
	 * Normalize the task input envelope.
	 *
	 * @param array<string,mixed> $params Raw task params.
	 * @return array<string,mixed>
	 */
	protected function normalizeInput( array $params ): array {
		return isset( $params['input'] ) && is_array( $params['input'] ) ? $params['input'] : $params;
	}

	/**
	 * Normalize optional corpus identifiers.
	 *
	 * @param array<string,mixed> $input Task input.
	 * @return array<string,mixed>
	 */
	protected function normalizeCorpus( array $input ): array {
		$corpus = isset( $input['corpus'] ) && is_array( $input['corpus'] ) ? $input['corpus'] : array();
		foreach (
			array(
				'id'    => 'corpus_id',
				'ref'   => 'corpus_ref',
				'key'   => 'corpus_key',
				'label' => 'corpus_label',
			) as $target => $source
		) {
			if ( ! array_key_exists( $target, $corpus ) && array_key_exists( $source, $input ) ) {
				$corpus[ $target ] = $input[ $source ];
			}
		}

		return $corpus;
	}

	/**
	 * Normalize accepted item keys into a flat list.
	 *
	 * @param array<string,mixed> $input Task input.
	 * @return array<int,array<string,mixed>>
	 */
	protected function normalizeItems( array $input ): array {
		foreach ( $this->getAcceptedItemKeys() as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];
			if ( is_array( $value ) && array_is_list( $value ) ) {
				return array_values( array_filter( $value, 'is_array' ) );
			}

			if ( is_array( $value ) ) {
				return array( $value );
			}
		}

		return array();
	}

	/**
	 * Build one output packet.
	 *
	 * @param array<string,mixed> $corpus Corpus identifiers.
	 * @param array<string,mixed> $item   Work item.
	 * @return array<string,mixed>
	 */
	protected function buildPacket( array $corpus, array $item ): array {
		$metadata = array(
			'source_type' => 'corpus_workflow',
			'task_type'   => $this->getTaskType(),
			'operation'   => $this->getCorpusOperation(),
			'corpus_id'   => (string) ( $corpus['id'] ?? '' ),
			'corpus_ref'  => (string) ( $corpus['ref'] ?? '' ),
			'document_id' => $this->firstIdentifier( $item, $this->getDocumentIdentifierKeys() ),
			'chunk_id'    => $this->firstIdentifier( $item, $this->getChunkIdentifierKeys() ),
			'success'     => true,
		);

		return array(
			'type'     => $this->getOutputPacketType(),
			'data'     => array(
				'operation' => $this->getCorpusOperation(),
				'corpus'    => $corpus,
				'item'      => $item,
			),
			'metadata' => $metadata,
		);
	}

	/**
	 * Candidate item keys that identify source documents.
	 *
	 * @return array<int,string>
	 */
	protected function getDocumentIdentifierKeys(): array {
		return array( 'document_id' );
	}

	/**
	 * Candidate item keys that identify chunks.
	 *
	 * @return array<int,string>
	 */
	protected function getChunkIdentifierKeys(): array {
		return array( 'chunk_id' );
	}

	/**
	 * Return the first non-empty identifier value for the requested keys.
	 *
	 * @param array<string,mixed> $item Work item.
	 * @param array<int,string>   $keys Candidate keys.
	 */
	private function firstIdentifier( array $item, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $item[ $key ] ) && '' !== (string) $item[ $key ] ) {
				return (string) $item[ $key ];
			}
		}

		return '';
	}

	/**
	 * Collect stable identifiers for job summaries.
	 *
	 * @param array<int,array<string,mixed>> $items Work items.
	 * @param array<int,string>              $keys  Candidate keys.
	 * @return array<int,string>
	 */
	private function collectIdentifiers( array $items, array $keys ): array {
		$ids = array();
		foreach ( $items as $item ) {
			foreach ( $keys as $key ) {
				if ( isset( $item[ $key ] ) && '' !== (string) $item[ $key ] ) {
					$ids[] = (string) $item[ $key ];
					break;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
