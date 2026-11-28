<?php
/**
 * Smoke tests for cycle flow selection.
 *
 * Usage: php tests/smoke-cycle-flow-selector.php
 *
 * @package DataMachine
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Core/Flows/CycleFlowSelector.php';

use DataMachine\Core\Flows\CycleFlowSelector;

$passed = 0;
$failed = 0;

function datamachine_cycle_assert_eq( mixed $actual, mixed $expected, string $label ): void {
	global $passed, $failed;
	if ( $actual === $expected ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}

	echo '✗ ' . $label . PHP_EOL;
	echo '  expected: ' . var_export( $expected, true ) . PHP_EOL;
	echo '  actual:   ' . var_export( $actual, true ) . PHP_EOL;
	$failed++;
}

$scheduled_ready = array(
	array(
		'flow_id'           => 10,
		'flow_name'         => 'Daily Archivist',
		'scheduling_config' => array(
			'interval' => 'daily',
			'enabled'  => true,
		),
	),
);

$all_flows = array(
	$scheduled_ready[0],
	array(
		'flow_id'           => 20,
		'flow_name'         => 'World Creator',
		'scheduling_config' => array(
			'interval'     => 'manual',
			'cycle_policy' => 'every_cycle',
			'enabled'      => true,
		),
	),
	array(
		'flow_id'           => 30,
		'flow_name'         => 'Manual Draft',
		'scheduling_config' => array(
			'interval' => 'manual',
			'enabled'  => true,
		),
	),
	array(
		'flow_id'           => 40,
		'flow_name'         => 'Paused Cycle Flow',
		'scheduling_config' => array(
			'interval'     => 'manual',
			'cycle_policy' => 'every_cycle',
			'enabled'      => false,
		),
	),
);

$selected = CycleFlowSelector::select_due_flows( $scheduled_ready, $all_flows );

datamachine_cycle_assert_eq( array_column( array_column( $selected, 'flow' ), 'flow_id' ), array( 10, 20 ), 'selects scheduled due plus every-cycle manual flow' );
datamachine_cycle_assert_eq( array_column( $selected, 'reason' ), array( 'schedule_due', 'cycle_policy:every_cycle' ), 'records selection reasons' );
datamachine_cycle_assert_eq( CycleFlowSelector::is_every_cycle_flow( $all_flows[1]['scheduling_config'] ), true, 'manual every_cycle flow is cycle due' );
datamachine_cycle_assert_eq( CycleFlowSelector::is_every_cycle_flow( $all_flows[2]['scheduling_config'] ), false, 'plain manual flow is not cycle due' );
datamachine_cycle_assert_eq( CycleFlowSelector::is_every_cycle_flow( $all_flows[3]['scheduling_config'] ), false, 'disabled every-cycle flow is skipped' );

if ( $failed > 0 ) {
	echo PHP_EOL . "Failed: {$failed}" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
