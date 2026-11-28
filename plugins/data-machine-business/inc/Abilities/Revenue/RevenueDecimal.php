<?php
/**
 * Exact decimal-string arithmetic for revenue amounts.
 *
 * Amounts are stored as DECIMAL(20,6) and exchanged as decimal strings so
 * totals never pick up binary floating point drift. Internally an amount is a
 * signed integer count of millionths (fits 64-bit ints for any realistic
 * ledger total).
 *
 * @package DataMachineBusiness\Abilities\Revenue
 */

namespace DataMachineBusiness\Abilities\Revenue;

defined( 'ABSPATH' ) || exit;

final class RevenueDecimal {

	public const SCALE = 6;

	private const UNIT = 1000000;

	/**
	 * Parse a decimal string or number into millionths.
	 *
	 * Strings must be plain decimals (no exponent, no thousands separators)
	 * with at most six fractional digits. Floats/ints are formatted to six
	 * places first.
	 *
	 * @param mixed $value Amount.
	 * @return int|null Millionths, or null when invalid.
	 */
	public static function to_micros( $value ): ?int {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		} elseif ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$value = number_format( $value, self::SCALE, '.', '' );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( ! preg_match( '/^([+-]?)(\d{1,14})(?:\.(\d{0,6}))?$/D', $value, $m ) ) {
			return null;
		}
		$micros = (int) $m[2] * self::UNIT + (int) str_pad( $m[3] ?? '', self::SCALE, '0' );
		return '-' === $m[1] ? -$micros : $micros;
	}

	/** Format millionths as a canonical decimal string (at least 2 fraction digits). */
	public static function from_micros( int $micros ): string {
		$sign     = $micros < 0 ? '-' : '';
		$abs      = abs( $micros );
		$whole    = intdiv( $abs, self::UNIT );
		$fraction = rtrim( str_pad( (string) ( $abs % self::UNIT ), self::SCALE, '0', STR_PAD_LEFT ), '0' );
		$fraction = str_pad( $fraction, 2, '0' );
		return ( 0 === $abs ? '' : $sign ) . $whole . '.' . $fraction;
	}

	/**
	 * Read a stored amount. MySQL returns exact DECIMAL strings; engines that
	 * store DECIMAL as REAL (SQLite) may return exponent notation, which is
	 * rounded back to the six-place scale.
	 *
	 * @param mixed $value Stored value.
	 */
	public static function from_storage( $value ): string {
		$micros = self::to_micros( is_string( $value ) || is_int( $value ) || is_float( $value ) ? $value : '' );
		if ( null === $micros && is_numeric( $value ) ) {
			$micros = self::to_micros( (float) $value );
		}
		return self::from_micros( $micros ?? 0 );
	}

	/** Storage form with the full fixed scale. */
	public static function to_storage( int $micros ): string {
		$sign = $micros < 0 ? '-' : '';
		$abs  = abs( $micros );
		return $sign . intdiv( $abs, self::UNIT ) . '.' . str_pad( (string) ( $abs % self::UNIT ), self::SCALE, '0', STR_PAD_LEFT );
	}
}
