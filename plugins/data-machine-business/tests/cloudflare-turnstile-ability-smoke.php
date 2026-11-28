<?php
/**
 * HTTP-boundary smoke coverage for the Cloudflare Turnstile abilities (#143).
 *
 * HttpClient is faked entirely in this namespace block — there is ZERO
 * network access in this test, live or otherwise. Every scenario asserts on
 * the fake's recorded calls (method, URL, body) as well as the ability's
 * returned array, so the read-modify-write contract (preserve name/mode,
 * idempotent no-op, dry-run-by-default) is verified at the HTTP boundary,
 * not just in memory.
 *
 * Run with: php tests/cloudflare-turnstile-ability-smoke.php
 */

namespace DataMachine\Core {
	/**
	 * Fake HttpClient. Queue one response per expected call in
	 * self::$responses; each call to get()/put() shifts the next one and
	 * records the call (method, url, options) into self::$calls.
	 */
	class HttpClient {
		public static array $responses = array();
		public static array $calls     = array();

		public static function get( string $url, array $options = array() ): array {
			return self::dispatch( 'GET', $url, $options );
		}

		public static function put( string $url, array $options = array() ): array {
			return self::dispatch( 'PUT', $url, $options );
		}

		private static function dispatch( string $method, string $url, array $options ): array {
			self::$calls[] = array(
				'method'  => $method,
				'url'     => $url,
				'options' => $options,
			);

			if ( empty( self::$responses ) ) {
				return array(
					'success' => false,
					'error'   => 'Test setup error: no fixture queued for this call.',
				);
			}

			return array_shift( self::$responses );
		}
	}
}

namespace {
	use DataMachineBusiness\Abilities\Cloudflare\CloudflareTurnstileAbilities;
	use DataMachine\Core\HttpClient;

	$root = dirname( __DIR__ );

	define( 'ABSPATH', $root . '/' );

	$failures         = array();
	$assertion_count  = 0;
	$GLOBALS['options_store'] = array();

	function get_site_option( string $key, $default = array() ) {
		return $GLOBALS['options_store'][ $key ] ?? $default;
	}

	function apply_filters( string $tag, $value ) {
		return $value;
	}

	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	require_once $root . '/inc/Abilities/Cloudflare/CloudflareTurnstileAbilities.php';

