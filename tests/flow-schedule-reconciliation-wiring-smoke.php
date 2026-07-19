<?php
/**
 * Pure-PHP smoke assertions for flow schedule reconciliation wiring.
 *
 * Run with: php tests/flow-schedule-reconciliation-wiring-smoke.php
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );

function datamachine_flow_reconcile_assert_contains( string $needle, string $haystack, string $message ): void {
	if ( str_contains( $haystack, $needle ) ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

echo "=== flow-schedule-reconciliation-wiring-smoke ===\n";

$plugin     = file_get_contents( $root . '/data-machine.php' );
$migration  = file_get_contents( $root . '/inc/migrations/flows.php' );
$runtime    = file_get_contents( $root . '/inc/migrations/runtime.php' );
$command    = file_get_contents( $root . '/inc/Cli/Commands/Flows/FlowsCommand.php' );
$reconciler = file_get_contents( $root . '/inc/Api/Flows/FlowScheduleReconciler.php' );
$scheduler  = file_get_contents( $root . '/inc/Engine/Tasks/RecurringScheduler.php' );
$lock       = file_get_contents( $root . '/inc/Api/Flows/FlowScheduleReconciliationLock.php' );

foreach ( array( $plugin, $migration, $runtime, $command, $reconciler, $scheduler, $lock ) as $source ) {
	if ( false === $source ) {
		echo "  [FAIL] Unable to read reconciliation source files\n";
		exit( 1 );
	}
}

datamachine_flow_reconcile_assert_contains( 'ReconcileFlowSchedulesAbility', $plugin, 'ability is registered with the full runtime' );
datamachine_flow_reconcile_assert_contains( 'datamachine_mark_flow_schedule_reconciliation', $runtime, 'deploy migrations mark schedule repair' );
datamachine_flow_reconcile_assert_contains( "add_action( 'action_scheduler_init'", $migration, 'repair waits for Action Scheduler initialization' );
datamachine_flow_reconcile_assert_contains( "delete_option( 'datamachine_flow_schedule_reconciliation_pending' )", $migration, 'successful repair clears the marker' );
datamachine_flow_reconcile_assert_contains( 'Flows::is_flow_enabled', $reconciler, 'reconciler excludes disabled persisted flows' );
datamachine_flow_reconcile_assert_contains( "array( 'manual', 'one_time' )", $reconciler, 'manual and one-time definitions are excluded' );
datamachine_flow_reconcile_assert_contains( "array( 'pending', 'in-progress' )", $scheduler, 'pending and in-progress actions count as coverage' );
datamachine_flow_reconcile_assert_contains( 'last_attempt_gmt', $reconciler, 'in-progress freshness uses last-attempt timing' );
datamachine_flow_reconcile_assert_contains( 'claim_created_gmt', $reconciler, 'in-progress freshness uses claim timing' );
datamachine_flow_reconcile_assert_contains( 'RUNNING_STALE_AFTER   = 7200', $reconciler, 'stale in-progress threshold is two hours' );
datamachine_flow_reconcile_assert_contains( 'scheduleMatches', $reconciler, 'coverage verifies recurrence semantics' );
datamachine_flow_reconcile_assert_contains( 'LIMIT %d', $reconciler, 'direct Action Scheduler coverage query is bounded' );
datamachine_flow_reconcile_assert_contains( 'MAX_COVERAGE_ROWS     = 5000', $reconciler, 'large fleet health query has an explicit ceiling' );
datamachine_flow_reconcile_assert_contains( "'ActionScheduler_DBStore' !== get_class( \$store )", $reconciler, 'direct SQL requires the authoritative DB store' );
datamachine_flow_reconcile_assert_contains( "'timing_authoritative' => false", $reconciler, 'fallback in-progress ownership is explicitly uncertain' );
datamachine_flow_reconcile_assert_contains( "'blocked'", $reconciler, 'stale or uncertain ownership is reported as blocked' );
datamachine_flow_reconcile_assert_contains( 'FlowScheduleReconciliationLock::acquire', $reconciler, 'all apply paths acquire the shared lock' );
datamachine_flow_reconcile_assert_contains( 'FlowScheduleReconciliationLock::refresh', $reconciler, 'large apply loops refresh the lock lease' );
datamachine_flow_reconcile_assert_contains( 'add_option( self::OPTION_NAME', $lock, 'first lock acquisition is atomic' );
datamachine_flow_reconcile_assert_contains( 'option_value = %s', $lock, 'stale takeover and release compare exact lock payloads' );
datamachine_flow_reconcile_assert_contains( "'reconcile-schedules'", $command, 'flows CLI dispatches reconcile-schedules' );
datamachine_flow_reconcile_assert_contains( "isset( \$assoc_args['apply'] )", $command, 'CLI remains dry-run unless --apply is present' );
datamachine_flow_reconcile_assert_contains( 'WP_CLI::error( (string) $result[\'error\'], false )', $command, 'table failures print before exiting non-zero' );
datamachine_flow_reconcile_assert_contains( 'WP_CLI::halt( 1 )', $command, 'JSON and table failures return non-zero' );
datamachine_flow_reconcile_assert_contains( "(int) ( \$result['invalid'] ?? 0 ) > 0", $command, 'invalid definitions return non-zero without changing ability success' );
datamachine_flow_reconcile_assert_contains( 'Blocked: %d', $command, 'CLI summary surfaces blocked flows' );
datamachine_flow_reconcile_assert_contains( "! empty( \$result['transient'] )", $migration, 'deferred marker persists only for transient failures' );

echo "\nAll flow schedule reconciliation wiring assertions passed.\n";
