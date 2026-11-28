<?php
/**
 * Pure-PHP smoke test for retry lifecycle state determinism.
 *
 * Run with: php tests/retry-schedule-lifecycle-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public string $status = 'processing';

		public function get_job( int $job_id ): array {
			$job_id;
			return array( 'status' => $this->status );
		}

		public function update_job_status( int $job_id, string $status ): bool {
			$job_id;
			$this->status = $status;
			return true;
		}
	}
}

namespace DataMachine\Core {
	class EngineData {
		public static function mutate( int $job_id, callable $callback, string $event_type = 'mutation' ): array {
			unset( $job_id, $event_type );
			$current = $GLOBALS['engine_data'] ?? array();
			$next    = $callback( $current );
			if ( null === $next ) {
				return array( 'success' => false );
			}

			$GLOBALS['engine_data'] = $next;
			return array( 'success' => true );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	$failed = 0;
	$total  = 0;

	function assert_retry_schedule_lifecycle( string $name, bool $condition, string $detail = '' ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  [PASS] $name\n";
			return;
		}

		echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
		++$failed;
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$hook;
		$args;
		return $value;
	}

	function do_action( string $hook, mixed ...$args ): void {
		$hook;
		$args;
	}

	function wp_rand( int $min, int $max ): int {
		$max;
		return $min;
	}

	function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int|false {
		$GLOBALS['scheduled_retry'] = compact( 'timestamp', 'hook', 'args', 'group' );
		return $GLOBALS['schedule_retry_result'];
	}

	function datamachine_get_engine_data( int $job_id ): array {
		$job_id;
		return $GLOBALS['engine_data'] ?? array();
	}

	function datamachine_merge_engine_data( int $job_id, array $data ): void {
		$job_id;
		$GLOBALS['merged_engine_data'] = $data;
	}

	require_once __DIR__ . '/../inc/Core/JobRetryPolicy.php';

	echo "Case 1: scheduler failure leaves no pending retry metadata\n";
	$GLOBALS['engine_data']           = array();
	$GLOBALS['merged_engine_data']    = array();
	$GLOBALS['schedule_retry_result'] = 0;
	$GLOBALS['scheduled_retry']       = null;
	$jobs                             = new \DataMachine\Core\Database\Jobs\Jobs();
	$result                           = \DataMachine\Core\JobRetryPolicy::maybeRetry(
		123,
		'transient_failure',
		array(
			'flow_step_id' => 'step-1',
			'retryable'    => true,
		),
		$jobs
	);

	assert_retry_schedule_lifecycle( 'schedule failure is not reported as retried', false === $result['retried'] );
	assert_retry_schedule_lifecycle( 'schedule failure reports structured reason', 'retry_schedule_failed' === ( $result['reason'] ?? '' ) );
	assert_retry_schedule_lifecycle( 'job status remains processing when no retry action exists', 'processing' === $jobs->status );
	assert_retry_schedule_lifecycle( 'next_retry_at is not exposed in result', ! isset( $result['next_retry_at'] ) );
	assert_retry_schedule_lifecycle( 'next_retry_at is not persisted without action', ! isset( $GLOBALS['merged_engine_data']['retry']['next_retry_at'] ) );

	$GLOBALS['engine_data'] = array( 'retry' => array( 'next_retry_at' => '2026-07-23T12:00:00Z' ) );
	\DataMachine\Core\JobRetryPolicy::maybeRetry(
		123,
		'transient_failure',
		array(
			'flow_step_id' => 'step-1',
			'retryable'    => true,
		),
		$jobs
	);
	assert_retry_schedule_lifecycle( 'failed later schedule clears stale retry ownership', ! isset( $GLOBALS['engine_data']['retry']['next_retry_at'] ) );

	echo "Case 2: scheduler success publishes pending retry metadata\n";
	$GLOBALS['engine_data']           = array();
	$GLOBALS['merged_engine_data']    = array();
	$GLOBALS['schedule_retry_result'] = 456;
	$GLOBALS['scheduled_retry']       = null;
	$jobs                             = new \DataMachine\Core\Database\Jobs\Jobs();
	$result                           = \DataMachine\Core\JobRetryPolicy::maybeRetry(
		123,
		'transient_failure',
		array(
			'flow_step_id' => 'step-1',
			'retryable'    => true,
		),
		$jobs
	);

	assert_retry_schedule_lifecycle( 'schedule success is reported as retried', true === $result['retried'] );
	assert_retry_schedule_lifecycle( 'job moves pending after retry action exists', 'pending' === $jobs->status );
	assert_retry_schedule_lifecycle( 'next_retry_at is exposed in result', isset( $result['next_retry_at'] ) );
	assert_retry_schedule_lifecycle( 'next_retry_at is persisted after action exists', isset( $GLOBALS['merged_engine_data']['retry']['next_retry_at'] ) );

	echo "\nRetry schedule lifecycle smoke complete: {$total} assertions, {$failed} failures.\n";
	if ( $failed > 0 ) {
		exit( 1 );
	}
}
