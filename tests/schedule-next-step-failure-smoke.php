<?php
/**
 * Static smoke test for next-step scheduling failure ownership.
 *
 * Run with: php tests/schedule-next-step-failure-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_schedule_next_step_failure_smoke( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
	++$failed;
}

$source  = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ScheduleNextStepAbility.php' ) ?: '';
$execute = strstr( $source, 'public function execute' ) ?: '';
$helper  = strstr( $source, 'private function failScheduling' ) ?: '';

echo "Case 1: schedule-next-step owns Action Scheduler failures\n";
assert_schedule_next_step_failure_smoke( 'helper exists', str_contains( $source, 'private function failScheduling' ) );
assert_schedule_next_step_failure_smoke( 'non-positive action scheduler result is checked', str_contains( $execute, '(int) $action_id <= 0' ) );
assert_schedule_next_step_failure_smoke( 'failed scheduling routes through helper', str_contains( $execute, "'next_step_schedule_failed'") );
assert_schedule_next_step_failure_smoke( 'helper failure happens before returning action result', strpos( $execute, 'if ( ! is_numeric( $action_id )' ) < strrpos( $execute, "'success'   => is_numeric( $" . 'action_id' ) );

echo "Case 2: unscheduled jobs are requeued or finalized through fail-job\n";
assert_schedule_next_step_failure_smoke( 'helper calls fail-job action', str_contains( $helper, "'datamachine_fail_job'") );
assert_schedule_next_step_failure_smoke( 'failure carries current next step id for retry', str_contains( $helper, "'flow_step_id'      => $" . 'flow_step_id') );
assert_schedule_next_step_failure_smoke( 'failure is retryable for scheduler handoff recovery', str_contains( $helper, "'retryable'         => true") );
assert_schedule_next_step_failure_smoke( 'failure records next step context', str_contains( $helper, "'next_flow_step_id' => $" . 'flow_step_id') );

echo "Case 3: early returns before scheduling are not silent\n";
assert_schedule_next_step_failure_smoke( 'missing flow id calls helper', str_contains( $execute, "'missing_flow_id_during_data_storage'") );
assert_schedule_next_step_failure_smoke( 'missing flow id failure precedes false return', strpos( $execute, "'missing_flow_id_during_data_storage'") < strpos( $execute, "'Flow ID missing during data storage.'" ) );

echo "\nSchedule-next-step failure smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
