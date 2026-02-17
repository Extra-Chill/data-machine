<?php
/**
 * Jobs database status management component.
 *
 * Handles job status transitions, validation, and state machine logic.
 * Part of the modular Jobs architecture following single responsibility principle.
 *
 * Pipeline → Flow architecture implementation.
 *
 * @package    Data_Machine
 * @subpackage Core\Database\Jobs
 * @since      0.15.0
 */

namespace DataMachine\Core\Database\Jobs;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\JobStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class JobsStatus extends BaseRepository {

	const TABLE_NAME = 'datamachine_jobs';

	/**
	 * Update the status for a job.
	 *
	 * @param int    $job_id The job ID.
	 * @param string $status The new status (e.g., 'processing').
	 * @return bool True on success, false on failure.
	 */
	public function start_job( int $job_id, string $status = 'processing' ): bool {
		if ( empty( $job_id ) ) {
			return false;
		}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->update(
			$this->table_name,
			array(
				'status' => $status,
			),
			array( 'job_id' => $job_id ),
			array( '%s' ), // Format for data
			array( '%d' )  // Format for WHERE
		);

		return false !== $updated;
	}

	/**
	 * Update the status and completed_at time for a job.
	 *
	 * Accepts compound statuses like "agent_skipped - reason" via JobStatus validation.
	 *
	 * @param int    $job_id The job ID.
	 * @param string $status The final status (any JobStatus final status, may be compound).
	 * @return bool True on success, false on failure.
	 */
	public function complete_job( int $job_id, string $status ): bool {
		// Validate using JobStatus - supports compound statuses like "agent_skipped - reason"
		if ( empty( $job_id ) || ! JobStatus::isStatusFinal( $status ) ) {
			return false;
		}

		$update_data = array(
			'status'       => $status,
			'completed_at' => current_time( 'mysql', true ),
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->update(
			$this->table_name,
			$update_data,
			array( 'job_id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			do_action( 'datamachine_job_complete', $job_id, $status );
		}

		return false !== $updated;
	}

	/**
	 * Update job status.
	 *
	 * @param int    $job_id The job ID.
	 * @param string $status The new status.
	 * @return bool True on success, false on failure.
	 */
	public function update_job_status( int $job_id, string $status ): bool {

		if ( empty( $job_id ) ) {
			return false;
		}

		$update_data = array( 'status' => $status );
		$format      = array( '%s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->update(
			$this->table_name,
			$update_data,
			array( 'job_id' => $job_id ),
			$format,
			array( '%d' )
		);

		return false !== $updated;
	}
}
