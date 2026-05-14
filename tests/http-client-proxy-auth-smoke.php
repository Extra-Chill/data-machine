<?php
/**
 * Smoke coverage for generic HttpClient proxy/auth request options.
 *
 * Run with: php tests/http-client-proxy-auth-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url(): string {
		return 'https://example.test';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		$parts = parse_url( $url );
		if ( -1 === $component ) {
			return $parts;
		}

		$map = array(
			PHP_URL_SCHEME => 'scheme',
			PHP_URL_HOST   => 'host',
			PHP_URL_PORT   => 'port',
			PHP_URL_USER   => 'user',
			PHP_URL_PASS   => 'pass',
			PHP_URL_PATH   => 'path',
			PHP_URL_QUERY  => 'query',
			PHP_URL_FRAGMENT => 'fragment',
		);

		$key = $map[ $component ] ?? null;
		return null === $key ? null : ( $parts[ $key ] ?? null );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		if ( 'datamachine_auth_providers' === $hook ) {
			return $GLOBALS['datamachine_http_client_auth_providers'] ?? $value;
		}

		if ( 'datamachine_auth_encrypted_fields' === $hook && 'http_basic' === ( $args[0] ?? '' ) ) {
			$value[] = 'password';
		}

		return $value;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['datamachine_http_client_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( string $name, mixed $value ): bool {
		$GLOBALS['datamachine_http_client_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'http-client-smoke-salt-' . $scheme;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = ''
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require_once __DIR__ . '/../inc/Engine/Bundle/BundleValidationException.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AuthRef.php';
require_once __DIR__ . '/../inc/Core/OAuth/BaseAuthProvider.php';
require_once __DIR__ . '/../inc/Core/OAuth/HttpBasicAuthProvider.php';
require_once __DIR__ . '/../inc/Core/HttpClient.php';

use DataMachine\Core\OAuth\HttpBasicAuthProvider;
use DataMachine\Core\HttpClient;

$failed = 0;
$total  = 0;

function http_client_smoke_assert( string $label, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failed;
	echo "FAIL: {$label}\n";
}

function http_client_private( string $method, mixed ...$args ): mixed {
	$ref = new ReflectionClass( HttpClient::class );
	$m   = $ref->getMethod( $method );
	return $m->invokeArgs( null, $args );
}

echo "\n[1] Standard auth options\n";

$basic_args = http_client_private(
	'buildRequestArgs',
	'GET',
	array(
		'auth' => array(
			'type'     => 'basic',
			'username' => 'chubes4',
			'password' => 'secret-password',
		),
	)
);

http_client_smoke_assert(
	'basic auth creates Authorization header',
	'Basic ' . base64_encode( 'chubes4:secret-password' ) === ( $basic_args['headers']['Authorization'] ?? null )
);

$bearer_args = http_client_private(
	'buildRequestArgs',
	'GET',
	array(
		'auth' => array(
			'type'  => 'bearer',
			'token' => 'bearer-token',
		),
	)
);

http_client_smoke_assert(
	'bearer auth creates Authorization header',
	'Bearer bearer-token' === ( $bearer_args['headers']['Authorization'] ?? null )
);

$manual_args = http_client_private(
	'buildRequestArgs',
	'GET',
	array(
		'headers' => array( 'authorization' => 'Custom manual' ),
		'auth'    => array(
			'type'  => 'bearer',
			'token' => 'ignored-token',
		),
	)
);

http_client_smoke_assert(
	'pre-set Authorization header is preserved case-insensitively',
	'Custom manual' === ( $manual_args['headers']['authorization'] ?? null )
);

echo "\n[2] Auth ref options\n";

$provider = new HttpBasicAuthProvider();
$provider->save_config(
	array(
		'account'   => 'logstash',
		'username'  => 'chubes4',
		'password'  => 'secret-password',
		'proxy_url' => 'socks5://127.0.0.1:8080',
	)
);
$GLOBALS['datamachine_http_client_auth_providers'] = array(
	HttpBasicAuthProvider::PROVIDER_SLUG => $provider,
);

$resolved_options = http_client_private(
	'resolveAuthRefOptions',
	array(
		'auth_ref' => 'http_basic:logstash',
	),
	'Logstash test'
);

http_client_smoke_assert( 'auth_ref resolves auth options', is_array( $resolved_options ) && 'basic' === ( $resolved_options['auth']['type'] ?? null ) );
http_client_smoke_assert( 'auth_ref resolves proxy URL', is_array( $resolved_options ) && 'socks5://127.0.0.1:8080' === ( $resolved_options['proxy_url'] ?? null ) );

$auth_ref_args = http_client_private( 'buildRequestArgs', 'GET', is_array( $resolved_options ) ? $resolved_options : array() );
http_client_smoke_assert(
	'auth_ref resolved Basic header is applied',
	'Basic ' . base64_encode( 'chubes4:secret-password' ) === ( $auth_ref_args['headers']['Authorization'] ?? null )
);

echo "\n[3] Redaction\n";

$redacted = http_client_private(
	'redactRequestArgsForLog',
	array(
		'headers' => array(
			'Authorization'       => 'Bearer top-secret',
			'Proxy-Authorization' => 'Basic hidden',
			'Cookie'              => 'wordpress_logged_in=hidden',
			'X-Test'              => 'visible',
		),
	)
);

http_client_smoke_assert( 'Authorization is redacted', '[redacted]' === ( $redacted['headers']['Authorization'] ?? null ) );
http_client_smoke_assert( 'Proxy-Authorization is redacted', '[redacted]' === ( $redacted['headers']['Proxy-Authorization'] ?? null ) );
http_client_smoke_assert( 'Cookie is redacted', '[redacted]' === ( $redacted['headers']['Cookie'] ?? null ) );
http_client_smoke_assert( 'Non-sensitive header remains visible', 'visible' === ( $redacted['headers']['X-Test'] ?? null ) );

echo "\n[4] Proxy scheme mapping\n";

http_client_smoke_assert(
	'socks5 proxy scheme is recognized when cURL exposes the constant',
	! defined( 'CURLPROXY_SOCKS5' ) || CURLPROXY_SOCKS5 === http_client_private( 'curlProxyTypeForScheme', 'socks5' )
);
http_client_smoke_assert( 'unknown proxy scheme is ignored', null === http_client_private( 'curlProxyTypeForScheme', 'ftp' ) );

if ( 0 === $failed ) {
	echo "\n=== http-client-proxy-auth-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}

echo "\n=== http-client-proxy-auth-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
