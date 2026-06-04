<?php
/**
 * Generic corpus-style job summary and artifact conventions.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class CorpusJobSurfaces {

	public const WORKLOAD_TYPE   = 'corpus_indexing';
	public const RETENTION_SCOPE = 'corpus_indexing';

	private const SUMMARY_SCHEMA_VERSION  = 1;
	private const ARTIFACT_SCHEMA_VERSION = 1;

	private const CORPUS_COUNT_KEYS = array(
		'items_total',
		'items_seen',
		'items_indexed',
		'items_unchanged',
		'items_skipped',
		'extraction_failures',
		'chunks_created',
		'chunking_failures',
		'embeddings_created',
		'embedding_failures',
		'retrieval_evaluations',
	);

	private const CORPUS_ARTIFACT_TYPES = array(
		'extraction_failures',
		'chunking_summary',
		'embedding_failures',
		'retrieval_evaluation',
		'skipped_items',
		'unchanged_items',
	);

	/**
	 * Normalize a concise operator-facing summary for corpus-style jobs.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @param array<string,mixed> $engine_data Job engine data.
	 * @return array<string,mixed>
	 */
	public static function summary( array $job, array $engine_data ): array {
		$summary = is_array( $engine_data['job_summary'] ?? null ) ? $engine_data['job_summary'] : array();
		$corpus  = self::corpusData( $engine_data );

		if ( empty( $summary ) && empty( $corpus ) ) {
			return array();
		}

		$metrics = is_array( $engine_data['run_metrics'] ?? null ) ? RunMetrics::normalize( $engine_data['run_metrics'] ) : array();
		$counts  = self::countsFrom( $summary, $corpus, $metrics['counts'] ?? array() );

		return self::filterEmpty(
			array(
				'schema_version' => self::SUMMARY_SCHEMA_VERSION,
				'workload_type'  => self::WORKLOAD_TYPE,
				'headline'       => self::boundedText( $summary['headline'] ?? ( $summary['summary'] ?? ( $corpus['headline'] ?? ( $corpus['summary'] ?? '' ) ) ), 280 ),
				'status'         => isset( $job['status'] ) ? (string) $job['status'] : null,
				'counts'         => $counts,
				'artifact_refs'  => self::artifactRefs( $engine_data ),
				'notes'          => self::boundedText( $summary['notes'] ?? ( $corpus['notes'] ?? '' ), 1000 ),
				'updated_at'     => self::text( $summary['updated_at'] ?? ( $corpus['updated_at'] ?? '' ) ),
			)
		);
	}

	/**
	 * Normalize artifact references recorded by corpus-style workloads.
	 *
	 * @param array<string,mixed> $engine_data Job engine data.
	 * @return array<string,array<string,mixed>>
	 */
	public static function artifactRefs( array $engine_data ): array {
		$refs = array();
		foreach ( self::artifactSources( $engine_data ) as $artifact_key => $artifact ) {
			$normalized = self::normalizeArtifact( $artifact_key, $artifact );
			if ( empty( $normalized ) ) {
				continue;
			}

			$refs[ $normalized['artifact_key'] ] = $normalized;
		}

		ksort( $refs, SORT_STRING );
		return $refs;
	}

	/**
	 * Return artifact retention policy definitions for corpus indexing surfaces.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function retentionPolicies(): array {
		$max_age_days = self::positiveDays( self::filter( 'datamachine_corpus_artifacts_max_age_days', 30 ), 30 );
		$policies     = array(
			self::RETENTION_SCOPE => array(
				'label'           => 'Corpus indexing artifacts',
				'retention_scope' => self::RETENTION_SCOPE,
				'artifact_types'  => self::corpusArtifactTypes(),
				'max_age_days'    => $max_age_days,
				'filter'          => 'datamachine_corpus_artifacts_max_age_days',
			),
		);

		$policies = self::filter( 'datamachine_job_artifact_retention_policies', $policies );
		return is_array( $policies ) ? $policies : array();
	}

	/**
	 * @return array<int,string>
	 */
	public static function corpusArtifactTypes(): array {
		$types = self::filter( 'datamachine_corpus_job_artifact_types', self::CORPUS_ARTIFACT_TYPES );
		if ( ! is_array( $types ) ) {
			return self::CORPUS_ARTIFACT_TYPES;
		}

		$out = array();
		foreach ( $types as $type ) {
			$type = self::key( $type );
			if ( '' !== $type ) {
				$out[] = $type;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string,mixed> $engine_data Job engine data.
	 * @return array<string,mixed>
	 */
	private static function corpusData( array $engine_data ): array {
		foreach ( array( 'corpus_indexing', 'corpus_indexing_summary' ) as $key ) {
			if ( is_array( $engine_data[ $key ] ?? null ) ) {
				return $engine_data[ $key ];
			}
		}

		return array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function countsFrom( array $summary, array $corpus, array $metrics_counts ): array {
		$counts = array();
		foreach ( self::CORPUS_COUNT_KEYS as $key ) {
			$value = $summary[ $key ] ?? ( $corpus[ $key ] ?? null );
			if ( null !== $value ) {
				$counts[ $key ] = max( 0, (int) $value );
			}
		}

		$aliases = array(
			'processed' => 'items_indexed',
			'skipped'   => 'items_skipped',
			'failed'    => 'extraction_failures',
		);
		foreach ( $aliases as $metrics_key => $count_key ) {
			if ( ! isset( $counts[ $count_key ] ) && isset( $metrics_counts[ $metrics_key ] ) ) {
				$counts[ $count_key ] = max( 0, (int) $metrics_counts[ $metrics_key ] );
			}
		}

		ksort( $counts, SORT_STRING );
		return $counts;
	}

	/**
	 * @param array<string,mixed> $engine_data Job engine data.
	 * @return array<string,array<string,mixed>>
	 */
	private static function artifactSources( array $engine_data ): array {
		$sources = array();
		foreach ( array( 'artifact_refs', 'artifact_files', 'corpus_artifacts' ) as $key ) {
			if ( is_array( $engine_data[ $key ] ?? null ) ) {
				$sources = array_replace( $sources, self::normalizeSourceList( $engine_data[ $key ] ) );
			}
		}

		$corpus = self::corpusData( $engine_data );
		if ( is_array( $corpus['artifacts'] ?? null ) ) {
			$sources = array_replace( $sources, self::normalizeSourceList( $corpus['artifacts'] ) );
		}

		return $sources;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function normalizeSourceList( array $artifacts ): array {
		$out = array();
		foreach ( $artifacts as $key => $artifact ) {
			if ( ! is_array( $artifact ) ) {
				continue;
			}

			$artifact_key         = is_string( $key ) ? $key : (string) ( $artifact['artifact_key'] ?? ( $artifact['artifact_type'] ?? '' ) );
			$out[ $artifact_key ] = $artifact;
		}

		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function normalizeArtifact( string $artifact_key, array $artifact ): array {
		$artifact_type = self::key( $artifact['artifact_type'] ?? $artifact_key );
		$retention     = self::key( $artifact['retention_scope'] ?? '' );
		if ( '' === $retention && in_array( $artifact_type, self::corpusArtifactTypes(), true ) ) {
			$retention = self::RETENTION_SCOPE;
		}

		if ( self::RETENTION_SCOPE !== $retention && ! in_array( $artifact_type, self::corpusArtifactTypes(), true ) ) {
			return array();
		}

		return self::filterEmpty(
			array(
				'schema_version'  => self::ARTIFACT_SCHEMA_VERSION,
				'artifact_key'    => self::key( $artifact['artifact_key'] ?? $artifact_key ),
				'artifact_type'   => $artifact_type,
				'artifact_ref'    => self::text( $artifact['artifact_ref'] ?? '' ),
				'label'           => self::text( $artifact['label'] ?? '' ),
				'retention_scope' => '' !== $retention ? $retention : self::RETENTION_SCOPE,
				'relative_path'   => self::text( $artifact['relative_path'] ?? '' ),
				'url'             => self::url( $artifact['url'] ?? '' ),
				'sha256'          => self::text( $artifact['sha256'] ?? '' ),
				'bytes'           => isset( $artifact['bytes'] ) ? max( 0, (int) $artifact['bytes'] ) : null,
				'created_at'      => self::text( $artifact['created_at'] ?? ( $artifact['written_at'] ?? '' ) ),
			)
		);
	}

	private static function positiveDays( mixed $value, int $fallback ): int {
		$days = (int) $value;
		return $days > 0 ? $days : $fallback;
	}

	private static function filter( string $hook, mixed $value ): mixed {
		return function_exists( 'apply_filters' ) ? apply_filters( $hook, $value ) : $value;
	}

	private static function key( mixed $value ): string {
		$value = (string) $value;
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $value );
		}

		$key = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
		return '' !== $key ? $key : '';
	}

	private static function text( mixed $value ): string {
		$value = (string) $value;
		if ( function_exists( 'sanitize_text_field' ) ) {
			return sanitize_text_field( $value );
		}

		return trim( preg_replace( '/<[^>]*>/', '', $value ) ?? '' );
	}

	private static function boundedText( mixed $value, int $max_chars ): string {
		$text = self::text( $value );
		return strlen( $text ) > $max_chars ? substr( $text, 0, $max_chars ) : $text;
	}

	private static function url( mixed $value ): string {
		$value = (string) $value;
		return function_exists( 'esc_url_raw' ) ? esc_url_raw( $value ) : filter_var( $value, FILTER_SANITIZE_URL );
	}

	/**
	 * @param array<string,mixed> $value Input array.
	 * @return array<string,mixed>
	 */
	private static function filterEmpty( array $value ): array {
		return array_filter(
			$value,
			static fn( $item ) => null !== $item && '' !== $item && array() !== $item
		);
	}
}
