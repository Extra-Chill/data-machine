<?php
/**
 * Pure-PHP smoke test for empty drain queue suppression/backoff wiring.
 *
 * Run with: php tests/empty-drain-suppression-smoke.php
 *
 * @package DataMachine\Tests
 */

$files = array(
	'flows'     => __DIR__ . '/../inc/Core/Database/Flows/Flows.php',
	'formatter' => __DIR__ . '/../inc/Core/Admin/FlowFormatter.php',
	'cycle'     => __DIR__ . '/../inc/Cli/Commands/CycleCommand.php',
);

$sources = array();
foreach ( $files as $key => $path ) {
	$sources[ $key ] = file_get_contents( $path ) ?: '';
}

$assertions = 0;

function assert_empty_drain_suppression_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;

	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_empty_drain_suppression_contains( string $needle, string $haystack, string $message ): void {
	assert_empty_drain_suppression_true( false !== strpos( $haystack, $needle ), $message );
}

assert_empty_drain_suppression_contains( 'datamachine_last_suppressed_run', $sources['flows'], 'flow readiness reads suppression marker' );
assert_empty_drain_suppression_contains( 'first_drain_queue_has_work', $sources['flows'], 'flow readiness bypasses suppression when new queue work exists' );
assert_empty_drain_suppression_contains( 'latest_mysql_datetime( $last_run_at, $suppressed_run[\'suppressed_at\'] )', $sources['flows'], 'suppressed tick participates in due-time calculation' );
assert_empty_drain_suppression_contains( 'QueueAbility::SLOT_CONFIG_PATCH_QUEUE', $sources['flows'], 'flow readiness checks the generic queue slot' );
assert_empty_drain_suppression_contains( 'last_suppressed_run', $sources['formatter'], 'formatted flow status exposes suppression details' );
assert_empty_drain_suppression_contains( "\$row['status'] = 'suppressed';", $sources['cycle'], 'cycle command reports suppressed empty-drain flows clearly' );

echo "OK ({$assertions} assertions)\n";
