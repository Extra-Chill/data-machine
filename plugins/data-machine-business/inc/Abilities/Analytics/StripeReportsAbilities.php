<?php
/**
 * Bounded, read-only Stripe reports.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class StripeReportsAbilities {
	const CONFIG_OPTION = 'datamachine_stripe_config';
	const API_BASE = 'https://api.stripe.com/v1';
	const MAX_PAGES = 10;
	/** Stripe's maximum list page size. */
	const PAGE_SIZE = 100;
	/** Aggregate/filtered actions scan the whole bounded window, then limit output rows. */
	const SCAN_ACTIONS = array( 'mrr', 'renewals', 'past_due' );
	const ACTIONS = array( 'subscriptions', 'mrr', 'renewals', 'past_due', 'charges', 'payouts' );
	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( static function () {
			wp_register_ability( 'datamachine/stripe-reports', array(
				'label' => 'Stripe Reports',
				'description' => 'Read-only, bounded Stripe subscription, MRR, renewal, delinquency, charge and payout reports. Restricted credentials remain server-side.',
				'category' => 'datamachine-analytics',
				'input_schema' => array(
					'type' => 'object', 'required' => array( 'action' ),
					'properties' => array(
						'action' => array( 'type' => 'string', 'enum' => self::ACTIONS ),
						'limit' => array( 'type' => 'integer', 'default' => 25, 'description' => 'Maximum source records per collection; clamped to 1–100.' ),
						'days' => array( 'type' => 'integer', 'default' => 7, 'description' => 'Renewal window; clamped to 1–365.' ),
					),
				),
				'output_schema' => self::outputSchema(),
				'execute_callback' => array( self::class, 'fetch' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta' => array( 'show_in_rest' => false ),
			) );
		} );
		self::$registered = true;
	}

	public static function outputSchema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'success', 'action', 'results', 'results_count', 'counts', 'provenance' ),
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'action' => array( 'type' => 'string', 'enum' => self::ACTIONS ),
				'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'results_count' => array( 'type' => 'integer' ),
				'counts' => array( 'type' => 'object' ),
				'provenance' => array( 'type' => 'object' ),
			),
		);
	}

	public static function get_config(): array {
		$config = get_option( self::CONFIG_OPTION, array() );
		return is_array( $config ) ? $config : array();
	}

	/** Prefix validation cannot verify dashboard permissions; operators must grant read only. */
	public static function validateKey( string $key ) {
		if ( ! preg_match( '/^rk_(live|test)_[A-Za-z0-9]+$/D', $key ) ) {
			return new \WP_Error( 'stripe_restricted_key_required', 'Create a read-only restricted Stripe key (rk_live_ or rk_test_). Secret keys (sk_) are not accepted.' );
		}
		return true;
	}

	/** @return array|\WP_Error */
	public static function fetch( array $input ) {
		$action = $input['action'] ?? '';
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'stripe_invalid_action', 'Invalid Stripe report action.' );
		}
		$key = (string) ( self::get_config()['api_key'] ?? '' );
		$valid = self::validateKey( $key );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$limit = max( 1, min( 100, (int) ( $input['limit'] ?? 25 ) ) );
		$days = max( 1, min( 365, (int) ( $input['days'] ?? 7 ) ) );
		$now = time();
		$collections = array();
		if ( 'past_due' === $action ) {
			$collections = array(
				array( '/invoices', array( 'status' => 'open' ) ),
				array( '/invoices', array( 'status' => 'uncollectible' ) ),
				array( '/subscriptions', array( 'status' => 'past_due', 'expand' => array( 'data.customer' ) ) ),
			);
		} elseif ( in_array( $action, array( 'charges', 'payouts' ), true ) ) {
			$collections[] = array( '/' . $action, array() );
		} else {
			$collections[] = array( '/subscriptions', array( 'status' => 'active', 'expand' => array( 'data.customer' ) ) );
		}
		$rows = array();
		$totals = array();
		$scanned = 0;
		$provenance = array();
		$scan_limit = in_array( $action, self::SCAN_ACTIONS, true ) ? self::PAGE_SIZE * self::MAX_PAGES : $limit;
		foreach ( $collections as list( $path, $query ) ) {
			$list = self::listRecords( $path, $query, $key, $scan_limit );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			$scanned += count( $list['data'] );
			$provenance[] = array( 'endpoint' => $path, 'status' => $query['status'] ?? null, 'pages' => $list['pages'], 'records' => count( $list['data'] ), 'truncated' => $list['truncated'] );
			foreach ( $list['data'] as $record ) {
				if ( '/subscriptions' === $path ) {
					if ( ! empty( $record['items']['has_more'] ) ) {
						return new \WP_Error( 'stripe_subscription_items_truncated', 'Stripe subscription items are truncated; refusing an incomplete report.' );
					}
					foreach ( self::subscriptionRows( $record ) as $row ) {
						if ( 'renewals' === $action && ( null === $row['current_period_end'] || $row['current_period_end'] < $now || $row['current_period_end'] > $now + $days * 86400 ) ) {
							continue;
						}
						if ( 'mrr' === $action ) {
							$factors = array( 'month' => 1, 'year' => 1 / 12, 'week' => 52 / 12, 'day' => 365 / 12 );
							if ( ! isset( $factors[ $row['interval'] ] ) || null === $row['amount'] ) {
								return new \WP_Error( 'stripe_unsupported_price', 'MRR requires fixed recurring prices with supported intervals; tiered or metered prices cannot be estimated.' );
							}
							$currency = $row['currency'];
							$totals[ $currency ] = ( $totals[ $currency ] ?? 0 ) + $row['amount'] * $factors[ $row['interval'] ] / $row['interval_count'];
						} else {
							$rows[] = $row;
						}
					}
				} elseif ( '/invoices' === $path ) {
					if ( empty( $record['due_date'] ) || $record['due_date'] >= $now ) {
						continue;
					}
					$rows[] = array( 'type' => 'invoice', 'id' => $record['id'], 'status' => $record['status'], 'customer' => self::customerId( $record['customer'] ?? null ), 'amount' => self::major( $record['amount_remaining'] ?? 0, $record['currency'] ), 'currency' => $record['currency'], 'due_date' => $record['due_date'] );
				} else {
					$row = array( 'id' => $record['id'], 'amount' => self::major( $record['amount'] ?? 0, $record['currency'] ), 'currency' => $record['currency'], 'status' => $record['status'] );
					foreach ( 'charges' === $action ? array( 'paid', 'refunded', 'created' ) : array( 'arrival_date' ) as $field ) {
						$row[ $field ] = $record[ $field ] ?? null;
					}
					if ( 'charges' === $action ) {
						$row['customer'] = self::customerId( $record['customer'] ?? null );
					}
					$rows[] = $row;
				}
			}
		}
		$matched = count( $rows );
		if ( 'mrr' !== $action ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		ksort( $totals );
		foreach ( $totals as $currency => $amount ) {
			$rows[] = array( 'currency' => $currency, 'mrr' => $amount );
		}
		$result = array(
			'success' => true, 'action' => $action, 'results' => $rows, 'results_count' => count( $rows ),
			'counts' => array( 'source_records' => $scanned, 'matched' => $matched, 'rows' => count( $rows ) ),
			'provenance' => array(
				'source' => array( 'ability' => 'datamachine/stripe-reports', 'action' => $action, 'api' => self::API_BASE, 'method' => 'GET' ),
				'collections' => $provenance, 'limit' => $limit, 'scan_limit' => $scan_limit, 'page_cap' => self::MAX_PAGES,
				'as_of' => $now, 'days' => $days, 'amount_units' => 'major',
				'complete' => ! in_array( true, array_column( $provenance, 'truncated' ), true ),
			),
		);
		// Defense in depth: even source strings reflecting credentials are redacted.
		return self::redact( $result, $key );
	}

	/** GET only. Never propagate raw transport messages, response bodies or credentials. */
	private static function listRecords( string $path, array $query, string $key, int $limit ) {
		$records = array();
		$cursor = null;
		$more = false;
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$query['limit'] = min( self::PAGE_SIZE, $limit - count( $records ) );
			if ( null !== $cursor ) {
				$query['starting_after'] = $cursor;
			}
			$response = HttpClient::get( self::API_BASE . $path . '?' . http_build_query( $query ), array(
				'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				'timeout' => 30, 'context' => 'Stripe reporting', 'log_response_body_preview' => false,
			) );
			$data = json_decode( (string) ( $response['data'] ?? '' ), true );
			if ( empty( $response['success'] ) || isset( $data['error'] ) ) {
				$status = (int) ( $response['status_code'] ?? 0 );
				$code = match ( $status ) { 401 => 'stripe_authentication', 403 => 'stripe_permission', 429 => 'stripe_rate_limit', default => 'stripe_api_error' };
				return new \WP_Error( $code, 'Stripe read request failed (HTTP ' . $status . '). Check restricted-key read permissions, account access, or retry later.' );
			}
			if ( ! is_array( $data ) || ! isset( $data['data'], $data['has_more'] ) || ! is_array( $data['data'] ) || ! is_bool( $data['has_more'] ) ) {
				return new \WP_Error( 'stripe_invalid_response', 'Stripe returned an invalid list response.' );
			}
			$records = array_merge( $records, array_slice( $data['data'], 0, $limit - count( $records ) ) );
			$more = $data['has_more'];
			if ( ! $more || count( $records ) >= $limit ) {
				break;
			}
			$last = end( $data['data'] );
			$next = $last['id'] ?? null;
			if ( ! is_string( $next ) || $next === $cursor ) {
				return new \WP_Error( 'stripe_invalid_cursor', 'Stripe pagination did not advance.' );
			}
			$cursor = $next;
		}
		return array( 'data' => $records, 'pages' => min( $page, self::MAX_PAGES ), 'truncated' => $more );
	}

	/** Subscription-item grain preserves mixed intervals/currencies without guessing. */
	private static function subscriptionRows( array $subscription ): array {
		$rows = array();
		$customer = $subscription['customer'] ?? null;
		foreach ( $subscription['items']['data'] ?? array() as $item ) {
			$price = $item['price'] ?? $item['plan'] ?? array();
			$recurring = $price['recurring'] ?? $price;
			$currency = strtolower( $price['currency'] ?? 'usd' );
			$amount = $price['unit_amount_decimal'] ?? $price['unit_amount'] ?? $price['amount'] ?? null;
			if ( 'metered' === ( $recurring['usage_type'] ?? '' ) ) {
				$amount = null;
			}
			$rows[] = array(
				'type' => 'subscription', 'id' => $subscription['id'], 'item_id' => $item['id'] ?? null,
				'customer' => self::customerId( $customer ),
				'customer_email' => is_array( $customer ) ? ( $customer['email'] ?? null ) : null,
				'customer_name' => is_array( $customer ) ? ( $customer['name'] ?? null ) : null,
				'amount' => null === $amount ? null : self::major( (float) $amount * ( $item['quantity'] ?? 1 ), $currency ),
				'currency' => $currency, 'interval' => $recurring['interval'] ?? null,
				'interval_count' => max( 1, (int) ( $recurring['interval_count'] ?? 1 ) ),
				'status' => $subscription['status'],
				'current_period_end' => $item['current_period_end'] ?? $subscription['current_period_end'] ?? null,
				'cancel_at_period_end' => (bool) ( $subscription['cancel_at_period_end'] ?? false ),
			);
		}
		return $rows;
	}

	private static function customerId( $customer ) {
		return is_array( $customer ) ? ( $customer['id'] ?? null ) : $customer;
	}

	private static function major( $amount, string $currency ): float {
		$zero = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
		$three = array( 'bhd', 'jod', 'kwd', 'omr', 'tnd' );
		$currency = strtolower( $currency );
		return (float) $amount / ( in_array( $currency, $zero, true ) ? 1 : ( in_array( $currency, $three, true ) ? 1000 : 100 ) );
	}

	private static function redact( $value, string $key ) {
		if ( is_array( $value ) ) {
			return array_map( static fn( $entry ) => self::redact( $entry, $key ), $value );
		}
		return is_string( $value ) ? str_replace( $key, '[redacted]', $value ) : $value;
	}
}
