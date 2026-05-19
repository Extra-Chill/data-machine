<?php
/**
 * Smoke tests for the per-user OAuth account API.
 *
 *   php tests/auth-per-user-api-smoke.php
 *
 * Exercises `get_account_for_user`, `save_account_for_user`, and
 * `delete_account_for_user` — the deliberate-no-fallback surface for
 * vendor plugins that operate on a specific user's credentials.
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

$GLOBALS['datamachine_auth_per_user_options'] = array();
$GLOBALS['datamachine_auth_per_user_filters'] = array();
$GLOBALS['datamachine_auth_per_user_logs']    = array();

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
	return 'auth-per-user-smoke-' . $scheme;
}

function maybe_serialize( $value ) {
	return serialize( $value );
}

function get_site_option( $name, $default = false ) {
	return $GLOBALS['datamachine_auth_per_user_options'][ $name ] ?? $default;
}

function update_site_option( $name, $value ) {
	$GLOBALS['datamachine_auth_per_user_options'][ $name ] = $value;
	return true;
}

function get_current_user_id() {
	return 0;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['datamachine_auth_per_user_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	$GLOBALS['datamachine_auth_per_user_filters'][ $hook ][] = $callback;
}

function do_action( $hook, ...$args ) {
	$GLOBALS['datamachine_auth_per_user_logs'][] = array( $hook, $args );
}

function set_transient( $key, $value, $expiration = 0 ) {
	unset( $key, $value, $expiration );
	return true;
}

function get_transient( $key ) {
	unset( $key );
	return false;
}

function delete_transient( $key ) {
	unset( $key );
	return true;
}

require_once dirname( __DIR__ ) . '/inc/Core/OAuth/OAuthRedirects.php';
require_once dirname( __DIR__ ) . '/inc/Core/OAuth/BaseAuthProvider.php';

class Per_User_Smoke_Provider extends DataMachine\Core\OAuth\BaseAuthProvider {
	public function __construct( string $slug = 'sample' ) {
		parent::__construct( $slug );
	}

	public function get_config_fields(): array {
		return array();
	}

	public function is_authenticated(): bool {
		return false;
	}
}

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

echo "[1] round-trip per-user save and read\n";
$provider = new Per_User_Smoke_Provider();
$saved    = $provider->save_account_for_user( 101, array(
	'access_token' => 'tok-alice',
	'username'     => 'alice',
) );
smoke_assert( 'save returns true', $saved );

$account = $provider->get_account_for_user( 101 );
smoke_assert( 'read returns array', is_array( $account ) );
smoke_assert( 'access_token round-trips through encryption', is_array( $account ) && 'tok-alice' === $account['access_token'] );
smoke_assert( 'plaintext fields preserved', is_array( $account ) && 'alice' === $account['username'] );

echo "\n[2] missing per-user account returns null (no site fallback)\n";
$GLOBALS['datamachine_auth_per_user_options']['datamachine_auth_data']['sample']['account'] = array(
	'access_token' => 'site-token',
);
$missing = $provider->get_account_for_user( 999 );
smoke_assert( 'unknown user returns null', null === $missing );
smoke_assert( 'site account still readable via legacy path', 'site-token' === $provider->get_account()['access_token'] );

echo "\n[3] two users do not see each other's accounts\n";
$provider->save_account_for_user( 202, array( 'access_token' => 'tok-bob' ) );
$alice = $provider->get_account_for_user( 101 );
$bob   = $provider->get_account_for_user( 202 );
smoke_assert( 'alice reads alice', is_array( $alice ) && 'tok-alice' === $alice['access_token'] );
smoke_assert( 'bob reads bob', is_array( $bob ) && 'tok-bob' === $bob['access_token'] );

echo "\n[4] delete is targeted and idempotent\n";
$deleted = $provider->delete_account_for_user( 101 );
smoke_assert( 'delete returns true', $deleted );
smoke_assert( 'deleted account returns null on read', null === $provider->get_account_for_user( 101 ) );
smoke_assert( 'other users unaffected by delete', is_array( $provider->get_account_for_user( 202 ) ) );
smoke_assert( 'second delete is idempotent', $provider->delete_account_for_user( 101 ) );

echo "\n[5] invalid user ids are rejected\n";
smoke_assert( 'save with zero user_id returns false', false === $provider->save_account_for_user( 0, array( 'access_token' => 'x' ) ) );
smoke_assert( 'read with zero user_id returns null', null === $provider->get_account_for_user( 0 ) );
smoke_assert( 'delete with zero user_id returns false', false === $provider->delete_account_for_user( 0 ) );

echo "\n[6] per-user storage shares slot with principal-scoped writes\n";
$shared = new Per_User_Smoke_Provider( 'shared' );
$shared->save_account_for_user( 303, array( 'access_token' => 'tok-shared' ) );
$raw = get_site_option( 'datamachine_auth_data', array() );
smoke_assert(
	'per-user account lives at principals[user:303][account]',
	isset( $raw['shared']['principals']['user:303']['account'] )
);

echo "\n[7] platform override filter short-circuits default lookup\n";
$filter_provider = new Per_User_Smoke_Provider( 'filtered' );

$captured_args = null;
add_filter(
	'datamachine_resolve_oauth_account_for_user',
	function ( $account, $provider, $user_id ) use ( &$captured_args ) {
		$captured_args = array( $account, $provider, $user_id );
		if ( 'filtered' === $provider && 707 === $user_id ) {
			return array(
				'access_token' => 'platform-supplied-token',
				'scope'        => 'platform:read',
			);
		}
		return $account;
	},
	10,
	3
);

$override = $filter_provider->get_account_for_user( 707 );
smoke_assert( 'filter return value short-circuits default lookup', is_array( $override ) && 'platform-supplied-token' === $override['access_token'] );
smoke_assert(
	'filter receives (null, provider, user_id) arguments',
	is_array( $captured_args ) && null === $captured_args[0] && 'filtered' === $captured_args[1] && 707 === $captured_args[2]
);

// Filter returning null lets the default fall through.
$filter_provider->save_account_for_user( 808, array( 'access_token' => 'default-token' ) );
$default = $filter_provider->get_account_for_user( 808 );
smoke_assert(
	'filter returning null lets default lookup proceed',
	is_array( $default ) && 'default-token' === $default['access_token']
);

echo "\n[8] sensitive fields are encrypted at rest\n";
$raw          = get_site_option( 'datamachine_auth_data', array() );
$stored_token = $raw['sample']['principals']['user:202']['account']['access_token'] ?? '';
smoke_assert(
	'access_token is encrypted in storage',
	is_string( $stored_token ) && str_starts_with( $stored_token, DataMachine\Core\OAuth\BaseAuthProvider::ENCRYPTION_PREFIX )
);
smoke_assert(
	'decrypted value matches original',
	'tok-bob' === $provider->get_account_for_user( 202 )['access_token']
);

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo "  - {$failure[0]}" . ( $failure[1] ? ": {$failure[1]}" : '' ) . "\n";
	}
	exit( 1 );
}
