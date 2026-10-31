<?php
/**
 * Per-request schema fingerprint gate (Extra-Chill/data-machine#3708).
 *
 * Run with: php tests/schema-fingerprint-gate-smoke.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\Database {
	abstract class BaseRepository {
		public static function database_table_exists( string $table, $wpdb = null ): bool {
			unset( $wpdb );
			++$GLOBALS['schema_queries'];
			return isset( $GLOBALS['schema_tables'][ $table ] );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'DATAMACHINE_VERSION', '1.0-test' );

	$GLOBALS['schema_queries']   = 0;
	$GLOBALS['create_calls']     = 0;
	$GLOBALS['options']          = array();
	$GLOBALS['schema_tables']    = array();

	// Every table class referenced by convergence: a static create_table() that
	// counts calls. PHP allows calling a static method through an instance.
	spl_autoload_register(
		static function ( string $class ): void {
			if ( ! preg_match( '/^DataMachine\\\\(Core\\\\Database|Engine\\\\AI\\\\Actions)\\\\/', $class ) ) {
				return;
			}
			$pos       = strrpos( $class, '\\' );
			$namespace = substr( $class, 0, $pos );
			$short     = substr( $class, $pos + 1 );
			eval( "namespace {$namespace}; class {$short} { public static function create_table() { ++\$GLOBALS['create_calls']; ++\$GLOBALS['schema_queries']; } }" );
		}
	);

	class FingerprintGateWpdb {
		public string $prefix      = 'wp_2_';
		public string $base_prefix = 'wp_';
		public string $last_error  = '';
		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) { $query = preg_replace( '/%i/', (string) $arg, $query, 1 ); }
			return $query;
		}
		public function get_results( string $query, $format = null ): array {
			unset( $format );
			++$GLOBALS['schema_queries'];
			if ( preg_match( '/SHOW COLUMNS FROM (\S+)/', $query, $match ) ) {
				return array_map( static fn( string $name ): array => array( 'Field' => $name ), $GLOBALS['schema_tables'][ $match[1] ]['columns'] ?? array() );
			}
			if ( preg_match( '/SHOW INDEX FROM (\S+)/', $query, $match ) ) {
				return array_map( static fn( string $name ): array => array( 'Key_name' => $name ), $GLOBALS['schema_tables'][ $match[1] ]['indexes'] ?? array() );
			}
			return array();
		}
	}

	$GLOBALS['wpdb'] = new FingerprintGateWpdb();
	function do_action( string $hook, ...$args ): void { unset( $hook, $args ); }
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
	function get_option( string $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( string $key, $value, $autoload = null ): bool { unset( $autoload ); $GLOBALS['options'][ $key ] = $value; return true; }
	function delete_option( string $key ): bool { unset( $GLOBALS['options'][ $key ] ); return true; }

	require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/ActivationServiceProvider.php';

	use DataMachine\Core\Bootstrap\ActivationServiceProvider;

	$option   = ActivationServiceProvider::SCHEMA_FINGERPRINT_OPTION;
	$failures = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) {
			++$failures;
			fwrite( STDERR, "FAIL: {$message}\n" );
			return;
		}
		echo "PASS: {$message}\n";
	};
	$reset = static function (): void {
		$GLOBALS['schema_queries'] = 0;
		$GLOBALS['create_calls']   = 0;
	};

	$GLOBALS['schema_tables'] = ActivationServiceProvider::current_schema_requirements();

	// 1. Missing fingerprint: converge, validate, and store the fingerprint.
	$reset();
	$assert( ActivationServiceProvider::ensure_all_tables_if_needed(), 'missing fingerprint converges successfully' );
	$assert( $GLOBALS['create_calls'] > 0, 'missing fingerprint runs table ensures' );
	$assert( ActivationServiceProvider::schema_fingerprint() === ( $GLOBALS['options'][ $option ] ?? null ), 'successful convergence stores the fingerprint' );

	// 2. Matching fingerprint: zero schema work.
	$reset();
	$assert( ActivationServiceProvider::ensure_all_tables_if_needed(), 'matching fingerprint reports a converged schema' );
	$assert( 0 === $GLOBALS['schema_queries'], 'matching fingerprint runs zero schema queries' );
	$assert( 0 === $GLOBALS['create_calls'], 'matching fingerprint skips every create_table' );

	// 3. Changed fingerprint (stale stored value): converge and store the new one.
	$GLOBALS['options'][ $option ] = 'stale-fingerprint';
	$reset();
	$assert( ActivationServiceProvider::ensure_all_tables_if_needed(), 'changed fingerprint converges successfully' );
	$assert( $GLOBALS['create_calls'] > 0, 'changed fingerprint runs table ensures' );
	$assert( ActivationServiceProvider::schema_fingerprint() === $GLOBALS['options'][ $option ], 'changed fingerprint stores the current value' );

	// 4. Failed convergence: fingerprint not stored, so the next request retries.
	$GLOBALS['options'][ $option ] = 'stale-fingerprint';
	unset( $GLOBALS['schema_tables']['wp_2_datamachine_jobs'] );
	$reset();
	$assert( ! ActivationServiceProvider::ensure_all_tables_if_needed(), 'failed validation reports failure' );
	$assert( ! isset( $GLOBALS['options'][ $option ] ), 'failed convergence leaves no fingerprint' );
	$reset();
	ActivationServiceProvider::ensure_all_tables_if_needed();
	$assert( $GLOBALS['create_calls'] > 0, 'next request after a failure retries convergence' );
	$GLOBALS['schema_tables'] = ActivationServiceProvider::current_schema_requirements();

	// 5. Direct ensure_all_tables() (activation, version bump, operator) always converges.
	ActivationServiceProvider::ensure_all_tables();
	$reset();
	ActivationServiceProvider::ensure_all_tables();
	$assert( $GLOBALS['create_calls'] > 0, 'direct ensure_all_tables always converges even with a matching fingerprint' );

	// 6. Fingerprint is per site: another prefix yields another fingerprint.
	$site_two                 = ActivationServiceProvider::schema_fingerprint();
	$GLOBALS['wpdb']->prefix  = 'wp_3_';
	$assert( $site_two !== ActivationServiceProvider::schema_fingerprint(), 'fingerprint differs per site prefix' );

	if ( $failures > 0 ) {
		exit( 1 );
	}

	echo "Schema fingerprint gate smoke passed.\n";
}
