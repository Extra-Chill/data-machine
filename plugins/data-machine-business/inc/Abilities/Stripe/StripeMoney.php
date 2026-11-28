<?php
/**
 * Stripe currency units.
 *
 * Stripe expresses amounts in the currency's smallest unit. Most currencies
 * use 2 decimals, zero-decimal currencies (JPY, KRW, ...) use none, and a few
 * use 3. Shared by reporting (minor -> major) and payment links (major -> minor).
 *
 * @package DataMachineBusiness\Abilities\Stripe
 */

namespace DataMachineBusiness\Abilities\Stripe;

defined( 'ABSPATH' ) || exit;

final class StripeMoney {

	private const ZERO_DECIMAL  = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
	private const THREE_DECIMAL = array( 'bhd', 'jod', 'kwd', 'omr', 'tnd' );

	/**
	 * Smallest units per major unit.
	 *
	 * @param string $currency ISO currency code, any case.
	 */
	public static function factor( string $currency ): int {
		$currency = strtolower( $currency );
		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return 1;
		}
		return in_array( $currency, self::THREE_DECIMAL, true ) ? 1000 : 100;
	}

	/**
	 * Stripe smallest units to major units.
	 *
	 * @param int|float|string $amount   Amount in smallest units.
	 * @param string           $currency ISO currency code.
	 */
	public static function major( $amount, string $currency ): float {
		return (float) $amount / self::factor( $currency );
	}

	/**
	 * Stripe smallest units to an exact major-unit decimal string.
	 *
	 * @param int|string $amount   Integer amount in smallest units.
	 * @param string     $currency ISO currency code.
	 */
	public static function decimal( $amount, string $currency ): string {
		$minor    = (int) $amount;
		$factor   = self::factor( $currency );
		$sign     = $minor < 0 ? '-' : '';
		$abs      = abs( $minor );
		$places   = strlen( (string) $factor ) - 1;
		$fraction = $places > 0 ? '.' . str_pad( (string) ( $abs % $factor ), $places, '0', STR_PAD_LEFT ) : '';
		return $sign . intdiv( $abs, $factor ) . $fraction;
	}

	/**
	 * Major units to Stripe smallest units, rounded half up.
	 *
	 * @param float  $amount   Amount in major units.
	 * @param string $currency ISO currency code.
	 */
	public static function minor( float $amount, string $currency ): int {
		return (int) round( $amount * self::factor( $currency ) );
	}
}
