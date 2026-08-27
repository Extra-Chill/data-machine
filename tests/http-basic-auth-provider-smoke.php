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

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
    	return json_encode( $data, $flags );
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

$fail = static function ( string $message ): void {
	fwrite( fopen( 'php://stderr', 'w' ), $message . "\n" );
	exit( 1 );
};

$stored_accounts = static function (): array {
	return $GLOBALS['datamachine_http_basic_options']['datamachine_auth_data']['http_basic']['config']['accounts'] ?? array();
};

$provider->save_config(
	array(
		'account'   => 'logstash',
		'username'  => 'chubes4',
		'password'  => 'secret-password',
		'proxy_url' => 'socks5://127.0.0.1:8080',
	)
);

// Secrets nest one level deeper now, and the base encrypt pass only walks top-level fields --
// so this asserts the provider encrypts each account itself rather than storing it in the clear.
$stored = $stored_accounts()['logstash']['password'] ?? '';
if ( ! is_string( $stored ) || ! str_starts_with( $stored, BaseAuthProvider::ENCRYPTION_PREFIX ) ) {
	$fail( 'password was not encrypted at rest' );
}
if ( str_contains( wp_json_encode( $GLOBALS['datamachine_http_basic_options'] ) ?: '', 'secret-password' ) ) {
	$fail( 'plaintext password found anywhere in stored options' );
}

$resolved = $provider->resolve_auth_ref( 'logstash' );
if ( is_wp_error( $resolved ) ) {
	$fail( 'auth ref unexpectedly failed' );
}
if ( 'basic' !== ( $resolved['auth']['type'] ?? '' ) || 'chubes4' !== ( $resolved['auth']['username'] ?? '' ) || 'secret-password' !== ( $resolved['auth']['password'] ?? '' ) ) {
	$fail( 'auth ref did not resolve Basic credentials' );
}
if ( 'socks5://127.0.0.1:8080' !== ( $resolved['proxy_url'] ?? '' ) ) {
	$fail( 'auth ref did not resolve proxy URL' );
}

// A second account must not evict the first: this is the whole point of the change.
$provider->save_config(
	array(
		'account'   => 'matticspace',
		'username'  => 'chubes',
		'password'  => 'other-password',
		'proxy_url' => 'socks5h://127.0.0.1:8080',
	)
);

$first = $provider->resolve_auth_ref( 'logstash' );
if ( is_wp_error( $first ) || 'secret-password' !== ( $first['auth']['password'] ?? '' ) ) {
	$fail( 'configuring a second account evicted the first' );
}

$second = $provider->resolve_auth_ref( 'matticspace' );
if ( is_wp_error( $second ) || 'chubes' !== ( $second['auth']['username'] ?? '' ) || 'other-password' !== ( $second['auth']['password'] ?? '' ) ) {
	$fail( 'second account did not resolve' );
}
if ( 'socks5h://127.0.0.1:8080' !== ( $second['proxy_url'] ?? '' ) ) {
	$fail( 'second account did not keep its own proxy' );
}

$names = $provider->get_account_names();
sort( $names );
if ( array( 'logstash', 'matticspace' ) !== $names ) {
	$fail( 'account listing did not report both credentials' );
}

// Re-saving an existing name replaces only that credential.
$provider->save_config( array( 'account' => 'logstash', 'username' => 'chubes4', 'password' => 'rotated' ) );
if ( 'rotated' !== ( $provider->resolve_auth_ref( 'logstash' )['auth']['password'] ?? '' ) ) {
	$fail( 'rotating a credential did not take effect' );
}
if ( 'other-password' !== ( $provider->resolve_auth_ref( 'matticspace' )['auth']['password'] ?? '' ) ) {
	$fail( 'rotating one credential disturbed another' );
}

if ( ! is_wp_error( $provider->resolve_auth_ref( 'never-configured' ) ) ) {
	$fail( 'an unconfigured account resolved' );
}

if ( ! $provider->delete_account( 'matticspace' ) || $provider->delete_account( 'matticspace' ) ) {
	$fail( 'delete_account did not report what it did' );
}
if ( ! is_wp_error( $provider->resolve_auth_ref( 'matticspace' ) ) ) {
	$fail( 'deleted account still resolved' );
}
if ( is_wp_error( $provider->resolve_auth_ref( 'logstash' ) ) ) {
	$fail( 'deleting one account removed another' );
}

// An install configured before multi-account support stores a flat credential. It must keep
// resolving without a migration step, otherwise upgrading silently breaks live auth refs.
$legacy_provider = new HttpBasicAuthProvider();
$GLOBALS['datamachine_http_basic_options']['datamachine_auth_data']['http_basic']['config'] = array(
	'account'   => 'legacy',
	'username'  => 'olduser',
	'password'  => 'legacy-password',
	'proxy_url' => 'socks5://127.0.0.1:9999',
);
$legacy = $legacy_provider->resolve_auth_ref( 'legacy' );
if ( is_wp_error( $legacy ) || 'olduser' !== ( $legacy['auth']['username'] ?? '' ) || 'legacy-password' !== ( $legacy['auth']['password'] ?? '' ) ) {
	$fail( 'pre-multi-account credential stopped resolving' );
}
if ( 'socks5://127.0.0.1:9999' !== ( $legacy['proxy_url'] ?? '' ) ) {
	$fail( 'pre-multi-account proxy stopped resolving' );
}
if ( array( 'legacy' ) !== $legacy_provider->get_account_names() ) {
	$fail( 'pre-multi-account credential was not listed' );
}
if ( ! $legacy_provider->is_authenticated() ) {
	$fail( 'pre-multi-account credential did not read as authenticated' );
}

// Adding an account alongside a legacy credential must carry the legacy one forward.
$legacy_provider->save_config( array( 'account' => 'added', 'username' => 'u2', 'password' => 'p2' ) );
if ( is_wp_error( $legacy_provider->resolve_auth_ref( 'legacy' ) ) ) {
	$fail( 'adding an account dropped the migrated legacy credential' );
}
if ( 'legacy-password' !== ( $legacy_provider->resolve_auth_ref( 'legacy' )['auth']['password'] ?? '' ) ) {
	$fail( 'migrated legacy credential lost its password' );
}

$empty = new HttpBasicAuthProvider();
$GLOBALS['datamachine_http_basic_options']['datamachine_auth_data']['http_basic']['config'] = array();
if ( $empty->is_authenticated() || array() !== $empty->get_account_names() ) {
	$fail( 'an unconfigured provider reported credentials' );
}

echo "=== http-basic-auth-provider-smoke: ALL PASS ===\n";