	$assert = static function ( bool $condition, string $message ) use ( &$failures, &$assertion_count ): void {
		++$assertion_count;
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	$fixture = static function ( array $body, bool $transport_success = true, int $status_code = 200, ?string $transport_error = null ): array {
		$response = array(
			'success'     => $transport_success,
			'status_code' => $status_code,
			'data'        => json_encode( $body ),
		);
		if ( ! $transport_success ) {
			$response['error'] = $transport_error ?? ( 'Cloudflare Turnstile API returned HTTP ' . $status_code );
		}
		return $response;
	};

	// --- Missing config names the option and never touches HTTP -----------
	$GLOBALS['options_store'] = array();
	$result = CloudflareTurnstileAbilities::list_widgets();
	$assert( false === $result['success'] && str_contains( $result['error'], 'datamachine_cloudflare_config' ), 'missing config names the option in the error message' );
	$assert( empty( HttpClient::$calls ), 'missing config never calls the HTTP layer' );

	// Configure for every remaining scenario.
	$GLOBALS['options_store']['datamachine_cloudflare_config'] = array(
		'api_token'  => 'secret-token',
		'account_id' => 'acct123',
	);

	// --- list_widgets parses the result array -------------------------------
	HttpClient::$calls     = array();
	HttpClient::$responses = array(
		$fixture(
			array(
				'success' => true,
				'errors'  => array(),
				'result'  => array(
					array( 'sitekey' => 'sk_a', 'name' => 'Widget A', 'mode' => 'managed', 'domains' => array( 'a.example.com' ) ),
					array( 'sitekey' => 'sk_b', 'name' => 'Widget B', 'mode' => 'managed', 'domains' => array( 'b.example.com' ) ),
				),
			)
		),
	);
	$list = CloudflareTurnstileAbilities::list_widgets();
	$assert( true === $list['success'] && 2 === $list['count'] && 'sk_a' === $list['widgets'][0]['sitekey'], 'list_widgets parses the Cloudflare result array' );
	$assert( 1 === count( HttpClient::$calls ) && 'GET' === HttpClient::$calls[0]['method'] && str_contains( HttpClient::$calls[0]['url'], '/accounts/acct123/challenges/widgets' ), 'list_widgets calls the account-scoped widgets endpoint' );
	$assert( 'secret-token' === HttpClient::$calls[0]['options']['auth']['token'], 'list_widgets authenticates with the configured bearer token' );

	// --- get_widget parses one widget by sitekey ----------------------------
	HttpClient::$calls     = array();
	HttpClient::$responses = array(
		$fixture(
			array(
				'success' => true,
				'errors'  => array(),
				'result'  => array( 'sitekey' => 'sk_a', 'name' => 'Widget A', 'mode' => 'managed', 'domains' => array( 'a.example.com', 'b.example.com' ) ),
			)
		),
	);
	$get = CloudflareTurnstileAbilities::get_widget( 'sk_a' );
	$assert( true === $get['success'] && 'Widget A' === $get['widget']['name'], 'get_widget parses one widget' );
	$assert( str_contains( HttpClient::$calls[0]['url'], '/challenges/widgets/sk_a' ), 'get_widget calls the sitekey-scoped endpoint' );

	// --- invalid hostnames are rejected before any HTTP call ----------------
	HttpClient::$calls = array();
	$invalid_scheme = CloudflareTurnstileAbilities::update_widget_domains( 'sk_a', array( 'https://bad.example.com' ), array(), false );
	$assert( false === $invalid_scheme['success'] && str_contains( $invalid_scheme['error'], 'Invalid hostname' ), 'rejects a hostname with a scheme' );

	$invalid_path = CloudflareTurnstileAbilities::update_widget_domains( 'sk_a', array( 'bad.example.com/path' ), array(), false );
	$assert( false === $invalid_path['success'] && str_contains( $invalid_path['error'], 'Invalid hostname' ), 'rejects a hostname with a path' );

	$invalid_wildcard = CloudflareTurnstileAbilities::update_widget_domains( 'sk_a', array( '*.example.com' ), array(), false );
	$assert( false === $invalid_wildcard['success'] && str_contains( $invalid_wildcard['error'], 'Invalid hostname' ), 'rejects a wildcard hostname' );
	$assert( empty( HttpClient::$calls ), 'invalid hostnames never reach the HTTP layer' );

	// --- dry run (apply=false) never issues a PUT and preserves name/mode --
	$widget_fixture = array(
		'sitekey' => 'sk_a',
		'name'    => 'Widget A',
		'mode'    => 'managed',
		'domains' => array( 'a.example.com', 'b.example.com' ),
	);

	HttpClient::$calls     = array();
	HttpClient::$responses = array(
		$fixture( array( 'success' => true, 'errors' => array(), 'result' => $widget_fixture ) ),
	);
	$dry_run = CloudflareTurnstileAbilities::update_widget_domains( 'sk_a', array( 'c.example.com' ), array(), false );
	$assert( true === $dry_run['success'] && true === $dry_run['dry_run'], 'add with apply=false returns a dry-run success' );
	$assert( array( 'a.example.com', 'b.example.com', 'c.example.com' ) === $dry_run['domains_after'], 'dry run computes the new domain list' );
	$assert( 'Widget A' === $dry_run['name'] && 'managed' === $dry_run['mode'], 'dry run response preserves name and mode' );
	$assert( 1 === count( HttpClient::$calls ) && 'GET' === HttpClient::$calls[0]['method'], 'dry run performs only the GET, never a PUT' );

	// --- add-existing is a no-op, even with apply=true, and never PUTs -----
	HttpClient::$calls     = array();
	HttpClient::$responses = array(
		$fixture( array( 'success' => true, 'errors' => array(), 'result' => $widget_fixture ) ),
	);
	$noop = CloudflareTurnstileAbilities::update_widget_domains( 'sk_a', array( 'a.example.com' ), array(), true );
	$assert( true === $noop['success'] && true === $noop['no_op'] && false === $noop['dry_run'], 'adding an already-present hostname is reported as a no-op' );
	$assert( 1 === count( HttpClient::$calls ), 'no-op add never issues a PUT even with apply=true' );

	// --- remove works; apply=true issues GET then exactly one PUT ----------
	HttpClient::$calls     = array();
	HttpClient::$responses = array(
		$fixture( array( 'success' => true, 'errors' => array(), 'result' => $widget_fixture ) ),
		$fixture( array( 'success' => true, 'errors' => array(), 'result' => array( 'sitekey' => 'sk_a', 'name' => 'Widget A', 'mode' => 'managed', 'domains' => array( 'a.example.com' ) ) ) ),
	);
	$remove = CloudflareTurnstileAbilities::update_widget_domains( 'sk_a', array(), array( 'b.example.com' ), true );
	$assert( true === $remove['success'] && false === $remove['dry_run'], 'remove with apply=true actually writes' );
	$assert( array( 'b.example.com' ) === $remove['removed'], 'reports the removed hostname' );
	$assert( array( 'a.example.com' ) === $remove['domains_after'], 'computes the domain list with the hostname removed' );
	$assert( 2 === count( HttpClient::$calls ) && 'GET' === HttpClient::$calls[0]['method'] && 'PUT' === HttpClient::$calls[1]['method'], 'apply=true issues a GET then exactly one PUT' );

	$put_body = json_decode( HttpClient::$calls[1]['options']['body'], true );
	$assert( 'Widget A' === $put_body['name'] && 'managed' === $put_body['mode'] && array( 'a.example.com' ) === $put_body['domains'], 'PUT body preserves name and mode and sends the full new domain list' );

	// --- mixed add+remove preserves untouched domains, lowercases input ----
	$mixed_widget = array(
		'sitekey' => 'sk_a',
		'name'    => 'Example Network',
		'mode'    => 'managed',
		'domains' => array( 'example.com', 'www.example.com', 'old.example.com' ),
	);
	HttpClient::$calls     = array();
	HttpClient::$responses = array(
		$fixture( array( 'success' => true, 'errors' => array(), 'result' => $mixed_widget ) ),
		$fixture( array( 'success' => true, 'errors' => array(), 'result' => array() ) ),
	);
	$mixed = CloudflareTurnstileAbilities::update_widget_domains( 'sk_a', array( 'Example.NET' ), array( 'old.example.com' ), true );
	$assert( array( 'example.com', 'www.example.com', 'example.net' ) === $mixed['domains_after'], 'mixed add+remove preserves untouched domains and lowercases the added hostname' );

	$put_body_mixed = json_decode( HttpClient::$calls[1]['options']['body'], true );
	$assert( 'Example Network' === $put_body_mixed['name'] && array( 'example.com', 'www.example.com', 'example.net' ) === $put_body_mixed['domains'], 'PUT body reflects the same preserved name and final domain list reported to the caller' );

	// --- Cloudflare in-band error (HTTP 200, body success:false) surfaced --
	HttpClient::$calls     = array();
	HttpClient::$responses = array(
		$fixture(
			array(
				'success' => false,
				'errors'  => array( array( 'code' => 1003, 'message' => 'Invalid sitekey' ) ),
				'result'  => null,
			)
		),
	);
	$cf_inband_error = CloudflareTurnstileAbilities::get_widget( 'bad-sitekey' );
	$assert( false === $cf_inband_error['success'] && 'Invalid sitekey (code 1003)' === $cf_inband_error['error'], 'surfaces an in-band Cloudflare errors[] message with its code' );

	// --- Cloudflare HTTP-level error (403) surfaces errors[] + status code -
	HttpClient::$calls     = array();
	HttpClient::$responses = array(
		$fixture(
			array(
				'success' => false,
				'errors'  => array( array( 'code' => 10000, 'message' => 'Authentication error' ) ),
			),
			false,
			403
		),
	);
	$auth_error = CloudflareTurnstileAbilities::list_widgets();
	$assert( false === $auth_error['success'] && 'Authentication error (code 10000)' === $auth_error['error'] && 403 === $auth_error['status_code'], 'surfaces the HTTP-level Cloudflare error body and status code' );

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, "FAILED: " . count( $failures ) . " Cloudflare Turnstile smoke assertion(s) failed.\n" );
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "- {$failure}\n" );
		}
		exit( 1 );
	}

	echo "{$assertion_count} assertions passed.\n";
}
