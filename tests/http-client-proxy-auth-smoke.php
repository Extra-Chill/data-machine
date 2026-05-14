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

require_once __DIR__ . '/../inc/Core/HttpClient.php';

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

echo "\n[2] Redaction\n";

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

echo "\n[3] Proxy scheme mapping\n";

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
