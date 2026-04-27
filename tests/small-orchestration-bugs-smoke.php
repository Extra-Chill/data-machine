<?php
/**
 * Pure-PHP smoke test for small orchestration bug fixes (#1342, #1349).
 *
 * Run with: php tests/small-orchestration-bugs-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/inc/Core/JobStatus.php';

use DataMachine\Core\JobStatus;

$failed = 0;
$total  = 0;

function small_orchestration_assert( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}" . ( '' !== $detail ? " - {$detail}" : '' ) . "\n";
}

/**
 * Mirror of SystemTaskStep's post-task success resolver.
 */
function resolve_system_task_success_for_test( bool $success, string $child_status, array $child_data ): array {
	$error_msg = '';

	if ( $success && JobStatus::isStatusFailure( $child_status ) ) {
		$success   = false;
		$error_msg = $child_data['error'] ?? 'Task reported failure';
	}

	$skipped = ! empty( $child_data['skipped'] );
	if ( $skipped ) {
		$success = true;
	}

	return array( $success, $error_msg, $skipped );
}

/**
 * Mirror of SystemTaskStep's catch-block "mark failed if still processing" guard.
 */
function should_mark_exception_failed_for_test( string $child_status ): bool {
	return JobStatus::PROCESSING === $child_status;
}

/**
 * Mirror of QueueAbility::moveQueueSlot after flow lookup has loaded the queue.
 * Returns [result, write_count, updated_queue].
 */
function move_queue_slot_for_test( array $queue, int $from_index, int $to_index ): array {
	$writes       = 0;
	$queue_length = count( $queue );

	if ( $from_index < 0 ) {
		return array( array( 'success' => false, 'error' => 'from_index is required and must be a non-negative integer' ), $writes, $queue );
	}

	if ( $to_index < 0 ) {
		return array( array( 'success' => false, 'error' => 'to_index is required and must be a non-negative integer' ), $writes, $queue );
	}

	if ( $from_index >= $queue_length ) {
		return array( array( 'success' => false, 'error' => sprintf( 'from_index %d is out of range. Queue has %d item(s).', $from_index, $queue_length ) ), $writes, $queue );
	}

	if ( $to_index >= $queue_length ) {
		return array( array( 'success' => false, 'error' => sprintf( 'to_index %d is out of range. Queue has %d item(s).', $to_index, $queue_length ) ), $writes, $queue );
	}

	if ( $from_index === $to_index ) {
		return array(
			array(
				'success'      => true,
				'from_index'   => $from_index,
				'to_index'     => $to_index,
				'queue_length' => $queue_length,
				'message'      => 'No move needed (same position).',
			),
			$writes,
			$queue,
		);
	}

	$item = $queue[ $from_index ];
	array_splice( $queue, $from_index, 1 );
	array_splice( $queue, $to_index, 0, array( $item ) );
	++$writes;

	return array(
		array(
			'success'      => true,
			'from_index'   => $from_index,
			'to_index'     => $to_index,
			'queue_length' => $queue_length,
		),
		$writes,
		$queue,
	);
}

echo "\n[1] System task status handling uses canonical JobStatus values\n";
small_orchestration_assert(
	'processing child status is recognized in exception path',
	should_mark_exception_failed_for_test( JobStatus::PROCESSING )
);
small_orchestration_assert(
	'uppercase PROCESSING literal is not treated as canonical status',
	! should_mark_exception_failed_for_test( 'PROCESSING' )
);

list( $success, $error_msg ) = resolve_system_task_success_for_test( true, JobStatus::FAILED, array( 'error' => 'boom' ) );
small_orchestration_assert( 'failed child job flips step success to false', false === $success );
small_orchestration_assert( 'failed child job exposes child error', 'boom' === $error_msg );

list( $success, $error_msg ) = resolve_system_task_success_for_test( true, JobStatus::failed( 'boom' )->toString(), array() );
small_orchestration_assert( 'compound failed child status is interpreted as failure', false === $success );
small_orchestration_assert( 'compound failed status falls back to generic error', 'Task reported failure' === $error_msg );

list( $success ) = resolve_system_task_success_for_test( true, JobStatus::COMPLETED, array() );
small_orchestration_assert( 'completed child job stays successful', true === $success );

echo "\n[2] Queue same-position move returns real length without writing\n";
$queue = array(
	array( 'prompt' => 'one' ),
	array( 'prompt' => 'two' ),
	array( 'prompt' => 'three' ),
);

list( $result, $writes, $updated_queue ) = move_queue_slot_for_test( $queue, 1, 1 );
small_orchestration_assert( 'same-position move succeeds', true === $result['success'] );
small_orchestration_assert( 'same-position move returns real queue length', 3 === $result['queue_length'] );
small_orchestration_assert( 'same-position move does not write', 0 === $writes );
small_orchestration_assert( 'same-position move leaves queue unchanged', $queue === $updated_queue );

list( $result, $writes, $updated_queue ) = move_queue_slot_for_test( $queue, 0, 2 );
small_orchestration_assert( 'actual move writes once', 1 === $writes );
small_orchestration_assert( 'actual move preserves queue length', 3 === $result['queue_length'] );
small_orchestration_assert( 'actual move reorders queue', array( 'two', 'three', 'one' ) === array_column( $updated_queue, 'prompt' ) );

list( $result, $writes ) = move_queue_slot_for_test( $queue, 5, 5 );
small_orchestration_assert( 'same out-of-range indexes do not bypass range validation', false === $result['success'] );
small_orchestration_assert( 'out-of-range same-position move does not write', 0 === $writes );

echo "\n[3] Production source contains the fixed comparisons/order\n";
$system_task_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Steps/SystemTask/SystemTaskStep.php' );
$queue_source       = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Flow/QueueAbility.php' );

small_orchestration_assert(
	'SystemTaskStep compares processing status through JobStatus::PROCESSING',
	str_contains( $system_task_source, 'JobStatus::PROCESSING === $status' )
);
small_orchestration_assert(
	'SystemTaskStep uses JobStatus::isStatusFailure for child failure detection',
	str_contains( $system_task_source, 'JobStatus::isStatusFailure( $child_status )' )
);
small_orchestration_assert(
	'SystemTaskStep no longer compares uppercase FAILED literal',
	! str_contains( $system_task_source, 'str_starts_with( $child_status, \'FAILED\' )' )
);
small_orchestration_assert(
	'QueueAbility same-position branch returns queue_length from loaded queue',
	str_contains( $queue_source, '\'queue_length\' => $queue_length' )
);

if ( 0 === $failed ) {
	echo "\n=== small-orchestration-bugs-smoke: all {$total} assertions passed ===\n";
	exit( 0 );
}

echo "\n=== small-orchestration-bugs-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
