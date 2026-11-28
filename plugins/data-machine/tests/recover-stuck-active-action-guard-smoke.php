<?php
/**
 * Static smoke test for recover-stuck active Action Scheduler guard.
 *
 * Run with: php tests/recover-stuck-active-action-guard-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function maybe_unserialize( $data ) {
	if ( ! is_string( $data ) ) {
		return $data;
	}
	$unserialized = @unserialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Compatibility fixture.
	return false === $unserialized && 'b:0;' !== $data ? $data : $unserialized;
}

require_once __DIR__ . '/fixtures/scheduler-evidence-bootstrap.php';

use DataMachine\Core\Jobs\SchedulerEvidence;

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
$evidence_src = file_get_contents( __DIR__ . '/../inc/Core/Jobs/SchedulerEvidence.php' ) ?: '';
$smoke_now    = strtotime( '2026-08-09 12:00:00 UTC' );
$smoke_row    = static fn( int $id, string $hook, string $status, string $args, string $scheduled = '2026-08-09 11:59:00', string $attempt = '0000-00-00 00:00:00' ): array => array(
	'action_id'          => $id,
	'hook'               => $hook,
	'status'             => $status,
	'scheduled_date_gmt' => $scheduled,
	'last_attempt_gmt'   => $attempt,
	'action_args'        => $args,
);
$timeout_loop = strstr( $source, 'foreach ( $timed_out_jobs as $job )' ) ?: '';

echo "Case 1: timed-out recovery skips jobs with active scheduler work\n";
assert_recover_stuck_guard_smoke( 'active scheduler work evidence method exists', str_contains( $source, 'private function getActiveSchedulerWork' ) );
assert_recover_stuck_guard_smoke( 'active step guard exposes action IDs', str_contains( $source, 'private function getActiveStepActionIds' ) );
assert_recover_stuck_guard_smoke( 'timeout loop diagnoses child ownership before dry-run recovery', strpos( $timeout_loop, 'ChildJobRecoveryPolicy::diagnose' ) < strpos( $timeout_loop, 'if ( $dry_run )' ) );
assert_recover_stuck_guard_smoke( 'guard records skipped status', str_contains( $source, "'status'  => 'skipped'") && str_contains( $source, 'Pending or in-progress scheduler work exists' ) );

echo "Case 2: guard is limited to executable Data Machine step actions\n";
assert_recover_stuck_guard_smoke( 'guard queries datamachine_execute_step', str_contains( $source, 'datamachine_execute_step' ) );
assert_recover_stuck_guard_smoke( 'guard checks pending and in-progress actions', str_contains( $source, "'pending'") && str_contains( $source, "'in-progress'") );
$evidence = SchedulerEvidence::fromRows(
	array(
		$smoke_row( 1, 'datamachine_execute_step', 'pending', '{"job_id":123,"flow_step_id":"a"}' ),
		$smoke_row( 2, 'datamachine_execute_step', 'pending', '{"job_id":12,"flow_step_id":"a"}' ),
	)
);
assert_recover_stuck_guard_smoke( 'guard confirms exact job id from action args (no numeric-prefix collision)', array( 2 ) === $evidence->liveActionIds( 12, $smoke_now, HOUR_IN_SECONDS ) && array( 1 ) === $evidence->liveActionIds( 123, $smoke_now, HOUR_IN_SECONDS ) );
assert_recover_stuck_guard_smoke( 'guard reads the shared evidence snapshot', str_contains( $source, '->liveActionIds(' ) && str_contains( $source, 'SchedulerEvidence::stepHooks()' ) );

echo "Case 3: stale in-progress step actions do not block timeout recovery forever\n";
assert_recover_stuck_guard_smoke( 'guard receives timeout window', str_contains( $source, 'private function getActiveStepActionIds( int $job_id, int $timeout_hours )' ) );
assert_recover_stuck_guard_smoke( 'guard reads action attempt timestamps', str_contains( $source, 'last_attempt_gmt' ) && str_contains( $source, 'scheduled_date_gmt' ) );
$aged = SchedulerEvidence::fromRows(
	array(
		$smoke_row( 10, 'datamachine_execute_step', 'pending', '{"job_id":7}', '2026-08-01 00:00:00' ),
		$smoke_row( 11, 'datamachine_execute_step', 'in-progress', '{"job_id":8}', '2026-08-09 05:00:00', '2026-08-09 05:00:00' ),
		$smoke_row( 12, 'datamachine_execute_step', 'in-progress', '{"job_id":9}', '2026-08-09 11:30:00', '2026-08-09 11:30:00' ),
	)
);
assert_recover_stuck_guard_smoke( 'pending actions remain guarded unconditionally', array( 10 ) === $aged->liveActionIds( 7, $smoke_now, HOUR_IN_SECONDS ) );
assert_recover_stuck_guard_smoke( 'old in-progress actions can fall through', array() === $aged->liveActionIds( 8, $smoke_now, HOUR_IN_SECONDS ) && array( 12 ) === $aged->liveActionIds( 9, $smoke_now, HOUR_IN_SECONDS ) );

echo "Case 4: batch parents guard chunk actions and active children\n";
assert_recover_stuck_guard_smoke( 'active batch guard method exists', str_contains( $batch_source, 'public static function hasActiveWork' ) );
assert_recover_stuck_guard_smoke( 'batch guard checks pipeline chunk actions', str_contains( $batch_source, 'PipelineBatchScheduler::BATCH_HOOK' ) && str_contains( $evidence_src, "'parent_job_id'" ) );
assert_recover_stuck_guard_smoke( 'batch guard checks active children', str_contains( $batch_source, 'WHERE parent_job_id = %d' ) && str_contains( $batch_source, 'status IN ( %s, %s )' ) );
$chunks = SchedulerEvidence::fromRows(
	array(
		$smoke_row( 20, 'datamachine_pipeline_batch_chunk', 'pending', '{"parent_job_id":7,"offset":40}' ),
		$smoke_row( 21, 'datamachine_pipeline_batch_chunk', 'pending', '[{"parent_job_id":8,"offset":40}]' ),
		$smoke_row( 22, 'datamachine_pipeline_batch_chunk', 'pending', serialize( array( array( 'parent_job_id' => 9, 'offset' => 40 ) ) ) ),
	)
);
assert_recover_stuck_guard_smoke( 'batch guard confirms exact parent id across JSON, nested, and serialized args', array( 20 ) === $chunks->liveActionIds( 7, $smoke_now, HOUR_IN_SECONDS ) && array( 21 ) === $chunks->liveActionIds( 8, $smoke_now, HOUR_IN_SECONDS ) && array( 22 ) === $chunks->liveActionIds( 9, $smoke_now, HOUR_IN_SECONDS ) && array() === $chunks->liveActionIds( 70, $smoke_now, HOUR_IN_SECONDS ) );
assert_recover_stuck_guard_smoke( 'batch guard reads the parent chunk from the single evidence query', str_contains( $evidence_src, 'COALESCE(extended_args, args)' ) && 1 === substr_count( $evidence_src, '$wpdb->get_results(' ) );
assert_recover_stuck_guard_smoke( 'batch evidence query is bounded with a truncation sentinel', str_contains( $evidence_src, 'self::SCAN_LIMIT + 1' ) && str_contains( $evidence_src, 'LIMIT %d' ) );
$over_limit = array_fill( 0, SchedulerEvidence::SCAN_LIMIT + 1, $smoke_row( 30, 'datamachine_execute_step', 'pending', '{"job_id":5}' ) );
assert_recover_stuck_guard_smoke( 'batch guard fails closed on incomplete evidence', false === SchedulerEvidence::fromRows( $over_limit, count( $over_limit ) <= SchedulerEvidence::SCAN_LIMIT )->isComplete() && str_contains( $evidence_src, 'last_error' ) );
assert_recover_stuck_guard_smoke( 'old child rows age out through shared policy', str_contains( $batch_source, 'public static function diagnoseChildRows' ) && str_contains( $batch_source, "'stale_job_ids'") );
assert_recover_stuck_guard_smoke( 'stale child candidates read the shared bulk evidence snapshot', str_contains( $batch_source, 'public static function diagnoseChildWork' ) && str_contains( $batch_source, 'CHILD_QUERY_LIMIT' ) && str_contains( $batch_source, 'SchedulerEvidence::load()' ) && ! str_contains( $batch_source, 'LIKE' ) );
assert_recover_stuck_guard_smoke( 'child action and job ownership evidence is returned', str_contains( $source, "'child_step_action'") && str_contains( $batch_source, "'child_action_ids'") );

echo "\nRecover-stuck active action guard smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
