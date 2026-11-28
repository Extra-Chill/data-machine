<?php
/**
 * Stripe Payment Link approval.
 *
 * The only code that writes to Stripe. A staged Payment Link exists purely as
 * a pending action: nothing is created in Stripe until a human approves it.
 *
 * - can_resolve: signed approve/reject link, or a site administrator.
 *   No agent grant is staged, so agents cannot approve their own requests.
 * - apply: consume the one-time receipt, re-check the request against the
 *   limits in force now, then create product -> price -> payment link with
 *   idempotency keys derived from the request, so a retried approval can
 *   never create a second link.
 * - rejected / expired: nothing to clean up, because nothing was created.
 *
 * @package DataMachineBusiness\Abilities\Stripe
 */

namespace DataMachineBusiness\Abilities\Stripe;

use DataMachine\Core\HttpClient;
use DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt;
use DataMachineBusiness\Abilities\Analytics\StripeReportsAbilities;

defined( 'ABSPATH' ) || exit;

final class StripePaymentLinkApproval {

	public const KIND = 'business_stripe_payment_link';

	/** Write credentials are stored apart from the read-only reporting key. */
	public const CONFIG_OPTION = 'datamachine_stripe_payment_link_config';

	private const API_BASE = 'https://api.stripe.com/v1';

	private static bool $registered = false;

	/** Hook into the pending-action resolver once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		add_filter( 'datamachine_pending_action_handlers', array( self::class, 'handlers' ) );
		self::$registered = true;
	}

	/**
	 * A CLI operator may resolve this kind. No agent grant.
	 */
	public static function resolver_grants(): array {
		return array(
			array(
				'type'  => 'operator',
				'kinds' => array( self::KIND ),
			),
		);
	}

	/**
	 * @param mixed $handlers Existing handler map.
	 */
	public static function handlers( $handlers ): array {
		$handlers               = is_array( $handlers ) ? $handlers : array();
		$handlers[ self::KIND ] = array(
			'apply'       => array( self::class, 'apply' ),
			'can_resolve' => array( self::class, 'can_resolve' ),
		);
		return $handlers;
	}

