<?php
/**
 * Portable slug helpers for agent bundle filenames and references.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes stable bundle slugs without touching persistence.
 */
final class PortableSlug {

	/**
	 * Normalize a candidate slug/name for bundle paths.
	 *
	 * @param string $candidate Candidate slug or display name.
	 * @param string $fallback  Fallback when candidate is empty after normalization.
	 * @return string
	 */
	public static function normalize( string $candidate, string $fallback = 'item' ): string {
		$slug = strtolower( trim( $candidate ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		if ( '' === $slug ) {
			$slug = $fallback;
		}

		return $slug;
	}

	/**
	 * Deduplicate a slug against already-used sibling slugs.
	 *
	 * @param string $slug Existing normalized slug.
	 * @param array  $used Used sibling slugs.
	 * @return string
	 */
	public static function dedupe( string $slug, array $used ): string {
		$used_lookup = array_fill_keys( array_map( 'strval', $used ), true );
		if ( ! isset( $used_lookup[ $slug ] ) ) {
			return $slug;
		}

		$base = $slug;
		$i    = 2;
		while ( isset( $used_lookup[ "{$base}-{$i}" ] ) ) {
			++$i;
		}

		return "{$base}-{$i}";
	}
}
