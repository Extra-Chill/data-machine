<?php
/**
 * Corpus refresh processed-item and batch metadata conventions.
 *
 * @package DataMachine\Core\Corpus
 */

namespace DataMachine\Core\Corpus;

defined( 'ABSPATH' ) || exit;

/**
 * Shared key and metadata shapes for corpus-style refresh workloads.
 */
class CorpusRefreshConventions {

	public const SOURCE_DOCUMENT  = 'corpus_document_revision';
	public const SOURCE_CHUNK     = 'corpus_chunk_revision';
	public const SOURCE_EMBEDDING = 'corpus_embedding_revision';

	/**
	 * Build a processed-item identifier for a source document revision.
	 *
	 * @param string $corpus_id   Stable corpus ID.
	 * @param string $document_id Stable document ID inside the corpus.
	 * @param string $source_hash Hash of the source document payload/revision.
	 * @return string Processed-item identifier.
	 */
	public static function document_key( string $corpus_id, string $document_id, string $source_hash ): string {
		return self::key(
			'doc',
			array(
				'corpus'   => $corpus_id,
				'document' => $document_id,
				'hash'     => $source_hash,
			)
		);
	}

	/**
	 * Build a processed-item identifier for a chunk revision.
	 *
	 * @param string $corpus_id  Stable corpus ID.
	 * @param string $chunk_id   Stable chunk ID inside the corpus.
	 * @param string $chunk_hash Hash of the chunk text/content.
	 * @return string Processed-item identifier.
	 */
	public static function chunk_key( string $corpus_id, string $chunk_id, string $chunk_hash ): string {
		return self::key(
			'chunk',
			array(
				'corpus' => $corpus_id,
				'chunk'  => $chunk_id,
				'hash'   => $chunk_hash,
			)
		);
	}

	/**
	 * Build a processed-item identifier for an embedding revision.
	 *
	 * @param string $corpus_id       Stable corpus ID.
	 * @param string $provider        Embedding provider ID.
	 * @param string $embedding_model Embedding model ID.
	 * @param string $chunk_hash      Hash of the chunk text/content.
	 * @return string Processed-item identifier.
	 */
	public static function embedding_key( string $corpus_id, string $provider, string $embedding_model, string $chunk_hash ): string {
		return self::key(
			'embedding',
			array(
				'corpus'   => $corpus_id,
				'provider' => $provider,
				'model'    => $embedding_model,
				'hash'     => $chunk_hash,
			)
		);
	}

	/**
	 * Build normalized batch metadata for corpus refresh jobs.
	 *
	 * @param array $metadata Batch metadata and count overrides.
	 * @return array Normalized metadata.
	 */
	public static function batch_metadata( array $metadata = array() ): array {
		$counts = is_array( $metadata['counts'] ?? null ) ? $metadata['counts'] : array();
		foreach ( array( 'selected', 'skipped', 'processed', 'failed', 'retried' ) as $key ) {
			$counts[ $key ] = max( 0, (int) ( $counts[ $key ] ?? ( $metadata[ $key ] ?? 0 ) ) );
		}

		$out = array(
			'workload' => 'corpus_refresh',
			'counts'   => $counts,
		);

		foreach ( array( 'corpus_id', 'batch_id', 'refresh_id', 'source_type' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) && '' !== (string) $metadata[ $key ] ) {
				$out[ $key ] = (string) $metadata[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Build a stable string identifier from ordered scalar key parts.
	 *
	 * @param string $kind Key kind prefix.
	 * @param array  $parts Ordered key parts.
	 * @return string Stable key.
	 */
	private static function key( string $kind, array $parts ): string {
		$segments = array( 'corpus', $kind );
		foreach ( $parts as $name => $value ) {
			$segments[] = self::clean_segment( (string) $name ) . '=' . self::clean_segment( (string) $value );
		}

		return implode( '|', $segments );
	}

	/**
	 * Normalize a key segment without losing hash/model delimiters that help humans debug.
	 *
	 * @param string $value Raw segment.
	 * @return string Normalized segment.
	 */
	private static function clean_segment( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9._:\/-]+/', '-', $value ) ?? '';

		return trim( $value, '-|' );
	}
}