	/**
	 * Who may approve or reject a Payment Link.
	 *
	 * @param mixed $payload  Pending action payload.
	 * @param mixed $decision accepted|rejected.
	 * @param mixed $user_id  Current user during resolution.
	 * @param mixed $context  Resolver context.
	 * @return true|\WP_Error
	 */
	public static function can_resolve( $payload, $decision, $user_id, $context = array() ) {
		unset( $payload, $decision );
		if ( is_array( $context ) && 'signed_url' === ( $context['resolution_transport'] ?? '' ) ) {
			return true;
		}
		$user_id = (int) $user_id;
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'payment_link_requires_admin', 'Only a site administrator can approve a Stripe Payment Link.', array( 'status' => 403 ) );
	}

	/**
	 * Restricted write key, validated.
	 *
	 * @return string|\WP_Error
	 */
	public static function api_key() {
		$config = get_option( self::CONFIG_OPTION, array() );
		$key    = is_array( $config ) ? (string) ( $config['api_key'] ?? '' ) : '';
		$valid  = StripeReportsAbilities::validateKey( $key );
		if ( is_wp_error( $valid ) ) {
			return new \WP_Error( 'stripe_link_key_required', 'Configure a restricted Stripe key (rk_live_ or rk_test_) with write access to Products, Prices and Payment Links only. Secret keys (sk_) are not accepted.' );
		}
		return $key;
	}

	/**
	 * Create the approved Payment Link.
	 *
	 * Three parameters are required by the resolver's apply contract.
	 *
	 * @param array $input   Stored apply_input.
	 * @param array $payload Stored pending action payload.
	 * @param array $receipt One-time authorization receipt.
	 * @return array|\WP_Error
	 */
	public static function apply( array $input, array $payload, array $receipt ) {
		// Consume first: an approval can never be replayed into a second link.
		$subject    = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' );
		$workspace  = isset( $payload['workspace'] ) && is_array( $payload['workspace'] ) ? $payload['workspace'] : array();
		$authorized = PendingActionAuthorizationReceipt::consume( $receipt, self::KIND, self::KIND, $input, $input, $subject, $workspace );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		// Re-check against the limits in force now, not the ones at staging.
		$link = StripePaymentLinkPolicy::normalize( $input, StripePaymentLinkPolicy::limits() );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		if ( (int) ( $input['unit_amount'] ?? -1 ) !== $link['unit_amount'] ) {
			return new \WP_Error( 'stripe_link_amount_mismatch', 'The approved amount no longer matches the staged request.' );
		}
		$request_id = isset( $input['request_id'] ) && is_string( $input['request_id'] ) && preg_match( '/^[A-Za-z0-9-]{8,64}$/', $input['request_id'] ) ? $input['request_id'] : '';
		if ( '' === $request_id ) {
			return new \WP_Error( 'stripe_link_invalid_request', 'The staged request has no valid request_id.' );
		}

		$key = self::api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$product_fields = array(
			'name'                  => $link['name'],
			'metadata[datamachine]' => 'payment-link',
			'metadata[request_id]'  => $request_id,
		);
		if ( '' !== $link['description'] ) {
			$product_fields['description'] = $link['description'];
		}
		$product = self::post( '/products', $product_fields, $key, $request_id . '-product' );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$price_fields = array(
			'product'     => $product['id'],
			'unit_amount' => $link['unit_amount'],
			'currency'    => $link['currency'],
		);
		if ( 'once' !== $link['interval'] ) {
			$price_fields['recurring[interval]']       = $link['interval'];
			$price_fields['recurring[interval_count]'] = $link['interval_count'];
		}
		$price = self::post( '/prices', $price_fields, $key, $request_id . '-price' );
		if ( is_wp_error( $price ) ) {
			return $price;
		}

		$payment_link = self::post(
			'/payment_links',
			array(
				'line_items[0][price]'    => $price['id'],
				'line_items[0][quantity]' => 1,
				'metadata[datamachine]'   => 'payment-link',
				'metadata[request_id]'    => $request_id,
			),
			$key,
			$request_id . '-link'
		);
		if ( is_wp_error( $payment_link ) ) {
			return $payment_link;
		}
		$url = (string) ( $payment_link['url'] ?? '' );
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return new \WP_Error( 'stripe_invalid_response', 'Stripe did not return a Payment Link URL.' );
		}

		return array(
			'success'         => true,
			'status'          => 'created',
			'url'             => $url,
			'payment_link_id' => (string) ( $payment_link['id'] ?? '' ),
			'price_id'        => (string) $price['id'],
			'product_id'      => (string) $product['id'],
			'summary'         => StripePaymentLinkPolicy::summary( $link ),
		);
	}

	/**
	 * Form-encoded Stripe POST with an idempotency key.
	 *
	 * Never propagates raw transport messages, response bodies or credentials.
	 *
	 * @param string $path            API path.
	 * @param array  $fields          Flat form fields.
	 * @param string $key             Restricted key.
	 * @param string $idempotency_key Stable per request and step.
	 * @return array|\WP_Error Decoded object with a string id.
	 */
	private static function post( string $path, array $fields, string $key, string $idempotency_key ) {
		$response = HttpClient::post(
			self::API_BASE . $path,
			array(
				'headers'                   => array(
					'Authorization'   => 'Bearer ' . $key,
					'Content-Type'    => 'application/x-www-form-urlencoded',
					'Idempotency-Key' => 'datamachine-' . $idempotency_key,
				),
				'body'                      => http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ),
				'timeout'                   => 30,
				'context'                   => 'Stripe payment link',
				'log_response_body_preview' => false,
			)
		);
		$data     = json_decode( (string) ( $response['data'] ?? '' ), true );
		if ( empty( $response['success'] ) || isset( $data['error'] ) ) {
			$status = (int) ( $response['status_code'] ?? 0 );
			$code   = match ( $status ) {
				401     => 'stripe_authentication',
				403     => 'stripe_permission',
				429     => 'stripe_rate_limit',
				default => 'stripe_api_error',
			};
			return new \WP_Error( $code, 'Stripe write request failed (HTTP ' . $status . '). Check the restricted key has write access to Products, Prices and Payment Links, then retry.' );
		}
		if ( ! is_array( $data ) || ! isset( $data['id'] ) || ! is_string( $data['id'] ) ) {
			return new \WP_Error( 'stripe_invalid_response', 'Stripe returned an invalid response.' );
		}
		return $data;
	}
}
