<?php
/**
 * Generic read-only execution query helpers.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class ExecutionQuery {

	/**
	 * Normalize metadata filters into stable path/value pairs.
	 *
	 * @param mixed $metadata Raw metadata filter input.
	 * @return array<string,mixed> Metadata filters keyed by dot-path.
	 */
	public static function normalize_metadata_filters( mixed $metadata ): array {
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$filters = array();
		foreach ( $metadata as $path => $value ) {
			$path = sanitize_text_field( (string) $path );
			if ( '' === $path ) {
				continue;
			}

			$filters[ $path ] = self::normalize_metadata_value( $value );
		}

		return $filters;
	}

	/**
	 * Determine whether an execution metadata snapshot matches all filters.
	 *
	 * @param array<string,mixed> $engine_data Engine data snapshot.
	 * @param array<string,mixed> $metadata_filters Metadata filters keyed by dot-path.
	 * @return bool Whether all filters match exactly.
	 */
	public static function matches_metadata_filters( array $engine_data, array $metadata_filters ): bool {
		foreach ( self::normalize_metadata_filters( $metadata_filters ) as $path => $expected ) {
			$actual = self::get_path_value( $engine_data, $path );
			if ( self::normalize_metadata_value( $actual ) !== $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read a dot-path from a nested array.
	 *
	 * @param array<string,mixed> $data Source data.
	 * @param string              $path Dot-delimited path.
	 * @return mixed Value at path, or null when absent.
	 */
	public static function get_path_value( array $data, string $path ): mixed {
		$current = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * Parse comma-separated key=value metadata filters.
	 *
	 * @param string $raw Raw filter string.
	 * @return array<string,mixed> Metadata filters keyed by dot-path.
	 */
	public static function parse_metadata_filter_string( string $raw ): array {
		$filters = array();
		foreach ( explode( ',', $raw ) as $pair ) {
			$pair = trim( $pair );
			if ( '' === $pair || ! str_contains( $pair, '=' ) ) {
				continue;
			}

			list( $path, $value ) = array_map( 'trim', explode( '=', $pair, 2 ) );
			if ( '' !== $path ) {
				$filters[ $path ] = $value;
			}
		}

		return self::normalize_metadata_filters( $filters );
	}

	/**
	 * Normalize scalar metadata values for exact comparisons.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return mixed Normalized value.
	 */
	private static function normalize_metadata_value( mixed $value ): mixed {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( 'true' === strtolower( $trimmed ) ) {
				return true;
			}
			if ( 'false' === strtolower( $trimmed ) ) {
				return false;
			}
			if ( 'null' === strtolower( $trimmed ) ) {
				return null;
			}
			if ( is_numeric( $trimmed ) ) {
				return str_contains( $trimmed, '.' ) ? (float) $trimmed : (int) $trimmed;
			}

			return sanitize_text_field( $trimmed );
		}

		return $value;
	}
}
