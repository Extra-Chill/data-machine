<?php
/**
 * Dependency-free contracts for approval-gated Stripe Payment Links.
 *
 * Run: php tests/stripe-payment-link-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Isolated WordPress test doubles.
// phpcs:disable WordPress.Files.FileName -- Standalone test entry point.
// phpcs:disable WordPress.PHP.DevelopmentFunctions -- Unit test assertions.
// phpcs:disable Squiz.Commenting -- Minimal isolated test doubles.
// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO -- Isolated doubles share one executable test.
// phpcs:disable PSR1.Classes.ClassDeclaration, PSR1.Files.SideEffects, Squiz.Classes.ClassFileName -- Isolated doubles share one executable test.
// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile, Universal.Namespaces.DisallowDeclarationWithoutName -- Namespaced dependency doubles and global WordPress functions.
// phpcs:disable Generic.NamingConventions.CamelCapsFunctionName, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames -- WordPress signatures.
// phpcs:disable WordPress.WP.GlobalVariablesOverride -- Isolated fixture variables.
// phpcs:disable WordPress.Security.EscapeOutput -- CLI test output.

namespace DataMachine\Core {
	class HttpClient {
		public static array $requests = array();
		public static $handler;
		public static function get( $url, $options ) {
			self::$requests[] = array( 'method' => 'GET', 'url' => $url, 'options' => $options );
			return ( self::$handler )( 'GET', $url, $options );
		}
		public static function post( $url, $options ) {
			self::$requests[] = array( 'method' => 'POST', 'url' => $url, 'options' => $options );
			return ( self::$handler )( 'POST', $url, $options );
		}
	}
}
namespace DataMachine\Abilities {
	class AbilityRegistration {
		public static function on_abilities_api_init( $callback ) {
			$callback();
		}
	}
	class PermissionHelper {
		public static function can_manage() {
			return true;
		}
	}
}
namespace DataMachine\Engine\AI\Actions {
	class PendingActionHelper {
		public static array $staged = array();
		public static function stage( array $args ): array {
			self::$staged[] = $args;
			return array(
				'staged'  => true,
				'payload' => array( 'action_id' => 'act_00000000-0000-0000-0000-000000000001' ),
			);
		}
	}
	class SignPendingActionResolutionAbility {
		public static function execute( array $input ): array {
			return array(
				'success'     => true,
				'approve_url' => 'https://example.org/approve/' . $input['action_id'],
				'reject_url'  => 'https://example.org/reject/' . $input['action_id'],
				'expires_at'  => '2026-11-04T00:00:00Z',
			);
		}
	}
	class PendingActionAuthorizationReceipt {
		public static $result = true;
		public static array $calls = array();
		public static function consume( $receipt, $kind, $operation, $target, $input, $subject, $workspace ) {
			self::$calls[] = func_get_args();
			return self::$result;
		}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'DAY_IN_SECONDS', 86400 );
	class WP_Error {
		public function __construct( private string $code, private string $message = '', private $data = null ) {}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
	function is_wp_error( $v ) {
		return $v instanceof WP_Error;
	}
	function get_option( $name, $default = array() ) {
		return $GLOBALS['options'][ $name ] ?? $default;
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function add_filter( $hook, $callback ) {
		$GLOBALS['filters'][ $hook ] = $callback;
	}
	function user_can( $user_id, $cap ) {
		return 1 === $user_id && 'manage_options' === $cap;
	}
	function wp_generate_uuid4() {
		return '11111111-2222-4333-8444-555555555555';
	}
	function wp_register_ability( $name, $args ) {
		$GLOBALS['ability'] = array( $name, $args );
	}
	require dirname( __DIR__ ) . '/inc/Abilities/Stripe/StripeMoney.php';
	require dirname( __DIR__ ) . '/inc/Abilities/Analytics/StripeReportsAbilities.php';
	require dirname( __DIR__ ) . '/inc/Abilities/Stripe/StripePaymentLinkPolicy.php';
	require dirname( __DIR__ ) . '/inc/Abilities/Stripe/StripePaymentLinkApproval.php';
	require dirname( __DIR__ ) . '/inc/Abilities/Stripe/StripePaymentLinkAbility.php';

	use DataMachineBusiness\Abilities\Stripe\StripePaymentLinkAbility as Link;
	use DataMachineBusiness\Abilities\Stripe\StripePaymentLinkApproval as Approval;
	use DataMachineBusiness\Abilities\Stripe\StripePaymentLinkPolicy as Policy;
	use DataMachine\Core\HttpClient as HTTP;
	use DataMachine\Engine\AI\Actions\PendingActionHelper as Pending;
	use DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt as Receipt;

	$failures = array();
	$passes   = 0;
	$assert   = static function ( $ok, $label ) use ( &$failures, &$passes ) {
		if ( $ok ) {
			++$passes;
		} else {
			$failures[] = $label;
		}
	};
	$code     = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'not-an-error';
	$outputs  = array();
	$key      = 'rk_test_WRITEKEYDONOTECHO';

	HTTP::$handler = static function () {
		throw new \RuntimeException( 'Unexpected HTTP call' );
	};

	new Link();
	$assert( 'datamachine/stripe-stage-payment-link' === $GLOBALS['ability'][0], 'ability registered' );
	$assert( false === $GLOBALS['ability'][1]['meta']['show_in_rest'], 'not exposed in REST' );
	$assert( isset( $GLOBALS['filters']['datamachine_pending_action_handlers'] ), 'approval handler hooked' );
	$handlers = $GLOBALS['filters']['datamachine_pending_action_handlers']( array() );
	$assert( isset( $handlers[ Approval::KIND ]['apply'], $handlers[ Approval::KIND ]['can_resolve'] ), 'handler provides apply and can_resolve' );

	// No write key: refuse before staging.
	$monthly = array(
		'name'     => 'Hosting + support',
		'amount'   => 50,
		'interval' => 'month',
	);
	$assert( 'stripe_link_key_required' === $code( Link::stage( $monthly ) ) && empty( Pending::$staged ), 'missing key refused before staging' );
	$GLOBALS['options'][ Approval::CONFIG_OPTION ] = array( 'api_key' => 'sk_live_SECRETDONOTECHO' );
	$error = Link::stage( $monthly );
	$assert( 'stripe_link_key_required' === $code( $error ) && empty( Pending::$staged ), 'sk_ secret key refused before staging' );
	$outputs[]                                     = $error->get_error_message();
	$GLOBALS['options'][ Approval::CONFIG_OPTION ] = array( 'api_key' => $key );

	// Stage: validated, no Stripe calls, operator-only grant.
	$staged = Link::stage( $monthly );
	$outputs[] = $staged;
	$args      = end( Pending::$staged );
	$assert( true === $staged['success'] && 'pending_approval' === $staged['status'], 'stage succeeds' );
	$assert( empty( HTTP::$requests ), 'staging makes no Stripe calls' );
	$assert( Approval::KIND === $args['kind'] && Link::APPROVAL_TTL === $args['ttl'], 'staged kind and TTL' );
	$assert( 5000 === $args['apply_input']['unit_amount'] && 'usd' === $args['apply_input']['currency'] && 'month' === $args['apply_input']['interval'] && 1 === $args['apply_input']['interval_count'], 'apply_input normalized to minor units' );
	$assert( '11111111-2222-4333-8444-555555555555' === $args['apply_input']['request_id'], 'request_id staged for idempotency' );
	$assert( 'Create a Stripe Payment Link "Hosting + support": 50.00 USD, every month' === $args['summary'], 'human summary' );
	$assert( array( 'operator' ) === array_column( $args['resolver_grants'], 'type' ), 'only an operator grant is staged' );
	$assert( str_contains( $staged['links']['approve_url'], 'act_' ) && isset( $staged['links']['reject_url'] ), 'signed approve and reject links' );

	// Policy rejections, all before staging.
	$count = count( Pending::$staged );
	$bad   = array(
		'stripe_link_amount_over_limit'    => array( 'amount' => 500.01 ),
		'stripe_link_invalid_amount'       => array( 'amount' => 0 ),
		'stripe_link_currency_not_allowed' => array( 'currency' => 'eur' ),
		'stripe_link_invalid_interval'     => array( 'interval' => 'hourly' ),
		'stripe_link_invalid_name'         => array( 'name' => "Line one\nLine two" ),
	);
	foreach ( $bad as $expected => $override ) {
		$assert( $expected === $code( Link::stage( array_merge( $monthly, $override ) ) ), 'rejects ' . $expected . ' ' . wp_json_encode_stub( $override ) );
	}
	foreach ( array( -5, NAN, INF, 'fifty' ) as $amount ) {
		$assert( 'stripe_link_invalid_amount' === $code( Link::stage( array_merge( $monthly, array( 'amount' => $amount ) ) ) ), 'rejects amount ' . var_export( $amount, true ) );
	}
	foreach ( array( array( 'month', 13 ), array( 'year', 2 ), array( 'week', 0 ), array( 'month', 1.5 ) ) as list( $interval, $n ) ) {
		$assert( 'stripe_link_invalid_interval' === $code( Link::stage( array_merge( $monthly, array( 'interval' => $interval, 'interval_count' => $n ) ) ) ), "rejects {$interval} x {$n}" );
	}
	$assert( 'stripe_link_invalid_name' === $code( Link::stage( array_merge( $monthly, array( 'name' => '' ) ) ) ), 'rejects empty name' );
	$assert( count( Pending::$staged ) === $count && empty( HTTP::$requests ), 'rejected requests are never staged or sent' );
	$assert( 500 * 100 === Policy::normalize( array_merge( $monthly, array( 'amount' => 500 ) ), Policy::limits() )['unit_amount'], 'limit is inclusive' );

	// Approval: receipt consumed first.
	$input   = $args['apply_input'];
	$payload = array( 'creator' => 'user:1' );
	Receipt::$result = new WP_Error( 'authorization_receipt_mismatch', 'mismatch' );
	$assert( 'authorization_receipt_mismatch' === $code( Approval::apply( $input, $payload, array( 'token' => 'x' ) ) ) && empty( HTTP::$requests ), 'invalid receipt stops before any Stripe call' );
	Receipt::$result = true;

	// Re-check against limits in force at approval time.
	$GLOBALS['options'][ Policy::LIMITS_OPTION ] = array( 'max_amount' => 40 );
	$assert( 'stripe_link_amount_over_limit' === $code( Approval::apply( $input, $payload, array() ) ) && empty( HTTP::$requests ), 'tightened limit blocks a pending approval' );
	unset( $GLOBALS['options'][ Policy::LIMITS_OPTION ] );
	$tampered                = $input;
	$tampered['unit_amount'] = 1;
	$assert( 'stripe_link_amount_mismatch' === $code( Approval::apply( $tampered, $payload, array() ) ) && empty( HTTP::$requests ), 'amount mismatch is refused' );
	$no_id = $input;
	unset( $no_id['request_id'] );
	$assert( 'stripe_link_invalid_request' === $code( Approval::apply( $no_id, $payload, array() ) ), 'missing request_id is refused' );

	// Successful approval creates product -> price -> link.
	$ids           = array(
		'/products'      => 'prod_1',
		'/prices'        => 'price_1',
		'/payment_links' => 'plink_1',
	);
	HTTP::$handler = static function ( $method, $url ) use ( $ids ) {
		$path = substr( $url, strlen( 'https://api.stripe.com/v1' ) );
		$body = array( 'id' => $ids[ $path ] );
		if ( '/payment_links' === $path ) {
			$body['url'] = 'https://buy.stripe.com/test_123';
		}
		return array(
			'success'     => true,
			'status_code' => 200,
			'data'        => json_encode( $body ),
		);
	};
	$result    = Approval::apply( $input, $payload, array( 'token' => 'ok' ) );
	$outputs[] = $result;
	$assert( true === $result['success'] && 'https://buy.stripe.com/test_123' === $result['url'] && 'plink_1' === $result['payment_link_id'], 'approval returns the Payment Link URL' );
	$assert( array( '/products', '/prices', '/payment_links' ) === array_map( static fn( $r ) => substr( $r['url'], 25 ), HTTP::$requests ), 'creates product, price, link in order' );
	$assert( array( 'POST', 'POST', 'POST' ) === array_column( HTTP::$requests, 'method' ), 'only POSTs after approval' );
	parse_str( HTTP::$requests[1]['options']['body'], $price_body );
	$assert( 'prod_1' === $price_body['product'] && '5000' === $price_body['unit_amount'] && 'month' === $price_body['recurring']['interval'] && '1' === $price_body['recurring']['interval_count'], 'recurring price body' );
	parse_str( HTTP::$requests[2]['options']['body'], $link_body );
	$assert( 'price_1' === $link_body['line_items'][0]['price'] && '1' === $link_body['line_items'][0]['quantity'], 'payment link body' );
	$keys = array_map( static fn( $r ) => $r['options']['headers']['Idempotency-Key'], HTTP::$requests );
	$assert( 3 === count( array_unique( $keys ) ) && str_contains( $keys[0], $input['request_id'] ), 'distinct idempotency keys derived from request_id' );
	$first_keys     = $keys;
	HTTP::$requests = array();
	Approval::apply( $input, $payload, array( 'token' => 'ok' ) );
	$assert( array_map( static fn( $r ) => $r['options']['headers']['Idempotency-Key'], HTTP::$requests ) === $first_keys, 'retried approval reuses the same idempotency keys' );
	foreach ( HTTP::$requests as $request ) {
		$assert( 'Bearer ' . $key === $request['options']['headers']['Authorization'] && false === $request['options']['log_response_body_preview'], 'bearer auth, no body logging' );
		$assert( ! str_contains( $request['url'] . $request['options']['body'], $key ), 'key not in URL or body' );
	}

	// One-time price has no recurring fields.
	HTTP::$requests = array();
	$once           = Link::stage( array( 'name' => 'Setup fee', 'amount' => 25 ) );
	Approval::apply( $once['request'], $payload, array() );
	parse_str( HTTP::$requests[1]['options']['body'], $once_body );
	$assert( ! isset( $once_body['recurring'] ) && '2500' === $once_body['unit_amount'], 'one-time price is not recurring' );
	$assert( str_contains( $once['summary'], 'one-time' ), 'one-time summary' );

	// Zero-decimal currency when allowed.
	$GLOBALS['options'][ Policy::LIMITS_OPTION ] = array( 'max_amount' => 10000, 'currencies' => array( 'USD', 'jpy' ) );
	$jpy = Link::stage( array( 'name' => 'Yen', 'amount' => 5000, 'currency' => 'JPY', 'interval' => 'year' ) );
	$assert( 5000 === $jpy['request']['unit_amount'] && 'jpy' === $jpy['request']['currency'], 'JPY stays in whole units' );
	unset( $GLOBALS['options'][ Policy::LIMITS_OPTION ] );

	// Stripe errors are mapped, sanitized, and stop the chain.
	foreach ( array( 401 => 'stripe_authentication', 403 => 'stripe_permission', 429 => 'stripe_rate_limit', 500 => 'stripe_api_error' ) as $status => $expected ) {
		HTTP::$requests = array();
		HTTP::$handler  = static fn() => array(
			'success'     => false,
			'status_code' => $status,
			'error'       => $key,
			'data'        => json_encode( array( 'error' => array( 'message' => 'Invalid API Key provided: ' . $key ) ) ),
		);
		$error          = Approval::apply( $input, $payload, array() );
		$assert( $expected === $code( $error ) && 1 === count( HTTP::$requests ), "HTTP {$status} maps to {$expected} and stops" );
		$outputs[] = $error->get_error_message();
	}

	// Who may resolve.
	$assert( true === Approval::can_resolve( array(), 'accepted', 0, array( 'resolution_transport' => 'signed_url' ) ), 'signed link may resolve' );
	$assert( true === Approval::can_resolve( array(), 'accepted', 1 ), 'administrator may resolve' );
	$assert( 'payment_link_requires_admin' === $code( Approval::can_resolve( array(), 'accepted', 2 ) ), 'non-administrator may not resolve' );

	$assert( ! str_contains( json_encode( $outputs ), $key ) && ! str_contains( json_encode( $outputs ), 'sk_live_SECRETDONOTECHO' ), 'keys never appear in output' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}
	echo "Stripe payment link smoke: {$passes} assertions passed.\n";

	function wp_json_encode_stub( $value ) {
		return json_encode( $value );
	}
}
