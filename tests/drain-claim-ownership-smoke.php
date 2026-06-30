<?php
/**
 * Pure-PHP smoke test for claim-owned Data Machine CLI drains (#2252).
 *
 * Run with: php tests/drain-claim-ownership-smoke.php
 *
 * @package DataMachine\Tests
 */

$drain_file = __DIR__ . '/../inc/Cli/Commands/DrainCommand.php';
$drain_src  = file_get_contents( $drain_file ) ?: '';

$assertions = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_contains( string $needle, string $haystack, string $message ): void {
	assert_true( false !== strpos( $haystack, $needle ), $message );
}

function assert_not_contains( string $needle, string $haystack, string $message ): void {
	assert_true( false === strpos( $haystack, $needle ), $message );
}

assert_contains( 'runActionSchedulerBatch', $drain_src, 'drain processes Action Scheduler batches' );
assert_contains( '\ActionScheduler_Store::instance()', $drain_src, 'drain uses the Action Scheduler store' );
assert_contains( 'GroupRegistrar::ensureDataMachineGroup()', $drain_src, 'drain ensures the Data Machine group exists before claiming' );
assert_contains( 'runActionSchedulerTimeoutCleanup( $store )', $drain_src, 'drain runs Action Scheduler timeout cleanup before claiming work' );
assert_contains( 'stake_claim( $claim_size, null, $hooks ?? array(), self::GROUP )', $drain_src, 'drain stakes a group-scoped Action Scheduler claim' );
assert_contains( '$claim->get_actions()', $drain_src, 'drain processes actions from the claim' );
assert_contains( 'find_actions_by_claim_id( $claim->get_id() )', $drain_src, 'drain rechecks claim ownership before processing each action' );
assert_contains( '$runner->process_action( $action_id, \'Data Machine CLI drain\' )', $drain_src, 'drain executes only claim-owned action IDs' );
assert_contains( 'flushRuntimeCache()', $drain_src, 'drain flushes runtime cache between actions' );
assert_contains( 'isMemorySoftLimitReached()', $drain_src, 'drain checks memory soft limit while processing actions' );
assert_contains( "'memory_limit'", $drain_src, 'drain reports memory-limit stop reason from batch processing' );
assert_contains( 'finally {', $drain_src, 'drain releases claims in a finally block' );
assert_contains( '$store->release_claim( $claim )', $drain_src, 'drain releases the Action Scheduler claim' );
assert_not_contains( 'getDuePendingActionIds', $drain_src, 'drain no longer preselects due action IDs outside Action Scheduler claims' );
assert_contains( 'laneActionRows', $drain_src, 'drain only selects action IDs for lane filtering/status, not direct processing' );

$ensure_pos  = strpos( $drain_src, 'GroupRegistrar::ensureDataMachineGroup()' );
$cleanup_pos = strpos( $drain_src, 'runActionSchedulerTimeoutCleanup( $store )' );
$stake_pos   = strpos( $drain_src, 'stake_claim( $claim_size, null, $hooks ?? array(), self::GROUP )' );
$verify_pos  = strpos( $drain_src, 'find_actions_by_claim_id( $claim->get_id() )' );
$process_pos = strpos( $drain_src, '$runner->process_action( $action_id, \'Data Machine CLI drain\' )' );
$flush_pos   = strpos( $drain_src, 'flushRuntimeCache()' );
$memory_pos  = strpos( $drain_src, 'isMemorySoftLimitReached()' );
$release_pos = strpos( $drain_src, '$store->release_claim( $claim )' );

assert_true( false !== $ensure_pos, 'group registration position found' );
assert_true( false !== $cleanup_pos, 'timeout cleanup position found' );
assert_true( false !== $stake_pos, 'claim staking position found' );
assert_true( false !== $verify_pos, 'claim verification position found' );
assert_true( false !== $process_pos, 'claimed action processing position found' );
assert_true( false !== $flush_pos, 'runtime cache flush position found' );
assert_true( false !== $memory_pos, 'memory soft-limit check position found' );
assert_true( false !== $release_pos, 'claim release position found' );
assert_true( $ensure_pos < $stake_pos, 'drain ensures group before staking a claim' );
assert_true( $cleanup_pos < $stake_pos, 'drain cleans stale claims before staking a claim' );
assert_true( $stake_pos < $verify_pos, 'drain stakes a claim before verifying ownership' );
assert_true( $verify_pos < $process_pos, 'drain verifies ownership before processing' );
assert_true( $process_pos < $flush_pos, 'drain flushes runtime cache after processing' );
assert_true( $process_pos < $release_pos, 'drain releases the claim after processing' );

echo "OK ({$assertions} assertions)\n";
