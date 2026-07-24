<?php
/**
 * Canonical citation metadata normalizer.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes generic retrieved-context citation metadata.
 */
class WP_Agent_Citation_Metadata {

	/**
	 * Canonical citation metadata key.
	 */
	public const KEY = 'citations';

	/**
	 * Normalize metadata and canonicalize metadata.citations when present.
	 *
	 * @param mixed $metadata Raw metadata.
	 * @return array<mixed>
	 */
	public static function normalize_metadata( $metadata ): array {
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		if ( array_key_exists( self::KEY, $metadata ) ) {
			$metadata[ self::KEY ] = self::normalize_many( $metadata[ self::KEY ] );
		}

		return $metadata;
	}

	/**
	 * Normalize a list of citations.
	 *
	 * @param mixed $citations Raw citation list.
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize_many( $citations ): array {
		if ( ! is_array( $citations ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $citations as $citation ) {
			if ( ! is_array( $citation ) ) {
				continue;
			}

			$citation = self::normalize( $citation );
			if ( ! empty( $citation ) ) {
				$normalized[] = $citation;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize one citation to the substrate citation shape.
	 *
	 * @param array<mixed> $citation Raw citation.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $citation ): array {
		$normalized = array();
		foreach ( $citation as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		foreach ( array( 'source', 'source_id', 'item_id', 'fragment_id', 'source_title', 'source_url', 'excerpt' ) as $key ) {
			unset( $normalized[ $key ] );

			if ( isset( $citation[ $key ] ) && is_scalar( $citation[ $key ] ) ) {
				$value = trim( (string) $citation[ $key ] );
				if ( '' !== $value ) {
					$normalized[ $key ] = $value;
				}
			}
		}

		unset( $normalized['score'] );
		if ( isset( $citation['score'] ) && is_numeric( $citation['score'] ) ) {
			$normalized['score'] = (float) $citation['score'];
		}

		return $normalized;
	}
}
