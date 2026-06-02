<?php
/**
 * Pure-PHP smoke for recurring schedule hook contracts.
 *
 * Run with: php tests/recurring-schedule-registry-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		if ( 'datamachine_recurring_schedules' !== $hook ) {
			return $value;
		}

		return array(
			'cleanup_fast' => array(
				'task_type' => 'cleanup',
				'interval'  => 'hourly',
			),
			'cleanup_slow' => array(
				'task_type' => 'cleanup',
				'interval'  => 'daily',
			),
		);
	}
}

require_once dirname( __DIR__ ) . '/inc/Engine/Tasks/RecurringScheduleRegistry.php';

use DataMachine\Engine\Tasks\RecurringScheduleRegistry;

function datamachine_recurring_contract_assert_same( $expected, $actual, string $message, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		echo "  [PASS] {$message}\n";
		++$passes;
		return;
	}

	echo "  [FAIL] {$message}\n";
	echo '         expected: ' . var_export( $expected, true ) . "\n";
	echo '         actual:   ' . var_export( $actual, true ) . "\n";
	$failures[] = $message;
}

$failures = array();
$passes   = 0;

echo "=== recurring-schedule-registry-contract-smoke ===\n";

$schedules = RecurringScheduleRegistry::all();

echo "\n[1] hooks are schedule-scoped\n";
datamachine_recurring_contract_assert_same( 'datamachine_recurring_cleanup_fast', RecurringScheduleRegistry::hookFor( $schedules['cleanup_fast'] ), 'fast cleanup schedule gets unique hook', $failures, $passes );
datamachine_recurring_contract_assert_same( 'datamachine_recurring_cleanup_slow', RecurringScheduleRegistry::hookFor( $schedules['cleanup_slow'] ), 'slow cleanup schedule gets unique hook', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " recurring schedule contract assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} recurring schedule contract assertions passed.\n";
