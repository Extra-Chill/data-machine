<?php
/**
 * Pure-PHP smoke test for fetch handler failure status propagation.
 *
 * Run with: php tests/fetch-handler-failure-status-smoke.php
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );
$file = file_get_contents( $root . '/inc/Core/Steps/Fetch/FetchStep.php' ) ?: '';

$failed = 0;
$total  = 0;

function assert_fetch_handler_failure_status( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}\n";
}

echo "=== fetch-handler-failure-status-smoke ===\n";

assert_fetch_handler_failure_status(
	'FetchStep accepts handler WP_Error results',
	str_contains( $file, 'if ( is_wp_error( $packets ) )' )
);

assert_fetch_handler_failure_status(
	'FetchStep records handler failures as failed step results',
	str_contains( $file, "'result'       => 'failed'," ) && str_contains( $file, "'reason'       => 'handler_failed'," )
);

assert_fetch_handler_failure_status(
	'FetchStep returns explicit failed result shape for handler failures',
	str_contains( $file, "'status'  => 'failed'," ) && str_contains( $file, "'reason'  => 'handler_failed'," )
);

assert_fetch_handler_failure_status(
	'FetchStep preserves handler failure messages in explicit results',
	str_contains( $file, "'error'   => \$packets->get_error_message()," )
);

assert_fetch_handler_failure_status(
	'execute_handler preserves handler exceptions as WP_Error',
	str_contains( $file, "new \\WP_Error( 'fetch_handler_execution_failed', \$e->getMessage() )" )
);

if ( $failed > 0 ) {
	echo "\nfetch-handler-failure-status-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\nfetch-handler-failure-status-smoke passed: {$total} assertions.\n";
