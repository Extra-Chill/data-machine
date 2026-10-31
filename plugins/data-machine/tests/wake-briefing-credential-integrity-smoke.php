<?php
/**
 * Smoke coverage for the WakeBriefing credential-integrity signal (#3504):
 * undecryptable stored credentials and the AUTH_KEY/AUTH_SALT advisory.
 *
 * Run with: php tests/wake-briefing-credential-integrity-smoke.php
 *
 * Contract under test, mirroring the incident that motivated the signal:
 * five credentials across four providers became undecryptable after the
 * encryption key changed, and nothing reported it for days. The briefing
 * must emit ONE grouped line naming providers and counts only (never
 * ciphertext, plaintext, or envelope fragments), stay silent when all
 * credentials are healthy, and surface the db-stored-salt precondition as
 * an advisory that expires once it is no longer new.
 *
 * Dependency-free like the other smoke tests: an in-memory options store,
 * a mutable wp_salt() stub, and a real encrypt/decrypt cycle through
 * BaseAuthProvider so envelope handling is exercised for real.
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

namespace {

	use DataMachine\Core\OAuth\BaseAuthProvider;
	use DataMachine\Engine\AI\System\Tasks\WakeBriefingTask;

	$root = dirname( __DIR__ );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/' );
	}
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	// In-memory site-option store backing the salt-advisory first-seen state.
	$GLOBALS['__wake_options'] = array();

	if ( ! function_exists( 'get_site_option' ) ) {
		function get_site_option( string $option, $default = false ) {
			if ( array_key_exists( $option, $GLOBALS['__wake_options'] ) ) {
				return $GLOBALS['__wake_options'][ $option ];
			}
			return $default;
		}
	}
	if ( ! function_exists( 'update_site_option' ) ) {
		function update_site_option( string $option, $value ): bool {
			$GLOBALS['__wake_options'][ $option ] = $value;
			return true;
		}
	}
	if ( ! function_exists( 'delete_site_option' ) ) {
		function delete_site_option( string $option ): bool {
			unset( $GLOBALS['__wake_options'][ $option ] );
			return true;
		}
	}

	// Mutable salt so tests can simulate an encryption-key change.
	$GLOBALS['__wake_salt'] = 'salt-before-incident';

	if ( ! function_exists( 'wp_salt' ) ) {
		function wp_salt( string $scheme = 'auth' ): string {
			return $GLOBALS['__wake_salt'];
		}
	}

	// In-memory filter registry so apply_filters() returns overrides.
	$GLOBALS['__wake_filters'] = array();

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$rest ) {
			if ( array_key_exists( $hook, $GLOBALS['__wake_filters'] ) ) {
				return $GLOBALS['__wake_filters'][ $hook ];
			}
			return $value;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ) {}
	}

	function wake_set_filter( string $hook, $value ): void {
		$GLOBALS['__wake_filters'][ $hook ] = $value;
	}
	function wake_clear_filters(): void {
		$GLOBALS['__wake_filters'] = array();
	}

	$failed = 0;
	$total  = 0;

	function wake_assert( string $name, bool $condition, string $detail = '' ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}
		++$failed;
		echo "  [FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}

	echo "=== wake-briefing-credential-integrity-smoke ===\n";

	require_once $root . '/inc/Core/OAuth/BaseAuthProvider.php';
	require_once $root . '/inc/Engine/AI/System/Tasks/SystemTask.php';
	require_once $root . '/inc/Engine/AI/System/Tasks/WakeBriefingTask.php';

	/**
	 * Concrete double exposing the protected encrypt surface so the test can
	 * build real envelopes under a controlled key.
	 */
	class Wake_Credential_Provider extends BaseAuthProvider {

		public function get_config_fields(): array {
			return array();
		}

		public function is_authenticated(): bool {
			return false;
		}

		public function test_encrypt_fields( array $data ): array {
			return $this->encrypt_fields( $data );
		}
	}

	$ref  = new ReflectionClass( WakeBriefingTask::class );
	$GLOBALS['__wake_task'] = $ref->newInstanceWithoutConstructor();

	$invoke = function ( string $method, array $args = array() ) use ( $ref ) {
		// No setAccessible() call: private/protected methods have been
		// directly invokable via Reflection since PHP 8.1, and
		// ReflectionMethod::setAccessible() is a deprecated no-op as of
		// PHP 8.5 — calling it here would emit a deprecation notice on
		// every invocation.
		$m = $ref->getMethod( $method );
		return $m->invoke( $GLOBALS['__wake_task'], ...$args );
	};

	// Fresh, unmemoized instance (the credential audit memoizes once per run).
	$fresh_task = function () use ( $ref ) {
		$GLOBALS['__wake_task'] = $ref->newInstanceWithoutConstructor();
	};

	$provider = new Wake_Credential_Provider( 'test' );

	// http_basic-style providers add their own encrypted field names via the
	// `datamachine_auth_encrypted_fields` filter — register it so the fixture
	// covers provider-specific fields, not just the ENCRYPTED_FIELDS defaults.
	wake_set_filter(
		'datamachine_auth_encrypted_fields',
		array_merge( BaseAuthProvider::ENCRYPTED_FIELDS, array( 'password' ) )
	);

	// -----------------------------------------------------------------------
	// Fixture: five encrypted credentials across four providers, mirroring
	// the incident shape (one provider holding two).
	// -----------------------------------------------------------------------

	$auth_data = array(
		'wpcom'      => array(
			'account' => $provider->test_encrypt_fields(
				array(
					'username'     => 'bot-account',
					'access_token' => 'super-secret-token-wpcom',
				)
			),
		),
		'wporg_trac' => array(
			'principals' => array(
				'user:7' => array(
					'account' => $provider->test_encrypt_fields(
						array( 'access_token' => 'super-secret-token-trac' )
					),
				),
			),
		),
		'http_basic' => array(
			'account' => $provider->test_encrypt_fields(
				array( 'password' => 'super-secret-http-basic-one' )
			),
			'accounts' => array(
				'second' => $provider->test_encrypt_fields(
					array( 'password' => 'super-secret-http-basic-two' )
				),
			),
		),
		'buildkite'  => array(
			'config' => $provider->test_encrypt_fields(
				array( 'api_secret' => 'super-secret-buildkite' )
			),
		),
	);

	// Envelopes must never carry the plaintext through storage.
	$stored_blob = $auth_data['wpcom']['account']['access_token'];
	wake_assert(
		'fixture: stored value carries the encryption envelope',
		str_starts_with( $stored_blob, 'dm:enc:v1:' ) && ! str_contains( $stored_blob, 'super-secret-token-wpcom' ),
		'got: ' . substr( (string) $stored_blob, 0, 32 ) . '…'
	);

	update_site_option( 'datamachine_auth_data', $auth_data );

	// -----------------------------------------------------------------------
	// 1. Audit: healthy under the original key.
	// -----------------------------------------------------------------------

	$audit = BaseAuthProvider::audit_stored_credentials();
	wake_assert(
		'audit: has_credentials is true with credentials stored',
		true === $audit['has_credentials']
	);
	wake_assert(
		'audit: no undecryptable values while the key still matches',
		array() === $audit['undecryptable_counts'],
		'got: ' . json_encode( $audit['undecryptable_counts'] )
	);

	// -----------------------------------------------------------------------
	// 2. Audit + signal line: the key changed.
	// -----------------------------------------------------------------------

	$GLOBALS['__wake_salt'] = 'salt-after-incident';

	$audit = BaseAuthProvider::audit_stored_credentials();
	wake_assert(
		'audit: counts every provider after the key changed (wpcom=1, wporg_trac=1, http_basic=2, buildkite=1)',
		array(
			'wpcom'      => 1,
			'wporg_trac' => 1,
			'http_basic' => 2,
			'buildkite'  => 1,
		) === $audit['undecryptable_counts'],
		'got: ' . json_encode( $audit['undecryptable_counts'] )
	);

	wake_clear_filters();
	$signals = $invoke( 'getCredentialSignals' );
	$lines   = array_values( array_filter(
		$signals,
		static fn( $line ) => str_contains( (string) $line, 'cannot be decrypted' )
	) );

	wake_assert(
		'signal: exactly one grouped Credentials line when credentials fail',
		1 === count( $lines ),
		'got: ' . json_encode( $signals )
	);

	$line = (string) ( $lines[0] ?? '' );
	wake_assert(
		'signal: names the total count (5) and every provider, grouping http_basic×2',
		str_contains( $line, '5 stored credential(s)' )
			&& str_contains( $line, 'wpcom' )
			&& str_contains( $line, 'wporg_trac' )
			&& str_contains( $line, 'http_basic×2' )
			&& str_contains( $line, 'buildkite' ),
		"got: {$line}"
	);
	wake_assert(
		'signal: carries the re-authenticate call to action',
		str_contains( $line, 'encryption key changed. Re-authenticate.' ),
		"got: {$line}"
	);
	wake_assert(
		'signal: leaks no plaintext, ciphertext, or envelope fragments',
		! str_contains( $line, 'dm:enc:v1:' )
			&& ! str_contains( $line, 'super-secret' )
			&& ! str_contains( $line, (string) $stored_blob ),
		"got: {$line}"
	);

	// -----------------------------------------------------------------------
	// 3. AUTH_KEY/AUTH_SALT advisory (constants undefined in this harness).
	// -----------------------------------------------------------------------

	$advisories = array_values( array_filter(
		$signals,
		static fn( $l ) => str_contains( (string) $l, 'AUTH_KEY/AUTH_SALT' )
	) );
	wake_assert(
		'advisory: emitted while credentials exist and salts are not in config',
		1 === count( $advisories )
			&& str_contains( (string) ( $advisories[0] ?? '' ), 'database-stored salts' ),
		'got: ' . json_encode( $signals )
	);
	wake_assert(
		'advisory: first-seen marker recorded once',
		(int) get_site_option( 'datamachine_wake_briefing_salt_advisory_first_seen', 0 ) > 0
	);

	// Aged out past the default window => quiet, marker retained.
	update_site_option( 'datamachine_wake_briefing_salt_advisory_first_seen', time() - ( 8 * DAY_IN_SECONDS ) );
	wake_clear_filters();
	$fresh_task();
	$signals        = $invoke( 'getCredentialSignals' );
	$advisory_count = count( array_filter( $signals, static fn( $l ) => str_contains( (string) $l, 'AUTH_KEY/AUTH_SALT' ) ) );
	$failure_count  = count( array_filter( $signals, static fn( $l ) => str_contains( (string) $l, 'cannot be decrypted' ) ) );
	wake_assert(
		'advisory: expires after the freshness window (undecryptable line remains)',
		0 === $advisory_count && 1 === $failure_count,
		'got: ' . json_encode( $signals )
	);
	wake_assert(
		'advisory: expired marker is retained so a standing condition stays quiet',
		(int) get_site_option( 'datamachine_wake_briefing_salt_advisory_first_seen', 0 ) > 0
	);

	// The window is filterable: 0 keeps the advisory indefinitely.
	wake_set_filter( 'datamachine_wake_briefing_salt_advisory_days', 0 );
	$signals = $invoke( 'getCredentialSignals' );
	wake_assert(
		'advisory: days=0 filter keeps the advisory despite its age',
		1 === count( array_filter( $signals, static fn( $l ) => str_contains( (string) $l, 'AUTH_KEY/AUTH_SALT' ) ) ),
		'got: ' . json_encode( $signals )
	);
	wake_clear_filters();

	// No credentials at risk => advisory disappears and the marker resets.
	update_site_option( 'datamachine_auth_data', array() );
	$fresh_task();
	$signals = $invoke( 'getCredentialSignals' );
	wake_assert(
		'advisory: silent when no credentials are stored at all',
		array() === $signals,
		'got: ' . json_encode( $signals )
	);
	wake_assert(
		'advisory: marker cleared once nothing is at risk',
		0 === (int) get_site_option( 'datamachine_wake_briefing_salt_advisory_first_seen', 0 )
	);

	// -----------------------------------------------------------------------
	// 4. Healthy credentials => no credential lines at all.
	// -----------------------------------------------------------------------

	$GLOBALS['__wake_salt'] = 'salt-before-incident';
	update_site_option( 'datamachine_auth_data', $auth_data );
	$fresh_task();
	$signals    = $invoke( 'getCredentialSignals' );
	$advisories = array_values( array_filter(
		$signals,
		static fn( $l ) => str_contains( (string) $l, 'AUTH_KEY/AUTH_SALT' )
	) );
	wake_assert(
		'healthy: no undecryptable line while the key matches',
		0 === count( array_filter( $signals, static fn( $l ) => str_contains( (string) $l, 'cannot be decrypted' ) ) ),
		'got: ' . json_encode( $signals )
	);
	wake_assert(
		'healthy: advisory re-arms for the (still db-salted) credentials',
		1 === count( $advisories ),
		'got: ' . json_encode( $signals )
	);

	// -----------------------------------------------------------------------
	// 5. gatherSiteSignals() integration: line rides with the other signals
	//    and the audit is memoized once per run.
	// -----------------------------------------------------------------------

	$GLOBALS['__wake_salt'] = 'salt-after-incident';
	update_site_option( 'datamachine_auth_data', $auth_data );
	delete_site_option( 'datamachine_wake_briefing_salt_advisory_first_seen' );
	$fresh_task();

	$GLOBALS['wpdb'] = new class() {
		public string $prefix = 'wp_';

		public function prepare( string $query, ...$args ): array {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array(
				'sql'  => $query,
				'args' => $args,
			);
		}

		public function get_results( $prepared, $output = ARRAY_A ) {
			return array();
		}

		public function get_var( $prepared = null ) {
			return '0';
		}

		public function get_row( $prepared, $output = ARRAY_A ) {
			return null;
		}
	};

	// Keep the other gatherers quiet: healthy disk thresholds, no debug.log.
	wake_set_filter( 'datamachine_wake_briefing_disk_min_free_pct', 0.0 );
	wake_set_filter( 'datamachine_wake_briefing_disk_min_free_bytes', 0.0 );
	$prev_error_log = ini_get( 'error_log' );
	ini_set( 'error_log', '/nonexistent/wake-credential/nope.log' );

	$site_signals = $invoke( 'gatherSiteSignals', array( gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
	wake_assert(
		'integration: gatherSiteSignals returns exactly the two credential lines',
		2 === count( $site_signals )
			&& str_contains( (string) $site_signals[0], 'cannot be decrypted' )
			&& str_contains( (string) $site_signals[1], 'AUTH_KEY/AUTH_SALT' ),
		'got: ' . json_encode( $site_signals )
	);

	// Repair the credentials between runs: the memo must hold for this run.
	$GLOBALS['__wake_salt'] = 'salt-before-incident';
	$site_signals_again     = $invoke( 'gatherSiteSignals', array( gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
	wake_assert(
		'integration: credential audit runs once per run (memoized across blogs)',
		$site_signals === $site_signals_again,
		'got: ' . json_encode( $site_signals_again )
	);

	ini_set( 'error_log', false === $prev_error_log ? '' : $prev_error_log );

	// -----------------------------------------------------------------------
	// 6. Salts defined in config (irreversible define: must run last).
	// -----------------------------------------------------------------------

	define( 'AUTH_KEY', 'defined-in-wp-config' );
	define( 'AUTH_SALT', 'defined-in-wp-config' );

	$GLOBALS['__wake_salt'] = 'salt-after-incident';
	$fresh_task();
	$signals = $invoke( 'getCredentialSignals' );
	wake_assert(
		'config salts: no advisory once AUTH_KEY/AUTH_SALT are defined',
		0 === count( array_filter( $signals, static fn( $l ) => str_contains( (string) $l, 'AUTH_KEY/AUTH_SALT' ) ) ),
		'got: ' . json_encode( $signals )
	);
	wake_assert(
		'config salts: undecryptable line still reported independently',
		1 === count( $signals ) && str_contains( (string) $signals[0], '5 stored credential(s)' ),
		'got: ' . json_encode( $signals )
	);
	wake_assert(
		'config salts: marker cleared once salts live in config',
		0 === (int) get_site_option( 'datamachine_wake_briefing_salt_advisory_first_seen', 0 )
	);

	// -----------------------------------------------------------------------
	// Legacy envelopes: written before the key fingerprint existed, so they
	// carry three parts and can only be settled by a trial decryption. Any
	// install holding credentials stored before that change has these, which
	// is exactly the shape the original incident left behind.
	// -----------------------------------------------------------------------

	$legacy_envelope = static function ( string $plaintext, string $salt ): string {
		$key = hash( 'sha256', $salt . 'datamachine-oauth', true );
		$iv  = random_bytes( (int) openssl_cipher_iv_length( BaseAuthProvider::CIPHER_ALGO ) );
		$tag = '';
		$ct  = openssl_encrypt( $plaintext, BaseAuthProvider::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv, $tag, '', BaseAuthProvider::AUTH_TAG_LENGTH );
		return 'dm:enc:v1:' . base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $ct );
	};

	$current_salt = wp_salt( 'auth' );

	update_site_option(
		'datamachine_auth_data',
		array(
			'legacy_ok'      => array(
				'config' => array( 'access_token' => $legacy_envelope( 'still-readable', $current_salt ) ),
			),
			'legacy_orphan'  => array(
				'config' => array( 'access_token' => $legacy_envelope( 'lost-to-rotation', 'a-different-salt-entirely' ) ),
			),
		)
	);

	$legacy_audit = BaseAuthProvider::audit_stored_credentials();

	wake_assert(
		'legacy: a three-part envelope under the current key is not reported',
		! isset( $legacy_audit['undecryptable_counts']['legacy_ok'] ),
		'got: ' . json_encode( $legacy_audit['undecryptable_counts'] )
	);
	wake_assert(
		'legacy: a three-part envelope under a rotated key is reported',
		1 === ( $legacy_audit['undecryptable_counts']['legacy_orphan'] ?? 0 ),
		'got: ' . json_encode( $legacy_audit['undecryptable_counts'] )
	);
	wake_assert(
		'legacy: a malformed envelope counts as undecryptable rather than passing',
		( static function () {
			update_site_option(
				'datamachine_auth_data',
				array( 'legacy_broken' => array( 'config' => array( 'access_token' => 'dm:enc:v1:only-two:parts' ) ) )
			);
			$audit = BaseAuthProvider::audit_stored_credentials();
			return 1 === ( $audit['undecryptable_counts']['legacy_broken'] ?? 0 );
		} )()
	);

	// -----------------------------------------------------------------------

	if ( $failed > 0 ) {
		echo "\nwake-briefing-credential-integrity-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nwake-briefing-credential-integrity-smoke passed: {$total} assertions.\n";
}
