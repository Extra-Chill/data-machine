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
datamachine_contract_assert_contains( $settings, "\$raw_settings['task_type'] = \$raw_settings['task'];", 'SystemTaskSettings maps legacy task to task_type on sanitize', $failures, $passes );

echo "\n[2] legacy task key remains readable\n";
datamachine_contract_assert_contains( $step, "\$settings['task_type'] ?? ( \$settings['task'] ?? '' )", 'SystemTaskStep reads task_type with task fallback', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " system task contract assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} system task contract assertions passed.\n";
