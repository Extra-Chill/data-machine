<?php

namespace DataMachine\Abilities\Fetch\Traits;

/**
 * Shared trait for the `applyKeywordSearch` method.
 *
 * Extracted by homeboy audit --fix from duplicate implementations.
 */
trait HasApplyKeywordSearch {
	/**
	 * Apply keyword search filter.
	 */
	private function applyKeywordSearch( string $text, string $search_term ): bool {
		if ( empty( $search_term ) ) {
			return true;
		}

		$terms      = array_map( 'trim', explode( ',', $search_term ) );
		$text_lower = strtolower( $text );

		foreach ( $terms as $term ) {
			if ( empty( $term ) ) {
				continue;
			}
			if ( strpos( $text_lower, strtolower( $term ) ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
