<?php
/**
 * Centralized price formatting utility.
 *
 * Provides consistent price formatting across all event import handlers
 * and extractors.
 *
 * @package DataMachineEvents\Core
 * @since   0.9.19
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriceFormatter {

	/**
	 * Format a price range as a display string.
	 *
	 * @param float|null $min Minimum price
	 * @param float|null $max Maximum price (optional)
	 * @return string Formatted price or empty if invalid
	 */
	public static function formatRange( ?float $min, ?float $max = null ): string {
		$min = $min ?? 0.0;
		$max = $max ?? 0.0;

		if ( $min <= 0 && $max <= 0 ) {
			return '';
		}

		// Single price or min equals max
		if ( $min > 0 && ( $max <= 0 || abs( $min - $max ) < 0.01 ) ) {
			return '$' . number_format( $min, 2 );
		}

		// Only max is set
		if ( $min <= 0 && $max > 0 ) {
			return '$' . number_format( $max, 2 );
		}

		// Range: ensure min <= max
		if ( $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}

		return '$' . number_format( $min, 2 ) . ' - $' . number_format( $max, 2 );
	}

	/**
	 * Parse a price string and extract numeric values.
	 *
	 * @param string $raw Raw price string
	 * @return array{min: ?float, max: ?float, is_free: bool}
	 */
	public static function parse( string $raw ): array {
		$result = array(
			'min'     => null,
			'max'     => null,
			'is_free' => false,
		);
		$raw    = trim( $raw );

		if ( empty( $raw ) ) {
			return $result;
		}

		if ( preg_match( '/^free$/i', $raw ) ) {
			$result['is_free'] = true;
			return $result;
		}

		if ( preg_match_all( '/[\d,]+(?:\.\d{2})?/', $raw, $matches ) ) {
			$values        = array_map( fn( $v ) => (float) str_replace( ',', '', $v ), $matches[0] );
			$result['min'] = $values[0] ?? null;
			$result['max'] = $values[1] ?? null;
		}

		return $result;
	}

	/**
	 * Check if a price string indicates a free event.
	 *
	 * @param string $raw Raw price string
	 * @return bool True if the price indicates a free event
	 */
	public static function isFree( string $raw ): bool {
		return preg_match( '/^free$/i', trim( $raw ) ) === 1;
	}
}
