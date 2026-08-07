<?php
/**
 * Source inventory capability profiler.
 *
 * @package DataMachine\Core\SourceAggregation
 */

namespace DataMachine\Core\SourceAggregation;

defined( 'ABSPATH' ) || exit;

class SourceInventoryProfiler {

	/**
	 * Build a generic inventory profile from a source descriptor.
	 *
	 * @param array $source Source descriptor.
	 * @return array<string,mixed>
	 */
	public function profile( array $source ): array {
		$capabilities = $this->normalizeCapabilities( $source['capabilities'] ?? array() );

		/**
		 * Filters source inventory capabilities for a source descriptor.
		 *
		 * Handlers/extensions can provide source-specific facts without Data Machine
		 * core knowing provider semantics.
		 *
		 * @param array<string,mixed> $capabilities Normalized capabilities.
		 * @param array<string,mixed> $source       Source descriptor.
		 */
		$filtered = apply_filters( 'datamachine_source_inventory_capabilities', $capabilities, $source );
		if ( is_array( $filtered ) ) {
			$capabilities = $this->normalizeCapabilities( $filtered );
		}

		$mode       = $this->coverageMode( $capabilities );
		$confidence = $this->confidence( $capabilities, $mode );
		$metric     = $this->metric( $mode );

		return array(
			'source_kind'          => (string) ( $source['kind'] ?? '' ),
			'provider'             => (string) ( $source['provider'] ?? '' ),
			'coverage_mode'        => $mode,
			'confidence'           => $confidence,
			'metric'               => $metric,
			'has_denominator'      => in_array( $mode, array( 'inventory', 'counted_search', 'bounded_window' ), true ),
			'denominator_reliable' => 'inventory' === $mode && ! empty( $capabilities['can_enumerate'] ) && ! empty( $capabilities['stable_ids'] ),
			'capabilities'         => $capabilities,
		);
	}

	/**
	 * Normalize source capability flags and metadata.
	 *
	 * @param mixed $capabilities Raw capability metadata.
	 * @return array<string,mixed>
	 */
	private function normalizeCapabilities( mixed $capabilities ): array {
		if ( ! is_array( $capabilities ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $capabilities as $key => $value ) {
			$key = $this->normalizeKey( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$normalized[ $key ] = $this->normalizeValue( $value );
		}

		return $normalized;
	}

	private function normalizeKey( string $key ): string {
		$key = strtolower( trim( $key ) );
		$key = preg_replace( '/[^a-z0-9_\-]+/', '_', $key );
		$key = str_replace( '-', '_', (string) $key );

		return trim( $key, '_' );
	}

	private function normalizeValue( mixed $value ): mixed {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			$normalized = array();
			foreach ( $value as $key => $item ) {
				if ( is_int( $key ) ) {
					$normalized[] = $this->normalizeValue( $item );
					continue;
				}

				$key = $this->normalizeKey( (string) $key );
				if ( '' !== $key ) {
					$normalized[ $key ] = $this->normalizeValue( $item );
				}
			}

			return $normalized;
		}

		return trim( (string) $value );
	}

	private function coverageMode( array $capabilities ): string {
		$explicit = $this->normalizeMode( (string) ( $capabilities['coverage_mode'] ?? '' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		if ( ! empty( $capabilities['can_enumerate'] ) && ! empty( $capabilities['stable_ids'] ) ) {
			return 'inventory';
		}

		if ( ! empty( $capabilities['has_total_count'] ) ) {
			return 'counted_search';
		}

		if ( ! empty( $capabilities['supports_time_windows'] ) && ! empty( $capabilities['stable_ids'] ) ) {
			return 'bounded_window';
		}

		if ( ! empty( $capabilities['stable_ids'] ) || ! empty( $capabilities['supports_query_shards'] ) ) {
			return 'sampled_discovery';
		}

		return 'unknown';
	}

	private function normalizeMode( string $mode ): string {
		$mode = $this->normalizeKey( $mode );
		return in_array( $mode, array( 'inventory', 'counted_search', 'bounded_window', 'sampled_discovery', 'unknown' ), true ) ? $mode : '';
	}

	private function confidence( array $capabilities, string $mode ): string {
		$explicit = $this->normalizeKey( (string) ( $capabilities['confidence'] ?? '' ) );
		if ( in_array( $explicit, array( 'high', 'medium', 'low' ), true ) ) {
			return $explicit;
		}

		if ( 'inventory' === $mode && ! empty( $capabilities['stable_ids'] ) && ( ! empty( $capabilities['has_stable_cursor'] ) || ! empty( $capabilities['has_total_count'] ) ) ) {
			return 'high';
		}

		if ( in_array( $mode, array( 'inventory', 'counted_search', 'bounded_window' ), true ) ) {
			return 'medium';
		}

		return 'low';
	}

	private function metric( string $mode ): string {
		if ( 'inventory' === $mode ) {
			return 'known_denominator';
		}
		if ( 'counted_search' === $mode ) {
			return 'reported_total_count';
		}
		if ( 'bounded_window' === $mode ) {
			return 'window_seen_vs_evaluated';
		}

		return 'marginal_yield_saturation';
	}
}
