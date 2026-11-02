<?php
/**
 * Stripe Payment Link policy.
 *
 * Hard limits on what a staged Payment Link may charge. Validated when the
 * request is staged and again, against the limits in force, at approval time,
 * so tightening the limits also blocks requests that are already pending.
 *
 * Limits live in the `datamachine_stripe_payment_link_limits` option and can
 * be adjusted with the `datamachine_stripe_payment_link_limits` filter:
 *
 * - max_amount: largest charge per payment, in major units (default 500).
 * - currencies: allowed lowercase ISO codes (default ['usd']).
 *
 * @package DataMachineBusiness\Abilities\Stripe
 */

namespace DataMachineBusiness\Abilities\Stripe;

defined( 'ABSPATH' ) || exit;

final class StripePaymentLinkPolicy {

	public const LIMITS_OPTION = 'datamachine_stripe_payment_link_limits';

	public const INTERVALS = array( 'once', 'day', 'week', 'month', 'year' );

	/** Stripe's maximum interval_count per interval (one year total). */
	private const MAX_INTERVAL_COUNT = array(
		'day'   => 365,
		'week'  => 52,
		'month' => 12,
		'year'  => 1,
	);

	/**
	 * Current hard limits.
	 *
	 * @return array{max_amount: float, currencies: string[]}
	 */
	public static function limits(): array {
		$stored     = get_option( self::LIMITS_OPTION, array() );
		$stored     = is_array( $stored ) ? $stored : array();
		$limits     = apply_filters(
			'datamachine_stripe_payment_link_limits',
			array(
				'max_amount' => isset( $stored['max_amount'] ) && is_numeric( $stored['max_amount'] ) ? (float) $stored['max_amount'] : 500.0,
				'currencies' => isset( $stored['currencies'] ) && is_array( $stored['currencies'] ) ? $stored['currencies'] : array( 'usd' ),
			)
		);
		$max        = is_array( $limits ) && isset( $limits['max_amount'] ) && is_numeric( $limits['max_amount'] ) ? (float) $limits['max_amount'] : 0.0;
		$currencies = is_array( $limits ) && isset( $limits['currencies'] ) && is_array( $limits['currencies'] ) ? $limits['currencies'] : array();
		$currencies = array_values( array_filter( array_map( static fn( $c ) => is_string( $c ) ? strtolower( $c ) : '', $currencies ), static fn( $c ) => (bool) preg_match( '/^[a-z]{3}$/', $c ) ) );
		return array(
			'max_amount' => is_finite( $max ) && $max > 0 ? $max : 0.0,
			'currencies' => $currencies,
		);
	}

	/**
	 * Validate and normalize a Payment Link request.
	 *
	 * @param array $input  Raw request.
	 * @param array $limits Limits from limits().
	 * @return array|\WP_Error Normalized request: name, description, amount, unit_amount, currency, interval, interval_count.
	 */
	public static function normalize( array $input, array $limits ) {
		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? trim( $input['name'] ) : '';
		if ( '' === $name || strlen( $name ) > 250 || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			return new \WP_Error( 'stripe_link_invalid_name', 'name is required: a single line of at most 250 characters.' );
		}
		$description = isset( $input['description'] ) && is_string( $input['description'] ) ? trim( $input['description'] ) : '';
		if ( strlen( $description ) > 500 ) {
			return new \WP_Error( 'stripe_link_invalid_description', 'description must be at most 500 characters.' );
		}

		$currency = isset( $input['currency'] ) && is_string( $input['currency'] ) ? strtolower( $input['currency'] ) : 'usd';
		if ( ! in_array( $currency, $limits['currencies'], true ) ) {
			return new \WP_Error( 'stripe_link_currency_not_allowed', sprintf( 'Currency %s is not allowed by the Payment Link limits.', strtoupper( $currency ) ) );
		}

		$amount = $input['amount'] ?? null;
		if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount <= 0 ) {
			return new \WP_Error( 'stripe_link_invalid_amount', 'amount must be a positive number in major units, e.g. 50 for $50.00.' );
		}
		$amount      = (float) $amount;
		$unit_amount = StripeMoney::minor( $amount, $currency );
		if ( $unit_amount < 1 ) {
			return new \WP_Error( 'stripe_link_invalid_amount', 'amount is smaller than the currency allows.' );
		}
		if ( $limits['max_amount'] <= 0 || $unit_amount > StripeMoney::minor( $limits['max_amount'], $currency ) ) {
			return new \WP_Error( 'stripe_link_amount_over_limit', sprintf( 'amount exceeds the Payment Link limit of %s %s per payment.', number_format( $limits['max_amount'], 2, '.', '' ), strtoupper( $currency ) ) );
		}

		$interval = isset( $input['interval'] ) && is_string( $input['interval'] ) ? strtolower( $input['interval'] ) : 'once';
		if ( ! in_array( $interval, self::INTERVALS, true ) ) {
			return new \WP_Error( 'stripe_link_invalid_interval', 'interval must be once, day, week, month, or year.' );
		}
		$interval_count = 1;
		if ( 'once' !== $interval ) {
			$raw = $input['interval_count'] ?? 1;
			if ( ! is_numeric( $raw ) || (int) $raw != $raw || (int) $raw < 1 || (int) $raw > self::MAX_INTERVAL_COUNT[ $interval ] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Integral check across int/float/string input.
				return new \WP_Error( 'stripe_link_invalid_interval', sprintf( 'interval_count must be an integer from 1 to %d for %s.', self::MAX_INTERVAL_COUNT[ $interval ], $interval ) );
			}
			$interval_count = (int) $raw;
		}

		return array(
			'name'           => $name,
			'description'    => $description,
			'amount'         => StripeMoney::major( $unit_amount, $currency ),
			'unit_amount'    => $unit_amount,
			'currency'       => $currency,
			'interval'       => $interval,
			'interval_count' => $interval_count,
		);
	}

	/**
	 * Human-readable summary for the approval request.
	 *
	 * @param array $link Normalized request.
	 */
	public static function summary( array $link ): string {
		$money = number_format( (float) $link['amount'], 2 ) . ' ' . strtoupper( $link['currency'] );
		if ( 'once' === $link['interval'] ) {
			$cadence = 'one-time';
		} elseif ( 1 === (int) $link['interval_count'] ) {
			$cadence = 'every ' . $link['interval'];
		} else {
			$cadence = 'every ' . (int) $link['interval_count'] . ' ' . $link['interval'] . 's';
		}
		return sprintf( 'Create a Stripe Payment Link "%s": %s, %s', $link['name'], $money, $cadence );
	}
}
