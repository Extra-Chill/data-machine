<?php
/** Dependency-free Stripe contracts. Run: php tests/stripe-reports-smoke.php */
namespace DataMachine\Core {
	class HttpClient {
		public static array $requests = array();
		public static $handler;
		public static function get( $url, $options ) {
			self::$requests[] = array( 'method' => 'GET', 'url' => $url, 'options' => $options );
			return ( self::$handler )( $url, $options );
		}
	}
}
namespace DataMachine\Abilities {
	class AbilityRegistration {
		public static function on_abilities_api_init( $callback ) { $callback(); }
	}
	class PermissionHelper { public static function can_manage() { return true; } }
}
namespace DataMachine\Cli { class BaseCommand {} }
namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function get_option( $name, $default = array() ) { return $GLOBALS['config'] ?? $default; }
	function wp_register_ability( $name, $args ) { $GLOBALS['ability'] = array( $name, $args ); }
	function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
	require dirname( __DIR__ ) . '/inc/Abilities/Analytics/StripeReportsAbilities.php';
	require dirname( __DIR__ ) . '/inc/Cli/StripeCommand.php';
	require dirname( __DIR__ ) . '/inc/Cli/CommandRegistry.php';
	use DataMachineBusiness\Abilities\Analytics\StripeReportsAbilities as Stripe;
	use DataMachine\Core\HttpClient as HTTP;
	$failures = array(); $passes = 0;
	$assert = static function ( $ok, $label ) use ( &$failures, &$passes ) { if ( $ok ) { ++$passes; } else { $failures[] = $label; } };
	new Stripe();
	$assert( 'datamachine/stripe-reports' === $GLOBALS['ability'][0], 'registration' );
	$assert( false === $GLOBALS['ability'][1]['meta']['show_in_rest'], 'private REST' );
	$assert( 'datamachine-analytics' === $GLOBALS['ability'][1]['category'], 'category' );
	$outputs = array();
	$GLOBALS['config'] = array( 'api_key' => 'sk_live_DO_NOT_ECHO' );
	$error = Stripe::fetch( array( 'action' => 'mrr' ) );
	$assert( is_wp_error( $error ) && str_contains( $error->get_error_message(), 'read-only restricted' ), 'secret key rejected clearly' );
	$assert( empty( HTTP::$requests ), 'rejection before HTTP' );
	$outputs[] = $error->get_error_message();
	$key = 'rk_test_DONOTECHO';
	$GLOBALS['config'] = array( 'api_key' => $key );
	$sub = static function ( $id, $amount, $currency, $interval, $count = 1, $quantity = 1 ) use ( $key ) {
		return array( 'id' => $id, 'status' => 'active', 'customer' => array( 'id' => 'cus_1', 'email' => 'a@example.com', 'name' => $key ), 'cancel_at_period_end' => false, 'items' => array( 'has_more' => false, 'data' => array( array( 'id' => 'si_' . $id, 'current_period_end' => time() + 86400, 'quantity' => $quantity, 'price' => array( 'unit_amount' => $amount, 'currency' => $currency, 'recurring' => array( 'interval' => $interval, 'interval_count' => $count ) ) ) ) ) );
	};
	$subscriptions = array( $sub( 's1', 12000, 'usd', 'year' ), $sub( 's2', 1200, 'usd', 'week' ), $sub( 's3', 6000, 'usd', 'month', 3, 2 ), $sub( 's4', 12000, 'jpy', 'year' ), $sub( 's5', 1200, 'usd', 'day', 2 ) );
	$reply = static fn( $rows, $more = false ) => array( 'success' => true, 'status_code' => 200, 'data' => json_encode( array( 'data' => $rows, 'has_more' => $more ) ) );
	HTTP::$handler = static function ( $url ) use ( $reply, $subscriptions ) {
		if ( str_contains( $url, '/subscriptions?' ) ) { return $reply( $subscriptions ); }
		if ( str_contains( $url, '/invoices?' ) ) {
			parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
			return $reply( array( array( 'id' => 'in_' . $q['status'], 'status' => $q['status'], 'customer' => 'cus_1', 'amount_remaining' => 500, 'currency' => 'usd', 'due_date' => time() - 100 ), array( 'id' => 'future', 'status' => 'open', 'currency' => 'usd', 'due_date' => time() + 100 ) ) );
		}
		return $reply( array( array( 'id' => 'obj_1', 'amount' => 1500, 'currency' => 'usd', 'status' => 'succeeded', 'paid' => true, 'refunded' => false, 'customer' => 'cus_1', 'created' => 123, 'arrival_date' => 456 ) ) );
	};
	foreach ( Stripe::ACTIONS as $action ) {
		$r = Stripe::fetch( array( 'action' => $action ) );
		$outputs[] = $r;
		$assert( $r['success'] && $r['action'] === $action && count( $r['results'] ) === $r['results_count'], $action . ' envelope' );
		$assert( isset( $r['counts']['source_records'], $r['provenance']['source']['api'] ) && 25 === $r['provenance']['limit'], $action . ' counts/provenance/default' );
		if ( 'mrr' === $action ) {
			$totals = array_column( $r['results'], 'mrr', 'currency' );
			$assert( abs( $totals['usd'] - ( 10 + 52 + 40 + 182.5 ) ) < 0.00001, 'year/week/day/interval_count/quantity normalization' );
			$assert( 1000.0 === $totals['jpy'], 'JPY zero decimals' );
		}
		if ( 'subscriptions' === $action ) { $assert( isset( $r['results'][0]['customer_email'], $r['results'][0]['interval'], $r['results'][0]['current_period_end'] ), 'subscription fields' ); }
		if ( 'past_due' === $action ) { $assert( 7 === $r['results_count'] && 'invoice' === $r['results'][0]['type'], 'past due combines two invoice statuses and subscriptions, excludes future invoices' ); }
		if ( 'charges' === $action ) { $assert( 15.0 === $r['results'][0]['amount'] && true === $r['results'][0]['paid'], 'charge fields' ); }
		if ( 'payouts' === $action ) { $assert( 456 === $r['results'][0]['arrival_date'], 'payout fields' ); }
	}
	$i = 0;
	HTTP::$requests = array();
	HTTP::$handler = static function ( $url ) use ( &$i, $reply, $sub, $assert ) {
		parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
		$assert( 0 === $i || ( 's' . $i ) === $q['starting_after'], 'cursor advances' );
		++$i;
		return $reply( array( $sub( 's' . $i, 100, 'usd', 'month' ) ), true );
	};
	$r = Stripe::fetch( array( 'action' => 'mrr', 'limit' => 999 ) );
	$outputs[] = $r;
	$assert( Stripe::MAX_PAGES === count( HTTP::$requests ) && false === $r['provenance']['complete'], 'hard pagination cap and partial flag' );
	$assert( 100 === $r['provenance']['limit'], 'upper clamp' );
	$i = 0;
	$r = Stripe::fetch( array( 'action' => 'subscriptions', 'limit' => -2 ) );
	$outputs[] = $r;
	$assert( 1 === $r['provenance']['limit'] && 1 === $r['results_count'], 'lower clamp' );
	// Aggregates and filters must scan past the output limit, or MRR silently undercounts.
	$many = array();
	for ( $n = 1; $n <= 130; $n++ ) { $many[] = $sub( 'm' . $n, 1000, 'usd', 'month' ); }
	HTTP::$handler = static function ( $url ) use ( $reply, $many ) {
		parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
		$offset = isset( $q['starting_after'] ) ? (int) substr( $q['starting_after'], 1 ) : 0;
		$page = array_slice( $many, $offset, (int) $q['limit'] );
		return $reply( $page, $offset + count( $page ) < count( $many ) );
	};
	$before = count( HTTP::$requests );
	$r = Stripe::fetch( array( 'action' => 'mrr' ) );
	$assert( 1300.0 === (float) array_column( $r['results'], 'mrr', 'currency' )['usd'] && true === $r['provenance']['complete'], 'MRR scans beyond the default output limit' );
	$assert( 2 === count( HTTP::$requests ) - $before, 'scan paginates at Stripe page size' );
	$r = Stripe::fetch( array( 'action' => 'renewals' ) );
	$assert( 25 === $r['results_count'] && 130 === $r['counts']['matched'], 'renewals filter the full window, then apply the output limit' );
	foreach ( array_slice( HTTP::$requests, $before ) as $request ) {
		parse_str( parse_url( $request['url'], PHP_URL_QUERY ), $q );
		$assert( (int) $q['limit'] >= 1 && (int) $q['limit'] <= 100, 'Stripe page limit stays within 1-100' );
	}
	foreach ( array( 401 => 'stripe_authentication', 403 => 'stripe_permission', 429 => 'stripe_rate_limit', 500 => 'stripe_api_error' ) as $status => $code ) {
		HTTP::$handler = static fn() => array( 'success' => false, 'status_code' => $status, 'error' => $key, 'data' => json_encode( array( 'error' => array( 'message' => $key ) ) ) );
		$error = Stripe::fetch( array( 'action' => 'charges' ) );
		$assert( is_wp_error( $error ) && $code === $error->get_error_code(), 'API error ' . $status );
		$outputs[] = $error->get_error_message();
	}
	foreach ( HTTP::$requests as $request ) {
		$assert( 'GET' === $request['method'], 'GET only' );
		$assert( 'Bearer ' . $key === $request['options']['headers']['Authorization'] && false === $request['options']['log_response_body_preview'], 'private bearer and no body logging' );
		$assert( ! str_contains( $request['url'], $key ), 'key not in URL' );
	}
	$assert( ! str_contains( json_encode( $outputs ), $key ) && ! str_contains( json_encode( $outputs ), 'sk_live_DO_NOT_ECHO' ), 'keys never in output including reflected source fields and errors' );
	$assert( DataMachineBusiness\Cli\StripeCommand::class === DataMachineBusiness\Cli\CommandRegistry::map()['datamachine analytics stripe'], 'CLI registry' );
	if ( $failures ) { fwrite( STDERR, implode( "\n", $failures ) . "\n" ); exit( 1 ); }
	echo "Stripe reports smoke: {$passes} assertions passed.\n";
}
