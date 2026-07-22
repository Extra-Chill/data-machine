<?php
/**
 * Pure-PHP smoke test for empty drain queue run-flow short-circuiting.
 *
 * Run with: php tests/run-flow-empty-drain-queue-smoke.php
 *
 * @package DataMachine\Tests
 */

$run_flow_file = __DIR__ . '/../inc/Abilities/Engine/RunFlowAbility.php';
$run_flow_src  = file_get_contents( $run_flow_file ) ?: '';
$queue_src     = file_get_contents( __DIR__ . '/../inc/Abilities/Flow/QueueAbility.php' ) ?: '';

$assertions = 0;

function assert_run_flow_empty_drain_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;

	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_run_flow_empty_drain_contains( string $needle, string $haystack, string $message ): void {
	assert_run_flow_empty_drain_true( false !== strpos( $haystack, $needle ), $message );
}

assert_run_flow_empty_drain_contains( 'use DataMachine\\Abilities\\Flow\\QueueAbility;', $run_flow_src, 'run-flow ability imports queue slot constants' );
assert_run_flow_empty_drain_contains( '$empty_drain_skip = $this->getEmptyDrainQueueSkip( $flow_config );', $run_flow_src, 'run-flow checks empty drain queues' );
assert_run_flow_empty_drain_contains( "'reason'     => 'empty_drain_queue'", $run_flow_src, 'empty drain skip returns explicit reason' );
assert_run_flow_empty_drain_contains( 'QueueAbility::firstDrainQueueWorkAvailability( $flow_config )', $run_flow_src, 'skip delegates drain-mode and queue-slot resolution' );
assert_run_flow_empty_drain_contains( 'self::SLOT_CONFIG_PATCH_QUEUE', $queue_src, 'queue availability checks the config patch queue slot' );
assert_run_flow_empty_drain_contains( '$this->recordSuppressedRun( $flow_id, $scheduling_config, $empty_drain_skip );', $run_flow_src, 'empty drain skip records scheduler suppression metadata' );
assert_run_flow_empty_drain_contains( "'datamachine_last_suppressed_run'", $run_flow_src, 'suppression marker uses explicit Data Machine scheduling key' );
assert_run_flow_empty_drain_contains( "'backoff_until'", $run_flow_src, 'suppression marker records backoff boundary for status' );
assert_run_flow_empty_drain_contains( 'update_flow_scheduling_metadata(', $run_flow_src, 'suppression metadata cannot overwrite concurrent desired scheduling state' );

$skip_pos       = strpos( $run_flow_src, '$empty_drain_skip = $this->getEmptyDrainQueueSkip( $flow_config );' );
$create_job_pos = strpos( $run_flow_src, '$job_id = $this->db_jobs->create_job( $job_data );' );
assert_run_flow_empty_drain_true( false !== $skip_pos && false !== $create_job_pos && $skip_pos < $create_job_pos, 'empty drain queue is skipped before job creation' );

echo "OK ({$assertions} assertions)\n";
