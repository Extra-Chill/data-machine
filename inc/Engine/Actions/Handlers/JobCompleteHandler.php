<?php
/**
 * Handler for the datamachine_job_complete action.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.11.0
 */

namespace DataMachine\Engine\Actions\Handlers;

/**
 * Updates flow health cache when jobs complete.
 */
class JobCompleteHandler {

	/**
	 * Handle the job-complete action.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Job completion status.
	 */
	public static function handle( $job_id, $status ) {
		$jobs_ops = new \DataMachine\Core\Database\Jobs\JobsOperations();
		$jobs_ops->update_flow_health_cache( $job_id, $status );

		// Revert one-time flows to manual after execution.
		// The Action Scheduler single action auto-completes, but the scheduling_config
		// stays as one_time — revert it so the UI correctly shows "Manual" instead of
		// a stale one_time with a past timestamp.
		self::cleanup_one_time_flow( $job_id );
	}

	/**
	 * Revert a one-time flow's scheduling config to manual after execution.
	 *
	 * @param int $job_id Job ID.
	 */
	private static function cleanup_one_time_flow( $job_id ) {
		$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
		$job     = $db_jobs->get_job( $job_id );

		if ( ! $job || empty( $job->flow_id ) ) {
			return;
		}

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $job->flow_id );

		if ( ! $flow ) {
			return;
		}

		$scheduling = is_string( $flow->scheduling_config )
			? json_decode( $flow->scheduling_config, true )
			: (array) $flow->scheduling_config;

		if ( ( $scheduling['interval'] ?? '' ) === 'one_time' ) {
			$db_flows->update_flow_scheduling(
				$job->flow_id,
				array( 'interval' => 'manual' )
			);
		}
	}
}
