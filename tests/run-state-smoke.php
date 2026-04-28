<?php
/**
 * Pure-PHP smoke test for DataMachine\Core\RunState.
 *
 * Run with: php tests/run-state-smoke.php
 *
 * Pins the generic resumable run-state vocabulary before the storage/event
 * migration lands. This deliberately does not assert any job-table behavior;
 * JobStatus::WAITING remains the existing webhook-gate job status until the
 * real run-state system is built.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Core/RunState.php';

use DataMachine\Core\RunState;

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "=== run-state-smoke ===\n";

echo "\n[1] vocabulary matches issue #1483\n";
$expected_states = array(
	'pending',
	'running',
	'waiting_for_tool',
	'waiting_for_input',
	'waiting_for_approval',
	'waiting_for_callback',
	'completed',
	'failed',
	'cancelled',
);

dm_assert( $expected_states === RunState::ALL_STATES, 'all states are ordered and complete' );
dm_assert( count( $expected_states ) === count( array_unique( RunState::ALL_STATES ) ), 'states are unique' );

echo "\n[2] validity is exact, not prefix-based\n";
foreach ( $expected_states as $state ) {
	dm_assert( RunState::is_valid( $state ), "{$state} is valid" );
}
dm_assert( ! RunState::is_valid( 'waiting' ), 'generic waiting is intentionally not a run state' );
dm_assert( ! RunState::is_valid( 'waiting_for_human' ), 'unregistered future wait reason is invalid until named' );

echo "\n[3] waiting states are explicit pause reasons\n";
$expected_waiting = array(
	'waiting_for_tool',
	'waiting_for_input',
	'waiting_for_approval',
	'waiting_for_callback',
);
dm_assert( $expected_waiting === RunState::WAITING_STATES, 'waiting states are explicit reason states' );
foreach ( $expected_waiting as $state ) {
	dm_assert( RunState::is_waiting( $state ), "{$state} is waiting" );
}
dm_assert( ! RunState::is_waiting( RunState::PENDING ), 'pending is not waiting' );
dm_assert( ! RunState::is_waiting( RunState::RUNNING ), 'running is not waiting' );
dm_assert( ! RunState::is_waiting( RunState::COMPLETED ), 'completed is not waiting' );

echo "\n[4] terminal states are distinct from resumable states\n";
$expected_terminal = array( 'completed', 'failed', 'cancelled' );
dm_assert( $expected_terminal === RunState::TERMINAL_STATES, 'terminal states are complete' );
foreach ( $expected_terminal as $state ) {
	dm_assert( RunState::is_terminal( $state ), "{$state} is terminal" );
}
dm_assert( ! RunState::is_terminal( RunState::WAITING_FOR_CALLBACK ), 'waiting_for_callback remains resumable' );
dm_assert( ! RunState::is_terminal( RunState::RUNNING ), 'running is not terminal' );

echo "\n=== run-state-smoke: ALL PASS ===\n";
