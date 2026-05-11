<?php
/**
 * Pure-PHP smoke test for system task DataPacket output handoff.
 *
 * Run with: php tests/system-task-output-packets-smoke.php
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );

$sources = array(
	'step'     => file_get_contents( $root . '/inc/Core/Steps/SystemTask/SystemTaskStep.php' ) ?: '',
	'task'     => file_get_contents( $root . '/inc/Engine/AI/System/Tasks/EmitDataPacketsTask.php' ) ?: '',
	'provider' => file_get_contents( $root . '/inc/Engine/AI/System/SystemAgentServiceProvider.php' ) ?: '',
	'engine'   => file_get_contents( $root . '/inc/Abilities/Engine/ExecuteStepAbility.php' ) ?: '',
);

$failures = array();
$passes   = 0;

function assert_system_task_output_contains( string $haystack, string $needle, string $message, array &$failures, int &$passes ): void {
	if ( str_contains( $haystack, $needle ) ) {
		++$passes;
		echo "  [PASS] {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "  [FAIL] {$message}\n";
	echo "    missing: {$needle}\n";
}

echo "=== system-task-output-packets-smoke ===\n";

echo "\n[1] SystemTaskStep exposes child task packet output\n";
assert_system_task_output_contains( $sources['step'], 'output_data_packets', 'SystemTaskStep reads child output_data_packets', $failures, $passes );
assert_system_task_output_contains( $sources['step'], 'replace_data_packets', 'SystemTaskStep can replace incoming packets', $failures, $passes );
assert_system_task_output_contains( $sources['step'], 'suppress_result_packet', 'SystemTaskStep can suppress synthetic result packet', $failures, $passes );
assert_system_task_output_contains( $sources['step'], "\$this->engine->set( 'job_status'", 'SystemTaskStep propagates child job_status override to parent engine', $failures, $passes );
assert_system_task_output_contains( $sources['step'], 'normalizeOutputDataPackets', 'SystemTaskStep normalizes task packet declarations', $failures, $passes );

echo "\n[2] emit_data_packets task provides generic workflow boundary\n";
assert_system_task_output_contains( $sources['task'], "return 'emit_data_packets';", 'EmitDataPacketsTask declares task type', $failures, $passes );
assert_system_task_output_contains( $sources['task'], "'output_data_packets'", 'EmitDataPacketsTask writes output_data_packets', $failures, $passes );
assert_system_task_output_contains( $sources['task'], "'replace_data_packets'", 'EmitDataPacketsTask controls packet replacement', $failures, $passes );
assert_system_task_output_contains( $sources['task'], "'suppress_result_packet'", 'EmitDataPacketsTask controls result packet suppression', $failures, $passes );
assert_system_task_output_contains( $sources['task'], 'JobStatus::COMPLETED_NO_ITEMS', 'EmitDataPacketsTask can mark zero-output stages completed_no_items', $failures, $passes );

echo "\n[3] task is registered for execute-workflow system_task steps\n";
assert_system_task_output_contains( $sources['provider'], 'use DataMachine\\Engine\\AI\\System\\Tasks\\EmitDataPacketsTask;', 'SystemAgentServiceProvider imports EmitDataPacketsTask', $failures, $passes );
assert_system_task_output_contains( $sources['provider'], "\$tasks['emit_data_packets']", 'SystemAgentServiceProvider registers emit_data_packets', $failures, $passes );

echo "\n[4] engine status override can stop after zero emitted packets\n";
assert_system_task_output_contains( $sources['engine'], 'if ( $status_override )', 'ExecuteStepAbility honors status override before normal continuation', $failures, $passes );
assert_system_task_output_contains( $sources['engine'], 'JobStatus::COMPLETED_NO_ITEMS', 'ExecuteStepAbility recognizes completed_no_items statuses', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " system task output packet assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} system task output packet assertions passed.\n";
