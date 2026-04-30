<?php
/**
 * Smoke test: normal frontend page views skip Data Machine's heavy runtime.
 *
 * Run with: php tests/frontend-runtime-gate-smoke.php
 *
 * @package DataMachine\Tests
 */

$plugin_file = dirname( __DIR__ ) . '/data-machine.php';
$source      = file_get_contents( $plugin_file );

if ( false === $source ) {
	fwrite( STDERR, "FAIL: data-machine.php is not readable\n" );
	exit( 1 );
}

$failed = 0;
$total  = 0;

$assert = static function ( string $name, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}

	echo "  FAIL: {$name}\n";
	++$failed;
};

$assert( 'runtime-gate-function-exists', str_contains( $source, 'function datamachine_should_load_full_runtime(): bool' ) );
$assert( 'main-runtime-checks-gate-first', (bool) preg_match( '/function datamachine_run_datamachine_plugin\(\) \{\s*if \( ! datamachine_should_load_full_runtime\(\) \)/', $source ) );
$assert( 'wp-cli-gets-full-runtime', str_contains( $source, "defined( 'WP_CLI' ) && WP_CLI" ) );
$assert( 'admin-ajax-cron-get-full-runtime', str_contains( $source, 'is_admin() || wp_doing_ajax() || wp_doing_cron()' ) );
$assert( 'rest-path-gets-full-runtime', str_contains( $source, 'str_starts_with( $path, \'/wp-json/\' )' ) );
$assert( 'oauth-callback-gets-full-runtime', str_contains( $source, 'str_starts_with( $path, \'/datamachine-auth/\' )' ) );
$assert( 'plain-permalink-rest-route-gets-full-runtime', str_contains( $source, 'isset( $_GET[\'rest_route\'] )' ) );
$assert( 'extensions-can-opt-in', str_contains( $source, "apply_filters( 'datamachine_should_load_full_runtime'" ) );
$assert( 'frontend-default-is-lazy', str_contains( $source, "apply_filters( 'datamachine_should_load_full_runtime', false" ) );

if ( $failed > 0 ) {
	fwrite( STDERR, "frontend runtime gate smoke failed: {$failed}/{$total}\n" );
	exit( 1 );
}

echo "Frontend runtime gate smoke passed: {$total} assertions.\n";
