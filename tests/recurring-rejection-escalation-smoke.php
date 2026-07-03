<?php
/**
 * Smoke test for RecurringRejectionTracker escalation behavior.
 *
 * Run with: php tests/recurring-rejection-escalation-smoke.php
 *
 * Verifies the core robustness contract from data-machine#2558:
 *
 *   (a) A single (transient) rejection does NOT escalate and does NOT mark
 *       the schedule degraded — it behaves exactly as a one-off failure.
 *   (b) N consecutive rejections for the same schedule_id flip the schedule
 *       into the degraded set and emit the distinct
 *       `recurring_schedule_persistently_rejected` log signal EXACTLY ONCE
 *       (not once per tick).
 *   (c) A success for that schedule_id clears the counter, so a recovered
 *       binding stops showing as degraded.
 *
 * The class only depends on get_option/update_option/delete_option,
 * apply_filters, do_action and gmdate, so this harness stubs an in-memory
 * options store and a log recorder and exercises the real production class.
 *
 * Also asserts the production wiring (source-grep) so the seam in
 * SystemAgentServiceProvider and the health surface in SystemAbilities
 * cannot silently drift away from the tracker.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── In-memory WordPress option + hook stubs ────────────────────────

$GLOBALS['datamachine_test_options'] = array();
$GLOBALS['datamachine_test_logs']    = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['datamachine_test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['datamachine_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['datamachine_test_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['datamachine_test_logs'][] = array(
				'level'   => $args[0] ?? '',
				'message' => $args[1] ?? '',
				'context' => $args[2] ?? array(),
			);
		}
	}
}

require_once dirname( __DIR__ ) . '/inc/Engine/Tasks/RecurringRejectionTracker.php';

use DataMachine\Engine\Tasks\RecurringRejectionTracker;

// ─── Tiny assertion helpers ─────────────────────────────────────────

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $cond ) use ( &$failures, &$total ): void {
	$total++;
	if ( $cond ) {
		echo "  [PASS] {$label}\n";
	} else {
		$failures++;
		echo "  [FAIL] {$label}\n";
	}
};

$reset_state = function (): void {
	$GLOBALS['datamachine_test_options'] = array();
	$GLOBALS['datamachine_test_logs']    = array();
};

$escalation_logs = static function (): array {
	return array_values(
		array_filter(
			$GLOBALS['datamachine_test_logs'],
			static fn( array $log ): bool =>
				( $log['context']['error_code'] ?? '' ) === RecurringRejectionTracker::ESCALATION_ERROR_CODE
		)
	);
};

echo "=== recurring-rejection-escalation-smoke ===\n";

$threshold = RecurringRejectionTracker::threshold();

// ─── (a) A single transient rejection does not escalate ─────────────

echo "\n[a] One rejection is transient — no escalation, not degraded\n";
$reset_state();
$count = RecurringRejectionTracker::record_rejection( 'workspace_disk_emergency_cleanup', 'workspace_disk_emergency_cleanup', 'task_scheduler_agent_context_required' );
$assert( 'count is 1 after one rejection', 1 === $count );
$assert( 'threshold is at least 2 so one rejection is below it', $threshold >= 2 );
$assert( 'schedule is NOT degraded after one rejection', ! isset( RecurringRejectionTracker::degraded()['workspace_disk_emergency_cleanup'] ) );
$assert( 'no escalation log emitted for a single rejection', 0 === count( $escalation_logs() ) );

// ─── (b) N consecutive rejections escalate exactly once ─────────────

echo "\n[b] N consecutive rejections flip degraded + escalate exactly once\n";
$reset_state();
for ( $i = 1; $i <= $threshold; $i++ ) {
	RecurringRejectionTracker::record_rejection( 'workspace_disk_emergency_cleanup', 'workspace_disk_emergency_cleanup', 'task_scheduler_agent_context_required' );
}
$degraded = RecurringRejectionTracker::degraded();
$assert( 'schedule is degraded once threshold reached', isset( $degraded['workspace_disk_emergency_cleanup'] ) );
$assert( 'degraded count equals threshold', $threshold === (int) $degraded['workspace_disk_emergency_cleanup']['count'] );
$assert( 'exactly one escalation log emitted at threshold', 1 === count( $escalation_logs() ) );
$escalation = $escalation_logs()[0] ?? array();
$assert( 'escalation log is error level', 'error' === ( $escalation['level'] ?? '' ) );
$assert( 'escalation carries schedule_id', 'workspace_disk_emergency_cleanup' === ( $escalation['context']['schedule_id'] ?? '' ) );
$assert( 'escalation carries running consecutive_count', $threshold === (int) ( $escalation['context']['consecutive_count'] ?? 0 ) );
$assert( 'escalation carries the rejection reason', 'task_scheduler_agent_context_required' === ( $escalation['context']['rejection_reason'] ?? '' ) );

echo "\n[b2] Further rejections keep counting but do NOT re-escalate (no duplicate rows)\n";
RecurringRejectionTracker::record_rejection( 'workspace_disk_emergency_cleanup', 'workspace_disk_emergency_cleanup', 'task_scheduler_agent_context_required' );
RecurringRejectionTracker::record_rejection( 'workspace_disk_emergency_cleanup', 'workspace_disk_emergency_cleanup', 'task_scheduler_agent_context_required' );
$assert( 'still exactly one escalation log after extra ticks', 1 === count( $escalation_logs() ) );
$assert( 'count keeps climbing past threshold', ( $threshold + 2 ) === (int) RecurringRejectionTracker::degraded()['workspace_disk_emergency_cleanup']['count'] );

// ─── (c) A success resets the counter ───────────────────────────────

echo "\n[c] A success clears the counter — recovered binding is not degraded\n";
RecurringRejectionTracker::record_success( 'workspace_disk_emergency_cleanup' );
$assert( 'schedule no longer degraded after success', ! isset( RecurringRejectionTracker::degraded()['workspace_disk_emergency_cleanup'] ) );
$assert( 'tracker state for schedule is fully cleared', ! isset( RecurringRejectionTracker::all()['workspace_disk_emergency_cleanup'] ) );

echo "\n[c2] After reset, a fresh rejection streak escalates again\n";
$GLOBALS['datamachine_test_logs'] = array();
for ( $i = 1; $i <= $threshold; $i++ ) {
	RecurringRejectionTracker::record_rejection( 'workspace_disk_emergency_cleanup', 'workspace_disk_emergency_cleanup', 'task_scheduler_agent_context_required' );
}
$assert( 'escalation fires again on a new streak', 1 === count( $escalation_logs() ) );

// ─── Isolation: independent schedules track independently ───────────

echo "\n[d] Distinct schedules track independently\n";
$reset_state();
RecurringRejectionTracker::record_rejection( 'schedule_a', 'task_a', 'task_scheduler_rejected' );
for ( $i = 1; $i <= $threshold; $i++ ) {
	RecurringRejectionTracker::record_rejection( 'schedule_b', 'task_b', 'task_scheduler_rejected' );
}
$degraded = RecurringRejectionTracker::degraded();
$assert( 'schedule_a (one rejection) is not degraded', ! isset( $degraded['schedule_a'] ) );
$assert( 'schedule_b (threshold rejections) is degraded', isset( $degraded['schedule_b'] ) );
$assert( 'a success on schedule_b does not touch schedule_a', ( static function () {
	RecurringRejectionTracker::record_success( 'schedule_b' );
	return 1 === (int) ( RecurringRejectionTracker::all()['schedule_a']['count'] ?? 0 );
} )() );

// ─── Production wiring (source-grep) ────────────────────────────────

echo "\n[e] Production wiring is in place\n";
$provider_source = file_get_contents( dirname( __DIR__ ) . '/inc/Engine/AI/System/SystemAgentServiceProvider.php' );
$abilities_source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/SystemAbilities.php' );
$command_source   = file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/SystemCommand.php' );
$assert( 'dispatch seam records rejections', str_contains( $provider_source, 'RecurringRejectionTracker::record_rejection' ) );
$assert( 'dispatch seam records successes', str_contains( $provider_source, 'RecurringRejectionTracker::record_success' ) );
$assert( 'health surface reads degraded schedules', str_contains( $abilities_source, 'RecurringRejectionTracker::degraded()' ) );
$assert( 'health surface exposes failing status', str_contains( $abilities_source, "\$status = 'failing'" ) );
$assert( 'health surface exposes rejected_schedules', str_contains( $abilities_source, "'rejected_schedules'" ) );
$assert( 'CLI renders rejected schedules', str_contains( $command_source, 'Rejected schedules:' ) );
$assert( 'gate decision (return false) is untouched in TaskScheduler', str_contains( file_get_contents( dirname( __DIR__ ) . '/inc/Engine/Tasks/TaskScheduler.php' ), 'task_scheduler_agent_context_required' ) );

echo "\n";
if ( $failures > 0 ) {
	echo "=== recurring-rejection-escalation-smoke: {$failures}/{$total} FAILED ===\n";
	exit( 1 );
}
echo "=== recurring-rejection-escalation-smoke: ALL PASS ({$total} assertions) ===\n";
