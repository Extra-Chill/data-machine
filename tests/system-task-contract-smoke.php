<?php
/**
 * Pure-PHP smoke for the public SystemTask contract.
 *
 * Run with: php tests/system-task-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$root = dirname( __DIR__ );

function datamachine_contract_assert_contains( string $haystack, string $needle, string $message, array &$failures, int &$passes ): void {
	if ( str_contains( $haystack, $needle ) ) {
		echo "  [PASS] {$message}\n";
		++$passes;
		return;
	}

	echo "  [FAIL] {$message}\n";
	$failures[] = $message;
}

$system_task = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/SystemTask.php' ) ?: '';
$step        = file_get_contents( $root . '/inc/Core/Steps/SystemTask/SystemTaskStep.php' ) ?: '';
$settings    = file_get_contents( $root . '/inc/Core/Steps/SystemTask/SystemTaskSettings.php' ) ?: '';

$failures = array();
$passes   = 0;

echo "=== system-task-contract-smoke ===\n";

echo "\n[1] task_type is the canonical workflow/settings key\n";
datamachine_contract_assert_contains( $system_task, "'task_type' => \$this->getTaskType()", 'SystemTask::getWorkflow writes task_type', $failures, $passes );
datamachine_contract_assert_contains( $settings, "'task_type' => array", 'SystemTaskSettings exposes task_type field', $failures, $passes );

echo "\n[2] system task step reads only task_type\n";
datamachine_contract_assert_contains( $step, "\$settings['task_type'] ?? ''", 'SystemTaskStep reads task_type directly', $failures, $passes );

echo "\n[3] legacy task alias fails explicitly\n";
datamachine_contract_assert_contains( $step, 'system_task_legacy_task_field', 'SystemTaskStep has explicit legacy task failure code', $failures, $passes );
datamachine_contract_assert_contains( $step, 'flow_step_settings.task_type', 'SystemTaskStep missing-task error names canonical task_type field', $failures, $passes );

echo "\n[4] task metadata documents manual-run guardrails\n";
datamachine_contract_assert_contains( $system_task, 'supports_run?: bool', 'SystemTask metadata documents supports_run', $failures, $passes );
datamachine_contract_assert_contains( $system_task, 'params_schema?: array', 'SystemTask metadata documents params_schema', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " system task contract assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} system task contract assertions passed.\n";
