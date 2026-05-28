<?php
/**
 * Smoke tests for principal-scoped auth provider storage.
 *
 *   php tests/auth-principal-scoped-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['datamachine_auth_scope_options']    = array();
$GLOBALS['datamachine_auth_scope_transients'] = array();
$GLOBALS['datamachine_auth_scope_user_id']    = 0;
$GLOBALS['datamachine_auth_scope_filters']    = array();

function __( $text, $domain = null ) {
	unset( $domain );
	return $text;
}

function esc_html( $text ) {
	return $text;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_salt( $scheme = 'auth' ) {
	return 'principal-scoped-auth-smoke-' . $scheme;
}

function maybe_serialize( $value ) {
	return serialize( $value );
}

function get_site_option( $name, $default = false ) {
	return $GLOBALS['datamachine_auth_scope_options'][ $name ] ?? $default;
}

function update_site_option( $name, $value ) {
	$GLOBALS['datamachine_auth_scope_options'][ $name ] = $value;
	return true;
}

function get_current_user_id() {
	return (int) $GLOBALS['datamachine_auth_scope_user_id'];
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['datamachine_auth_scope_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	$GLOBALS['datamachine_auth_scope_filters'][ $hook ][] = $callback;
}

function do_action( $hook, ...$args ) {
	unset( $hook, $args );
}

function set_transient( $key, $value, $expiration = 0 ) {
	unset( $expiration );
	$GLOBALS['datamachine_auth_scope_transients'][ $key ] = $value;
	return true;
}

function get_transient( $key ) {
	return $GLOBALS['datamachine_auth_scope_transients'][ $key ] ?? false;
}

function delete_transient( $key ) {
	unset( $GLOBALS['datamachine_auth_scope_transients'][ $key ] );
	return true;
}

require_once dirname( __DIR__ ) . '/inc/Core/OAuth/OAuthRedirects.php';
require_once dirname( __DIR__ ) . '/inc/Core/OAuth/BaseAuthProvider.php';
require_once dirname( __DIR__ ) . '/inc/Core/OAuth/OAuth2Handler.php';

class Principal_Scoped_Provider extends DataMachine\Core\OAuth\BaseAuthProvider {
	public function __construct() {
		parent::__construct( 'example' );
	}

	public function get_config_fields(): array {
		return array();
	}

	public function is_authenticated(): bool {
		$account = $this->get_account();
		return ! empty( $account['access_token'] );
	}
}

add_filter( 'datamachine_auth_scope_policy', function ( string $policy, string $provider_slug, string $credential_type ): string {
	if ( 'example' === $provider_slug ) {
		return DataMachine\Core\OAuth\BaseAuthProvider::AUTH_SCOPE_PRINCIPAL;
	}
	if ( 'direct_config' === $provider_slug && 'config' === $credential_type ) {
		return DataMachine\Core\OAuth\BaseAuthProvider::AUTH_SCOPE_USER;
	}
	return $policy;
}, 10, 3 );

$pass     = 0;
$fail     = 0;
$failures = array();

function smoke_assert( string $label, bool $condition, string $detail = '' ): void {
	global $pass, $fail, $failures;
	if ( $condition ) {
		$pass++;
		echo "  ✓ {$label}\n";
		return;
	}

	$fail++;
	$failures[] = array( $label, $detail );
	echo "  ✗ {$label}" . ( $detail ? "\n      {$detail}" : '' ) . "\n";
}

echo "[1] two users store provider accounts without overwriting\n";
$provider = new Principal_Scoped_Provider();
$provider->save_account_for_user( 101, array( 'access_token' => 'alice-token' ) );
$provider->save_account_for_user( 202, array( 'access_token' => 'bob-token' ) );

smoke_assert( 'alice reads alice token', 'alice-token' === $provider->get_account_for_user( 101 )['access_token'] );
smoke_assert( 'bob reads bob token', 'bob-token' === $provider->get_account_for_user( 202 )['access_token'] );

$raw = get_site_option( 'datamachine_auth_data', array() );
smoke_assert( 'alice account stored under user principal', isset( $raw['example']['principals']['user:101']['account'] ) );
smoke_assert( 'bob account stored under user principal', isset( $raw['example']['principals']['user:202']['account'] ) );
smoke_assert( 'legacy site account was not written', ! isset( $raw['example']['account'] ) );

echo "\n[2] agent scope wins when explicitly provided\n";
$provider->save_account_for_agent( 303, array( 'access_token' => 'agent-token' ) );
smoke_assert( 'agent reads agent token', 'agent-token' === $provider->get_account_for_agent( 303 )['access_token'] );
$raw = get_site_option( 'datamachine_auth_data', array() );
smoke_assert( 'agent account stored under agent principal', isset( $raw['example']['principals']['agent:303']['account'] ) );

echo "\n[3] policy context explicitly opts into site fallback\n";
$GLOBALS['datamachine_auth_scope_options']['datamachine_auth_data']['legacy']['account'] = array( 'access_token' => 'legacy-token' );
$legacy = new class() extends DataMachine\Core\OAuth\BaseAuthProvider {
	public function __construct() { parent::__construct( 'legacy' ); }
	public function get_config_fields(): array { return array(); }
	public function is_authenticated(): bool { return ! empty( $this->get_account()['access_token'] ); }
};
smoke_assert( 'named user lookup does not fall back to legacy account', null === $legacy->get_account_for_user( 404 ) );
smoke_assert( 'policy context lookup falls back to legacy account', 'legacy-token' === $legacy->get_account_for_policy_context( array( 'user_id' => 404 ) )['access_token'] );

echo "\n[4] site-wide policy remains the default\n";
$site_provider = new class() extends DataMachine\Core\OAuth\BaseAuthProvider {
	public function __construct() { parent::__construct( 'site_default' ); }
	public function get_config_fields(): array { return array(); }
	public function is_authenticated(): bool { return ! empty( $this->get_account()['access_token'] ); }
};
$site_provider->save_site_account( array( 'access_token' => 'site-token-a' ) );
$site_provider->save_site_account( array( 'access_token' => 'site-token-b' ) );
smoke_assert( 'site account writes site account', 'site-token-b' === $site_provider->get_site_account()['access_token'] );
$raw = get_site_option( 'datamachine_auth_data', array() );
smoke_assert( 'default policy does not create principal accounts', ! isset( $raw['site_default']['principals'] ) );

echo "\n[5] direct config credentials can opt into user scoping\n";
$config_provider = new class() extends DataMachine\Core\OAuth\BaseAuthProvider {
	public function __construct() { parent::__construct( 'direct_config' ); }
	public function get_config_fields(): array { return array(); }
	public function is_authenticated(): bool { return ! empty( $this->get_config()['cookie'] ); }
};
$config_provider->save_config( array( 'cookie' => 'alice-cookie' ), array( 'user_id' => 101 ) );
$config_provider->save_config( array( 'cookie' => 'bob-cookie' ), array( 'user_id' => 202 ) );
smoke_assert( 'alice reads scoped config', 'alice-cookie' === $config_provider->get_config( array( 'user_id' => 101 ) )['cookie'] );
smoke_assert( 'bob reads scoped config', 'bob-cookie' === $config_provider->get_config( array( 'user_id' => 202 ) )['cookie'] );

echo "\n[6] concurrent OAuth state and PKCE entries do not overwrite\n";
$oauth  = new DataMachine\Core\OAuth\OAuth2Handler();
$state1 = $oauth->create_state( 'example', array( 'user_id' => 101 ) );
$state2 = $oauth->create_state( 'example', array( 'user_id' => 202 ) );
$pkce1  = $oauth->create_pkce( 'example', $state1 );
$pkce2  = $oauth->create_pkce( 'example', $state2 );

smoke_assert( 'first state returns first payload', 101 === $oauth->verify_state( 'example', $state1 )['user_id'] );
smoke_assert( 'second state returns second payload', 202 === $oauth->verify_state( 'example', $state2 )['user_id'] );
smoke_assert( 'first PKCE verifier survives second create', $pkce1['verifier'] === $oauth->get_pkce_verifier( 'example', $state1 ) );
smoke_assert( 'second PKCE verifier survives first read', $pkce2['verifier'] === $oauth->get_pkce_verifier( 'example', $state2 ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo "  - {$failure[0]}" . ( $failure[1] ? ": {$failure[1]}" : '' ) . "\n";
	}
	exit( 1 );
}
