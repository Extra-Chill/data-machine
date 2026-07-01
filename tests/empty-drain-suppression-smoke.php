<?php
/**
 * Pure-PHP smoke test for empty drain queue suppression/backoff wiring.
 *
 * Run with: php tests/empty-drain-suppression-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

require_once __DIR__ . '/../inc/Engine/ExecutionPlan.php';
require_once __DIR__ . '/../inc/Abilities/Flow/FlowHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Flow/QueueAbility.php';

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
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_empty_drain_suppression_contains( string $needle, string $haystack, string $message ): void {
	assert_empty_drain_suppression_true( false !== strpos( $haystack, $needle ), $message );
}

assert_empty_drain_suppression_contains( 'datamachine_last_suppressed_run', $sources['flows'], 'flow readiness reads suppression marker' );
assert_empty_drain_suppression_contains( 'first_drain_queue_has_work', $sources['flows'], 'flow readiness bypasses suppression when new queue work exists' );
assert_empty_drain_suppression_contains( 'latest_mysql_datetime( $last_run_at, $suppressed_run[\'suppressed_at\'] )', $sources['flows'], 'suppressed tick participates in due-time calculation' );
assert_empty_drain_suppression_contains( 'QueueAbility::firstDrainQueueHasWork', $sources['flows'], 'flow readiness uses central queue work primitive' );
assert_empty_drain_suppression_contains( 'last_suppressed_run', $sources['formatter'], 'formatted flow status exposes suppression details' );
assert_empty_drain_suppression_contains( "\$row['status'] = 'suppressed';", $sources['cycle'], 'cycle command reports suppressed empty-drain flows clearly' );

$availability = DataMachine\Abilities\Flow\QueueAbility::firstDrainQueueWorkAvailability(
	array(
		'fetch1' => array(
			'execution_order'     => 0,
			'step_type'           => 'fetch',
			'queue_mode'          => 'drain',
			'config_patch_queue'  => array(),
		),
	)
);
assert_empty_drain_suppression_true( is_array( $availability ), 'fetch drain step returns availability' );
assert_empty_drain_suppression_true( false === $availability['has_work'], 'empty fetch drain step reports no work' );
assert_empty_drain_suppression_true( DataMachine\Abilities\Flow\QueueAbility::SLOT_CONFIG_PATCH_QUEUE === $availability['slot'], 'fetch drain step resolves config-patch slot' );

$availability = DataMachine\Abilities\Flow\QueueAbility::firstDrainQueueWorkAvailability(
	array(
		'ai1' => array(
			'execution_order' => 0,
			'step_type'       => 'ai',
			'queue_mode'      => 'drain',
			'prompt_queue'    => array( array( 'prompt' => 'work', 'added_at' => 't0' ) ),
		),
	)
);
assert_empty_drain_suppression_true( true === $availability['has_work'], 'prompt drain step reports queued work' );
assert_empty_drain_suppression_true( DataMachine\Abilities\Flow\QueueAbility::SLOT_PROMPT_QUEUE === $availability['slot'], 'prompt drain step resolves prompt slot' );

echo "OK ({$assertions} assertions)\n";
