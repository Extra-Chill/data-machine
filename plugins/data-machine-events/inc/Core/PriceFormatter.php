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
	 * Canonical display string for free events.
	 */
	private const FREE_LABEL = 'Free';

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
	 * Format a structured price payload into a display string.
	 *
	 * Treats explicit free flags and all-zero values as free.
	 * Non-USD currencies are prefixed with the ISO currency code while preserving
	 * the existing dollar-based numeric formatting behavior.
	 *
	 * @param float|null  $min Minimum price.
	 * @param float|null  $max Maximum price.
	 * @param string      $currency ISO currency code.
	 * @param bool|null   $is_free Explicit free signal from source data.
	 * @return string Formatted price string.
	 */
	public static function formatStructured( ?float $min = null, ?float $max = null, string $currency = 'USD', ?bool $is_free = null ): string {
		if ( true === $is_free ) {
			return self::formatFree();
		}

		$normalized_min = null !== $min ? (float) $min : null;
		$normalized_max = null !== $max ? (float) $max : null;

		if ( self::isZeroOrLess( $normalized_min ) && self::isZeroOrLess( $normalized_max ) ) {
			if ( null !== $normalized_min || null !== $normalized_max ) {
				return self::formatFree();
			}
			return '';
		}

		$formatted = self::formatRange( $normalized_min, $normalized_max );
		if ( '' === $formatted ) {
			return '';
		}

		$currency = strtoupper( trim( $currency ) );
		if ( '' === $currency || 'USD' === $currency ) {
			return $formatted;
		}

		return $currency . ' ' . $formatted;
	}

	/**
	 * Format a free event label.
	 *
	 * @return string
	 */
	public static function formatFree(): string {
		return self::FREE_LABEL;
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

	/**
	 * Whether the provided numeric value is null or non-positive.
	 *
	 * @param float|null $value Numeric value.
	 * @return bool
	 */
	private static function isZeroOrLess( ?float $value ): bool {
		return null === $value || $value <= 0;
	}
}
