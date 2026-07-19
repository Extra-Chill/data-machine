<?php
/**
 * Smoke tests for scheduler health diagnostics wiring.
 *
 * Run with: php tests/system-health-scheduler-smoke.php
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );

function datamachine_scheduler_health_assert_contains( string $needle, string $haystack, string $message ): void {
	if ( str_contains( $haystack, $needle ) ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

echo "=== system-health-scheduler-smoke ===\n";

$system_abilities = file_get_contents( $root . '/inc/Abilities/SystemAbilities.php' );
$system_command   = file_get_contents( $root . '/inc/Cli/Commands/SystemCommand.php' );

if ( false === $system_abilities || false === $system_command ) {
	echo "  [FAIL] Unable to read changed files\n";
	exit( 1 );
}

datamachine_scheduler_health_assert_contains( "'scheduler' => array", $system_abilities, 'scheduler check is registered' );
datamachine_scheduler_health_assert_contains( "wp_next_scheduled( 'action_scheduler_run_queue' )", $system_abilities, 'WP-Cron runner lag is inspected' );
datamachine_scheduler_health_assert_contains( 'RecurringScheduler::GROUP', $system_abilities, 'diagnostics scope to Data Machine actions' );
datamachine_scheduler_health_assert_contains( 'FlowScheduleReconciler', $system_abilities, 'flow schedule coverage is audited' );
datamachine_scheduler_health_assert_contains( 'flows reconcile-schedules', $system_abilities, 'missing coverage includes operator repair guidance' );
datamachine_scheduler_health_assert_contains( 'blocked by in-progress Action Scheduler ownership', $system_abilities, 'blocked ownership makes scheduler health fail' );
datamachine_scheduler_health_assert_contains( 'Recover stale or uncertain in-progress jobs/actions first', $system_abilities, 'blocked health recommends ownership recovery first' );
datamachine_scheduler_health_assert_contains( 'wp datamachine drain', $system_abilities, 'stale scheduler output recommends an explicit drain' );
datamachine_scheduler_health_assert_contains( 'wp datamachine system run daily_memory_generation --param=agent_slug=<slug> --wait', $system_abilities, 'stale scheduler output recommends scoped daily memory remediation' );
datamachine_scheduler_health_assert_contains( 'Due now:', $system_command, 'CLI table output shows due Action Scheduler work' );
datamachine_scheduler_health_assert_contains( 'Daily memory:', $system_command, 'CLI table output shows daily memory schedule state' );
datamachine_scheduler_health_assert_contains( 'Flow schedules:', $system_command, 'CLI table output shows flow schedule coverage' );
datamachine_scheduler_health_assert_contains( '[--wait]', $system_command, 'system run exposes a job-scoped wait option' );
datamachine_scheduler_health_assert_contains( 'DrainJobAbility', $system_command, 'system run drains only the created job when waiting' );

echo "\nAll scheduler health smoke assertions passed.\n";
