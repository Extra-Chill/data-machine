<?php
/**
 * Recover Stuck Jobs Ability
 *
 * Recovers jobs stuck in processing state that have a job_status override in engine_data.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class RecoverStuckJobsAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/recover-stuck-jobs',
				array(
					'label'               => __( 'Recover Stuck Jobs', 'data-machine' ),
					'description'         => __( 'Recover jobs stuck in processing state that have a job_status override in engine_data.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Preview what would be updated without making changes', 'data-machine' ),
							),
							'flow_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Filter to recover jobs only for a specific flow ID', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'recovered' => array( 'type' => 'integer' ),
							'skipped'   => array( 'type' => 'integer' ),
							'dry_run'   => array( 'type' => 'boolean' ),
							'jobs'      => array( 'type' => 'array' ),
							'message'   => array( 'type' => 'string' ),
							'error'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute recover-stuck-jobs ability.
	 *
	 * Finds jobs with status='processing' that have a job_status override in engine_data
	 * and updates them to their intended final status.
	 *
	 * @param array $input Input parameters with optional dry_run and flow_id.
	 * @return array Result with recovered/skipped counts.
	 */
	public function execute( array $input ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		$dry_run = ! empty( $input['dry_run'] );
		$flow_id = isset( $input['flow_id'] ) && is_numeric( $input['flow_id'] ) ? (int) $input['flow_id'] : null;

		$where_clause = "WHERE status = 'processing' AND engine_data LIKE '%\"job_status\"%'";
		if ( $flow_id ) {
			$where_clause .= $wpdb->prepare( ' AND flow_id = %d', $flow_id );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic WHERE clause
		$stuck_jobs = $wpdb->get_results(
			"SELECT job_id, flow_id, JSON_UNQUOTE(JSON_EXTRACT(engine_data, '$.job_status')) as target_status
			 FROM {$table}
			 {$where_clause}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $stuck_jobs ) ) {
			return array(
				'success'   => true,
				'recovered' => 0,
				'skipped'   => 0,
				'dry_run'   => $dry_run,
				'jobs'      => array(),
				'message'   => 'No stuck jobs found.',
			);
		}

		$recovered = 0;
		$skipped   = 0;
		$jobs      = array();

		foreach ( $stuck_jobs as $job ) {
			$status = $job->target_status;

			if ( ! $status || ! JobStatus::isStatusFinal( $status ) ) {
				++$skipped;
				$jobs[] = array(
					'job_id'  => (int) $job->job_id,
					'flow_id' => (int) $job->flow_id,
					'status'  => 'skipped',
					'reason'  => sprintf( 'Invalid or non-final status: %s', $status ?? 'null' ),
				);
				continue;
			}

			if ( $dry_run ) {
				++$recovered;
				$jobs[] = array(
					'job_id'        => (int) $job->job_id,
					'flow_id'       => (int) $job->flow_id,
					'status'        => 'would_recover',
					'target_status' => $status,
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					$table,
					array(
						'status'       => $status,
						'completed_at' => current_time( 'mysql', true ),
					),
					array( 'job_id' => $job->job_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				if ( false !== $result ) {
					++$recovered;
					$jobs[] = array(
						'job_id'        => (int) $job->job_id,
						'flow_id'       => (int) $job->flow_id,
						'status'        => 'recovered',
						'target_status' => $status,
					);

					do_action( 'datamachine_job_complete', $job->job_id, $status );
				} else {
					++$skipped;
					$jobs[] = array(
						'job_id'  => (int) $job->job_id,
						'flow_id' => (int) $job->flow_id,
						'status'  => 'skipped',
						'reason'  => 'Database update failed',
					);
				}
			}
		}

		$message = $dry_run
			? sprintf( 'Dry run complete. Would recover %d jobs, skip %d.', $recovered, $skipped )
			: sprintf( 'Recovery complete. Recovered: %d, Skipped: %d', $recovered, $skipped );

		if ( ! $dry_run && $recovered > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Stuck jobs recovered via ability',
				array(
					'recovered' => $recovered,
					'skipped'   => $skipped,
					'flow_id'   => $flow_id,
				)
			);
		}

		return array(
			'success'   => true,
			'recovered' => $recovered,
			'skipped'   => $skipped,
			'dry_run'   => $dry_run,
			'jobs'      => $jobs,
			'message'   => $message,
		);
	}
}
