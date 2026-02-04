<?php
/**
 * Job Helpers Trait
 *
 * Shared helper methods used across all Job ability classes.
 * Provides database access, formatting, and utility operations.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;
use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\Admin\DateFormatter;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;

defined( 'ABSPATH' ) || exit;

trait JobHelpers {

	protected Jobs $db_jobs;
	protected Flows $db_flows;
	protected ProcessedItems $db_processed_items;

	protected function initDatabases(): void {
		$this->db_jobs            = new Jobs();
		$this->db_flows           = new Flows();
		$this->db_processed_items = new ProcessedItems();
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Add formatted display fields for timestamps.
	 *
	 * @param array $job Job data.
	 * @return array Job data with *_display fields added.
	 */
	protected function addDisplayFields( array $job ): array {
		if ( isset( $job['created_at'] ) ) {
			$job['created_at_display'] = DateFormatter::format_for_display( $job['created_at'] );
		}

		if ( isset( $job['completed_at'] ) ) {
			$job['completed_at_display'] = DateFormatter::format_for_display( $job['completed_at'] );
		}

		return $job;
	}

	/**
	 * Create a new job for a flow execution.
	 *
	 * @param int $flow_id Flow ID to execute.
	 * @param int $pipeline_id Pipeline ID (optional, will be looked up if not provided).
	 * @return int|null Job ID on success, null on failure.
	 */
	protected function createJob( int $flow_id, int $pipeline_id = 0 ): ?int {
		if ( $pipeline_id <= 0 ) {
			$flow = $this->db_flows->get_flow( $flow_id );
			if ( ! $flow ) {
				do_action( 'datamachine_log', 'error', 'Job creation failed - flow not found', array( 'flow_id' => $flow_id ) );
				return null;
			}
			$pipeline_id = (int) $flow['pipeline_id'];
		}

		$job_id = $this->db_jobs->create_job(
			array(
				'pipeline_id' => $pipeline_id,
				'flow_id'     => $flow_id,
			)
		);

		if ( ! $job_id ) {
			do_action(
				'datamachine_log',
				'error',
				'Job creation failed - database insert failed',
				array(
					'flow_id'     => $flow_id,
					'pipeline_id' => $pipeline_id,
				)
			);
			return null;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Job created',
			array(
				'job_id'      => $job_id,
				'flow_id'     => $flow_id,
				'pipeline_id' => $pipeline_id,
			)
		);

		return $job_id;
	}

	/**
	 * Delete jobs based on criteria.
	 *
	 * @param array $criteria Deletion criteria ('all' => true or 'failed' => true).
	 * @param bool  $cleanup_processed Whether to cleanup associated processed items.
	 * @return array Result with deleted count and cleanup info.
	 */
	protected function deleteJobs( array $criteria, bool $cleanup_processed = false ): array {
		$job_ids_to_delete = array();

		if ( $cleanup_processed ) {
			global $wpdb;
			$jobs_table = $wpdb->prefix . 'datamachine_jobs';

			if ( ! empty( $criteria['failed'] ) ) {
				$failed_pattern = $wpdb->esc_like( 'failed' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$job_ids_to_delete = $wpdb->get_col( $wpdb->prepare( 'SELECT job_id FROM %i WHERE status LIKE %s', $jobs_table, $failed_pattern ) );
			} elseif ( ! empty( $criteria['all'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$job_ids_to_delete = $wpdb->get_col( $wpdb->prepare( 'SELECT job_id FROM %i', $jobs_table ) );
			}
		}

		$deleted_count = $this->db_jobs->delete_jobs( $criteria );

		if ( false === $deleted_count ) {
			return array(
				'success'                 => false,
				'jobs_deleted'            => 0,
				'processed_items_cleaned' => 0,
			);
		}

		if ( $cleanup_processed && ! empty( $job_ids_to_delete ) ) {
			foreach ( $job_ids_to_delete as $job_id ) {
				$this->db_processed_items->delete_processed_items( array( 'job_id' => (int) $job_id ) );
			}
		}

		return array(
			'success'                 => true,
			'jobs_deleted'            => $deleted_count,
			'processed_items_cleaned' => $cleanup_processed ? count( $job_ids_to_delete ) : 0,
		);
	}
}
