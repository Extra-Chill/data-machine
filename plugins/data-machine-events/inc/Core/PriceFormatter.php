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
	 * ISO 4217 currency code → symbol mapping.
	 *
	 * Covers all currencies returned by event sources (Ticketmaster, DiceFM,
	 * Sofar Sounds, etc.). Symbols placed before the amount by convention.
	 */
	private const CURRENCY_SYMBOLS = array(
		'USD' => '$',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'NZD' => 'NZ$',
		'EUR' => '€',
		'GBP' => '£',
		'JPY' => '¥',
		'CNY' => '¥',
		'KRW' => '₩',
		'BRL' => 'R$',
		'MXN' => 'MX$',
		'INR' => '₹',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'CHF' => 'CHF',
		'ZAR' => 'R',
		'SGD' => 'S$',
		'HKD' => 'HK$',
		'THB' => '฿',
		'AED' => 'AED',
		'NGN' => '₦',
		'CZK' => 'Kč',
		'PLN' => 'zł',
		'TRY' => '₺',
		'ILS' => '₪',
		'PHP' => '₱',
		'MYR' => 'RM',
		'IDR' => 'Rp',
		'TWD' => 'NT$',
		'ARS' => 'AR$',
		'CLP' => 'CLP$',
		'COP' => 'COP$',
		'PEN' => 'S/',
		'BGN' => 'лв',
		'RON' => 'lei',
		'HUF' => 'Ft',
		'HRK' => 'kn',
		'ISK' => 'kr',
	);

	/**
	 * Get the display symbol for a currency code.
	 *
	 * Returns the mapped symbol for known currencies, or the raw ISO code
	 * for unmapped currencies.
	 *
	 * @param string $currency ISO 4217 currency code.
	 * @return string Display symbol or ISO code.
	 */
	public static function getCurrencySymbol( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );
		return self::CURRENCY_SYMBOLS[ $currency ] ?? $currency;
	}

	/**
	 * Format a price range as a display string.
	 *
	 * @param float|null $min Minimum price
	 * @param float|null $max Maximum price (optional)
	 * @param string     $currency ISO 4217 currency code (default: USD)
	 * @return string Formatted price or empty if invalid
	 */
	public static function formatRange( ?float $min, ?float $max = null, string $currency = 'USD' ): string {
		$min = $min ?? 0.0;
		$max = $max ?? 0.0;

		$symbol = self::getCurrencySymbol( $currency );

		if ( $min <= 0 && $max <= 0 ) {
			return '';
		}

		// Single price or min equals max
		if ( $min > 0 && ( $max <= 0 || abs( $min - $max ) < 0.01 ) ) {
			return $symbol . number_format( $min, 2 );
		}

		// Only max is set
		if ( $min <= 0 && $max > 0 ) {
			return $symbol . number_format( $max, 2 );
		}

		// Range: ensure min <= max
		if ( $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}

		return $symbol . number_format( $min, 2 ) . ' - ' . $symbol . number_format( $max, 2 );
	}

	/**
	 * Format a structured price payload into a display string.
	 *
	 * Treats explicit free flags and all-zero values as free.
	 * Uses the proper currency symbol for known ISO 4217 codes.
	 *
	 * @param float|null  $min Minimum price.
	 * @param float|null  $max Maximum price.
	 * @param string      $currency ISO 4217 currency code.
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

		return self::formatRange( $normalized_min, $normalized_max, $currency );
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
