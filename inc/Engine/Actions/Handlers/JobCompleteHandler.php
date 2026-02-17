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
	}
}
