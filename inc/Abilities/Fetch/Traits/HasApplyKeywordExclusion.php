<?php

namespace DataMachine\Abilities\Fetch\Traits;

/**
 * Shared trait for the `applyKeywordExclusion` method.
 *
 * Extracted from duplicate implementations across the Fetch ability handlers.
 */
trait HasApplyKeywordExclusion {
	/**
	 * Apply keyword exclusion filter.
	 *
	 * Returns true when any of the comma-separated terms in $exclude_keywords
	 * is present in $text. Inverse of applyKeywordSearch — callers should skip
	 * items for which this returns true. An empty exclusion list never matches.
	 */
	private function applyKeywordExclusion( string $text, string $exclude_keywords ): bool {
		$exclude_keywords = trim( $exclude_keywords );
		if ( '' === $exclude_keywords ) {
			return false;
		}

		$terms      = array_filter( array_map( 'trim', explode( ',', $exclude_keywords ) ) );
		$text_lower = strtolower( $text );

		foreach ( $terms as $term ) {
			if ( strpos( $text_lower, strtolower( $term ) ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
