<?php
/**
 * Static smoke test for recover-stuck active Action Scheduler guard.
 *
 * Run with: php tests/recover-stuck-active-action-guard-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_recover_stuck_guard_smoke( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
	++$failed;
}

$source = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' ) ?: '';
$timeout_loop = strstr( $source, 'foreach ( $timed_out_jobs as $job )' ) ?: '';

echo "Case 1: timed-out recovery skips jobs with active step actions\n";
assert_recover_stuck_guard_smoke( 'active step guard method exists', str_contains( $source, 'private function hasActiveStepAction' ) );
assert_recover_stuck_guard_smoke( 'timeout loop invokes active step guard before dry-run timeout', strpos( $timeout_loop, '$this->hasActiveStepAction( $job_id )' ) < strpos( $timeout_loop, 'if ( $dry_run )' ) );
assert_recover_stuck_guard_smoke( 'guard records skipped status', str_contains( $source, "'status'  => 'skipped'") && str_contains( $source, 'Pending or in-progress Action Scheduler step action exists' ) );

echo "Case 2: guard is limited to executable Data Machine step actions\n";
assert_recover_stuck_guard_smoke( 'guard queries datamachine_execute_step', str_contains( $source, 'datamachine_execute_step' ) );
assert_recover_stuck_guard_smoke( 'guard checks pending and in-progress actions', str_contains( $source, "'pending'") && str_contains( $source, "'in-progress'") );
assert_recover_stuck_guard_smoke( 'guard confirms exact job id from action args', str_contains( $source, '$this->extractActionJobId' ) );

echo "\nRecover-stuck active action guard smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
