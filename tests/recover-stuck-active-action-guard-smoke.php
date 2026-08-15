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

$source       = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' ) ?: '';
$batch_source = file_get_contents( __DIR__ . '/../inc/Core/ActionScheduler/PathlessBatchRecovery.php' ) ?: '';
$timeout_loop = strstr( $source, 'foreach ( $timed_out_jobs as $job )' ) ?: '';

echo "Case 1: timed-out recovery skips jobs with active scheduler work\n";
assert_recover_stuck_guard_smoke( 'active scheduler work evidence method exists', str_contains( $source, 'private function getActiveSchedulerWork' ) );
assert_recover_stuck_guard_smoke( 'active step guard exposes action IDs', str_contains( $source, 'private function getActiveStepActionIds' ) );
assert_recover_stuck_guard_smoke( 'timeout loop diagnoses child ownership before dry-run recovery', strpos( $timeout_loop, 'ChildJobRecoveryPolicy::diagnose' ) < strpos( $timeout_loop, 'if ( $dry_run )' ) );
assert_recover_stuck_guard_smoke( 'guard records skipped status', str_contains( $source, "'status'  => 'skipped'") && str_contains( $source, 'Pending or in-progress scheduler work exists' ) );

echo "Case 2: guard is limited to executable Data Machine step actions\n";
assert_recover_stuck_guard_smoke( 'guard queries datamachine_execute_step', str_contains( $source, 'datamachine_execute_step' ) );
assert_recover_stuck_guard_smoke( 'guard checks pending and in-progress actions', str_contains( $source, "'pending'") && str_contains( $source, "'in-progress'") );
assert_recover_stuck_guard_smoke( 'guard confirms exact job id from action args', str_contains( $source, '$this->extractActionJobId' ) );

echo "Case 3: stale in-progress step actions do not block timeout recovery forever\n";
assert_recover_stuck_guard_smoke( 'guard receives timeout window', str_contains( $source, 'private function getActiveStepActionIds( int $job_id, int $timeout_hours )' ) );
assert_recover_stuck_guard_smoke( 'guard reads action attempt timestamps', str_contains( $source, 'last_attempt_gmt' ) && str_contains( $source, 'scheduled_date_gmt' ) );
assert_recover_stuck_guard_smoke( 'pending actions remain guarded unconditionally', str_contains( $source, 'if ( \'pending\' === (string) $action->status )' ) );
assert_recover_stuck_guard_smoke( 'old in-progress actions can fall through', str_contains( $source, '( $now_gmt - $started_at ) < $timeout_seconds' ) );

echo "Case 4: batch parents guard chunk actions and active children\n";
assert_recover_stuck_guard_smoke( 'active batch guard method exists', str_contains( $batch_source, 'public static function hasActiveWork' ) );
assert_recover_stuck_guard_smoke( 'batch guard checks pipeline chunk actions', str_contains( $batch_source, 'datamachine_pipeline_batch_chunk' ) && str_contains( $batch_source, 'parent_job_id' ) );
assert_recover_stuck_guard_smoke( 'batch guard checks active children', str_contains( $batch_source, 'WHERE parent_job_id = %d' ) && str_contains( $batch_source, 'status IN ( %s, %s )' ) );
assert_recover_stuck_guard_smoke( 'batch guard confirms exact parent id', str_contains( $batch_source, 'extractParentJobId' ) && str_contains( $batch_source, 'maybe_unserialize' ) );
assert_recover_stuck_guard_smoke( 'batch guard uses indexed canonical args', str_contains( $batch_source, 'WHERE args = %s AND hook = %s' ) );
assert_recover_stuck_guard_smoke( 'batch compatibility queries are bounded', str_contains( $batch_source, 'ACTION_QUERY_LIMIT + 1' ) && str_contains( $batch_source, 'LIMIT %d' ) );
assert_recover_stuck_guard_smoke( 'batch guard fails closed on incomplete evidence', str_contains( $batch_source, 'count( $actions ) > self::ACTION_QUERY_LIMIT' ) && str_contains( $batch_source, "last_error" ) );
assert_recover_stuck_guard_smoke( 'old child rows age out through shared policy', str_contains( $batch_source, 'public static function diagnoseChildRows' ) && str_contains( $batch_source, "'stale_job_ids'") );

echo "\nRecover-stuck active action guard smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
