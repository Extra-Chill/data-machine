<?php
/**
 * Smoke coverage for the generic HTTP Basic auth provider.
 *
 * Run with: php tests/http-basic-auth-provider-smoke.php
 */

use DataMachine\Core\OAuth\BaseAuthProvider;
use DataMachine\Core\OAuth\HttpBasicAuthProvider;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = '' ): string {
    	unset( $domain );
    	return $text;
    }
}

if ( ! function_exists( 'get_site_option' ) ) {
    function get_site_option( string $name, mixed $default = false ): mixed {
    	return $GLOBALS['datamachine_http_basic_options'][ $name ] ?? $default;
    }
}

if ( ! function_exists( 'update_site_option' ) ) {
    function update_site_option( string $name, mixed $value ): bool {
    	$GLOBALS['datamachine_http_basic_options'][ $name ] = $value;
    	return true;
    }
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'http-basic-smoke-salt-' . $scheme;
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
    	if ( 'datamachine_auth_encrypted_fields' === $hook && 'http_basic' === ( $args[0] ?? '' ) ) {
    		$value[] = 'password';
    	}
    	return $value;
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $thing ): bool {
    	return $thing instanceof \WP_Error;
    }
}

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

require_once dirname( __DIR__ ) . '/inc/Core/OAuth/BaseAuthProvider.php';
require_once dirname( __DIR__ ) . '/inc/Core/OAuth/HttpBasicAuthProvider.php';

$provider = new HttpBasicAuthProvider();
$provider->save_config(
	array(
		'account'   => 'logstash',
		'username'  => 'chubes4',
		'password'  => 'secret-password',
		'proxy_url' => 'socks5://127.0.0.1:8080',
	)
);

$stored = $GLOBALS['datamachine_http_basic_options']['datamachine_auth_data']['http_basic']['config']['password'] ?? '';
if ( ! is_string( $stored ) || ! str_starts_with( $stored, BaseAuthProvider::ENCRYPTION_PREFIX ) ) {
	fwrite( fopen( 'php://stderr', 'w' ), "password was not encrypted at rest\n" );
	exit( 1 );
}

$resolved = $provider->resolve_auth_ref( 'logstash' );
if ( is_wp_error( $resolved ) ) {
	fwrite( fopen( 'php://stderr', 'w' ), "auth ref unexpectedly failed\n" );
	exit( 1 );
}

if ( 'basic' !== ( $resolved['auth']['type'] ?? '' ) || 'chubes4' !== ( $resolved['auth']['username'] ?? '' ) || 'secret-password' !== ( $resolved['auth']['password'] ?? '' ) ) {
	fwrite( fopen( 'php://stderr', 'w' ), "auth ref did not resolve Basic credentials\n" );
	exit( 1 );
}

if ( 'socks5://127.0.0.1:8080' !== ( $resolved['proxy_url'] ?? '' ) ) {
	fwrite( fopen( 'php://stderr', 'w' ), "auth ref did not resolve proxy URL\n" );
	exit( 1 );
}

echo "=== http-basic-auth-provider-smoke: ALL PASS ===\n";
