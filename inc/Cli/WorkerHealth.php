<?php
/**
 * Pure worker dispatch-health classification.
 *
 * @package DataMachine\Cli
 */

namespace DataMachine\Cli;

defined( 'ABSPATH' ) || exit;

class WorkerHealth {

	/**
	 * Classify worker health from scheduler, queue trigger, and lease evidence.
	 *
	 * @param array<string,mixed> $evidence Normalized dispatch evidence.
	 * @return array<string,mixed> Stable health condition and remediation.
	 */
	public static function classify( array $evidence, int $stale_threshold_seconds = 900 ): array {
		$stale_threshold_seconds = max( 60, $stale_threshold_seconds );
		$scope                   = (string) ( $evidence['scope'] ?? 'global' );
		if ( 'global' !== $scope ) {
			return array(
				'condition'                    => 'lane_scope_unclassified',
				'scheduler_dispatcher_starved' => false,
				'stale_threshold_seconds'      => $stale_threshold_seconds,
				'recommendation'               => null,
			);
		}

		$due_count               = max( 0, (int) ( $evidence['due_count'] ?? 0 ) );
		$deferred                = max( 0, (int) ( $evidence['concurrency_deferred_actions'] ?? 0 ) );
		$stale_due_sample_age    = isset( $evidence['stale_due_sample_age_seconds'] ) ? max( 0, (int) $evidence['stale_due_sample_age_seconds'] ) : null;
		$queue_trigger_state     = (string) ( $evidence['queue_trigger_state'] ?? 'unknown' );
		$heartbeat_state         = (string) ( $evidence['worker_heartbeat_state'] ?? 'absent' );
		$worker_claimed          = 'fresh' === $heartbeat_state;
		$dispatcher_starved      = $due_count > 0
			&& ! $worker_claimed
			&& null !== $stale_due_sample_age
			&& $stale_due_sample_age > $stale_threshold_seconds
			&& in_array( $queue_trigger_state, array( 'overdue', 'missing' ), true );

		if ( $dispatcher_starved ) {
			$condition      = 'scheduler_dispatcher_starved';
			$recommendation = array(
				'code'    => 'run_supported_worker',
				'command' => 'wp datamachine worker run --once',
			);
		} elseif ( $worker_claimed ) {
			$condition      = 'worker_claimed_with_heartbeat';
			$recommendation = null;
		} elseif ( $due_count > 0 ) {
			$condition      = 'due_work_unclaimed';
			$recommendation = array(
				'code'    => 'wait_or_run_supported_worker',
				'command' => 'wp datamachine worker run --once',
			);
		} elseif ( $deferred > 0 ) {
			$condition      = 'downstream_concurrency_deferred';
			$recommendation = array(
				'code'    => 'inspect_job_liveness',
				'command' => 'wp datamachine jobs liveness --format=json',
			);
		} else {
			$condition      = 'no_due_work';
			$recommendation = null;
		}

		return array(
			'condition'                    => $condition,
			'scheduler_dispatcher_starved' => $dispatcher_starved,
			'stale_threshold_seconds'      => $stale_threshold_seconds,
			'recommendation'               => $recommendation,
		);
	}
}
