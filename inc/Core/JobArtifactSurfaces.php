<?php
/**
 * Generic job summary and artifact retention surfaces.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class JobArtifactSurfaces {

	public const DEFAULT_WORKLOAD_TYPE   = 'indexing';
	public const DEFAULT_RETENTION_SCOPE = 'indexing_artifacts';

	private const SUMMARY_SCHEMA_VERSION  = 1;
	private const ARTIFACT_SCHEMA_VERSION = 1;

	private const DEFAULT_COUNT_KEYS = array(
		'selected',
		'processed',
		'skipped',
		'failed',
		'retried',
		'scheduled',
	);

	/**
	 * Normalize a concise operator-facing summary for long-running jobs.
	 *
	 * @param array<string,mixed> $job         Job row.
	 * @param array<string,mixed> $engine_data Job engine data.
	 * @return array<string,mixed>
	 */
	public static function summary( array $job, array $engine_data ): array {
		$summary = is_array( $engine_data['job_summary'] ?? null ) ? $engine_data['job_summary'] : array();
		if ( empty( $summary ) ) {
			return array();
		}

		$metrics = is_array( $engine_data['run_metrics'] ?? null ) ? RunMetrics::normalize( $engine_data['run_metrics'] ) : array();

		return self::filterEmpty(
			array(
				'schema_version' => self::SUMMARY_SCHEMA_VERSION,
				'workload_type'  => self::key( $summary['workload_type'] ?? ( $engine_data['workload_type'] ?? self::DEFAULT_WORKLOAD_TYPE ) ),
				'headline'       => self::boundedText( $summary['headline'] ?? ( $summary['summary'] ?? '' ), 280 ),
				'status'         => isset( $job['status'] ) ? (string) $job['status'] : null,
				'counts'         => self::countsFrom( $summary, $metrics['counts'] ?? array() ),
				'artifact_refs'  => self::artifactRefs( $engine_data ),
				'notes'          => self::boundedText( $summary['notes'] ?? '', 1000 ),
				'updated_at'     => self::text( $summary['updated_at'] ?? '' ),
			)
		);
	}

	/**
	 * Normalize job artifact references with explicit retention scopes.
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
	 * Return retention policy definitions for scoped job artifacts.
	 *
	 * Consumers can add product/domain scopes through the filter without putting
	 * their semantics into Data Machine core.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function retentionPolicies(): array {
		$max_age_days = self::positiveDays( self::filter( 'datamachine_job_artifacts_max_age_days', 30 ), 30 );
		$policies     = array(
			self::DEFAULT_RETENTION_SCOPE => array(
				'label'           => 'Indexing job artifacts',
				'retention_scope' => self::DEFAULT_RETENTION_SCOPE,
				'max_age_days'    => $max_age_days,
				'filter'          => 'datamachine_job_artifacts_max_age_days',
			),
		);

		$policies = self::filter( 'datamachine_job_artifact_retention_policies', $policies );
		return is_array( $policies ) ? self::normalizePolicies( $policies ) : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function countsFrom( array $summary, array $metrics_counts ): array {
		$counts = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
		foreach ( self::DEFAULT_COUNT_KEYS as $key ) {
			if ( ! isset( $counts[ $key ] ) && isset( $summary[ $key ] ) ) {
				$counts[ $key ] = $summary[ $key ];
			}

			if ( ! isset( $counts[ $key ] ) && isset( $metrics_counts[ $key ] ) ) {
				$counts[ $key ] = $metrics_counts[ $key ];
			}
		}

		$out = array();
		foreach ( $counts as $key => $value ) {
			$key = self::key( $key );
			if ( '' !== $key && is_scalar( $value ) ) {
				$out[ $key ] = max( 0, (int) $value );
			}
		}

		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * @param array<string,mixed> $engine_data Job engine data.
	 * @return array<string,array<string,mixed>>
	 */
	private static function artifactSources( array $engine_data ): array {
		$sources     = array();
		$summary     = is_array( $engine_data['job_summary'] ?? null ) ? $engine_data['job_summary'] : array();
		$source_keys = self::filter( 'datamachine_job_artifact_source_keys', array( 'job_artifacts', 'artifact_refs', 'artifact_files' ) );
		if ( ! is_array( $source_keys ) ) {
			$source_keys = array( 'job_artifacts', 'artifact_refs', 'artifact_files' );
		}

		if ( is_array( $summary['artifact_refs'] ?? null ) ) {
			$sources = array_replace( $sources, self::normalizeSourceList( $summary['artifact_refs'], true ) );
		}

		foreach ( $source_keys as $key ) {
			$key = (string) $key;
			if ( is_array( $engine_data[ $key ] ?? null ) ) {
				$sources = array_replace( $sources, self::normalizeSourceList( $engine_data[ $key ], 'job_artifacts' === $key ) );
			}
		}

		return $sources;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function normalizeSourceList( array $artifacts, bool $default_scope ): array {
		$out = array();
		foreach ( $artifacts as $key => $artifact ) {
			if ( ! is_array( $artifact ) ) {
				continue;
			}

			if ( $default_scope && empty( $artifact['retention_scope'] ) ) {
				$artifact['retention_scope'] = self::DEFAULT_RETENTION_SCOPE;
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
		$retention = self::key( $artifact['retention_scope'] ?? '' );
		if ( '' === $retention || ! isset( self::retentionPolicies()[ $retention ] ) ) {
			return array();
		}

		return self::filterEmpty(
			array(
				'schema_version'  => self::ARTIFACT_SCHEMA_VERSION,
				'artifact_key'    => self::key( $artifact['artifact_key'] ?? $artifact_key ),
				'artifact_type'   => self::key( $artifact['artifact_type'] ?? $artifact_key ),
				'artifact_ref'    => self::text( $artifact['artifact_ref'] ?? '' ),
				'label'           => self::text( $artifact['label'] ?? '' ),
				'retention_scope' => $retention,
				'relative_path'   => self::text( $artifact['relative_path'] ?? '' ),
				'url'             => self::url( $artifact['url'] ?? '' ),
				'sha256'          => self::text( $artifact['sha256'] ?? '' ),
				'bytes'           => isset( $artifact['bytes'] ) ? max( 0, (int) $artifact['bytes'] ) : null,
				'created_at'      => self::text( $artifact['created_at'] ?? ( $artifact['written_at'] ?? '' ) ),
			)
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function normalizePolicies( array $policies ): array {
		$out = array();
		foreach ( $policies as $scope => $policy ) {
			if ( ! is_array( $policy ) ) {
				continue;
			}

			$scope = self::key( $policy['retention_scope'] ?? $scope );
			if ( '' === $scope ) {
				continue;
			}

			$out[ $scope ] = self::filterEmpty(
				array(
					'label'           => self::text( $policy['label'] ?? $scope ),
					'retention_scope' => $scope,
					'max_age_days'    => self::positiveDays( $policy['max_age_days'] ?? 30, 30 ),
					'filter'          => self::text( $policy['filter'] ?? '' ),
				)
			);
		}

		return $out;
	}

	private static function positiveDays( mixed $value, int $fallback ): int {
		$days = (int) $value;
		return $days > 0 ? $days : $fallback;
	}

	private static function filter( string $hook, mixed $value ): mixed {
		if ( '' === $hook ) {
			return $value;
		}

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
		$url   = function_exists( 'esc_url_raw' ) ? esc_url_raw( $value ) : filter_var( $value, FILTER_SANITIZE_URL );
		return is_string( $url ) ? $url : '';
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
