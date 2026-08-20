<?php
/**
 * Pure-PHP smoke test for synchronous job draining ability (#1803).
 *
 * Run with: php tests/drain-job-ability-smoke.php
 *
 * @package DataMachine\Tests
 */

$ability_file   = __DIR__ . '/../inc/Abilities/Engine/DrainJobAbility.php';
$registry_file  = __DIR__ . '/../inc/Engine/Actions/DataMachineActions.php';
$ability_source = file_get_contents( $ability_file ) ?: '';
$registry_source = file_get_contents( $registry_file ) ?: '';

$assertions = 0;

function assert_drain_job_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_drain_job_contains( string $needle, string $haystack, string $message ): void {
	assert_drain_job_true( false !== strpos( $haystack, $needle ), $message );
}

function assert_drain_job_not_contains( string $needle, string $haystack, string $message ): void {
	assert_drain_job_true( false === strpos( $haystack, $needle ), $message );
}

assert_drain_job_contains( "wp_register_ability(\n\t\t\t\t'datamachine/drain-job'", $ability_source, 'drain-job ability is registered' );
assert_drain_job_contains( "'required'   => array( 'job_id' )", $ability_source, 'job_id is required input' );
assert_drain_job_contains( 'DEFAULT_STEP_BUDGET = 50', $ability_source, 'sane default step budget is declared' );
assert_drain_job_contains( 'DEFAULT_TIME_BUDGET_MS = 300000', $ability_source, 'sane default wall-clock budget is declared' );
assert_drain_job_contains( "'step_budget'", $ability_source, 'step budget is exposed in input schema' );
assert_drain_job_contains( "'time_budget_ms'", $ability_source, 'wall-clock budget is exposed in input schema' );
assert_drain_job_true( 1 === preg_match( "/'error_type'\s*=>\s*'action_scheduler_unavailable'/", $ability_source ), 'AS unavailability returns a typed error' );
assert_drain_job_contains( '( new ScopedDrainService() )->drain(', $ability_source, 'ability delegates to the shared scoped drain service' );
assert_drain_job_contains( "'job_ids'                  => array( $" . 'job_id )', $ability_source, 'drain is scoped to one job_id' );
assert_drain_job_contains( 'ScopedDrainService::HOOK_EXECUTE_STEP', $ability_source, 'drain includes execute-step actions' );
assert_drain_job_contains( 'ScopedDrainService::HOOK_BATCH_CHUNK', $ability_source, 'drain includes pipeline batch chunk actions' );
assert_drain_job_contains( 'JobStatus::isStatusFinal', $ability_source, 'drain stops on terminal job status' );
assert_drain_job_contains( "'actions_drained'", $ability_source, 'output reports actions drained' );
assert_drain_job_contains( "'wall_time_ms'", $ability_source, 'output reports elapsed wall time' );
assert_drain_job_contains( "use DataMachine\\Abilities\\Engine\\DrainJobAbility;", $registry_source, 'Engine action composition imports DrainJobAbility' );
assert_drain_job_contains( 'new DrainJobAbility();', $registry_source, 'Engine action composition instantiates DrainJobAbility' );
foreach ( array( 'RunFlowAbility', 'DrainJobAbility', 'ExecuteStepAbility', 'ScheduleNextStepAbility', 'ScheduleFlowAbility' ) as $provider ) {
	assert_drain_job_true( 1 === substr_count( $registry_source, "new {$provider}();" ), "Engine action composition instantiates {$provider} exactly once" );
}
assert_drain_job_true( ! file_exists( __DIR__ . '/../inc/Abilities/EngineAbilities.php' ), 'EngineAbilities registration facade is removed' );
assert_drain_job_not_contains( 'WP_CLI::', $ability_source, 'drain-job ability does not add a WP-CLI execution path' );
assert_drain_job_not_contains( 'as_run_queue', $ability_source, 'drain-job avoids unscoped queue draining' );

echo "OK ({$assertions} assertions)\n";
