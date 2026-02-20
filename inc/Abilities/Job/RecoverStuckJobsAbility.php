<?php
/**
 * Recover Stuck Jobs Ability
 *
 * Recovers jobs stuck in processing state: jobs with status override in engine_data, and jobs exceeding timeout threshold.
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
					'description'         => __( 'Recover jobs stuck in processing state: jobs with status override in engine_data, and jobs exceeding timeout threshold.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'       => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Preview what would be updated without making changes', 'data-machine' ),
							),
							'flow_id'       => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Filter to recover jobs only for a specific flow ID', 'data-machine' ),
							),
							'timeout_hours' => array(
								'type'        => 'integer',
								'default'     => 2,
								'description' => __( 'Hours before a processing job without status override is considered timed out', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'recovered' => array( 'type' => 'integer' ),
							'skipped'   => array( 'type' => 'integer' ),
							'timed_out' => array( 'type' => 'integer' ),
							'requeued'  => array( 'type' => 'integer' ),
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
	 * and updates them to their intended final status. Also recovers timed-out jobs.
	 *
	 * @param array $input Input parameters with optional dry_run, flow_id, and timeout_hours.
	 * @return array Result with recovered/skipped counts.
	 */
	public function execute( array $input ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		$dry_run       = ! empty( $input['dry_run'] );
		$flow_id       = isset( $input['flow_id'] ) && is_numeric( $input['flow_id'] ) ? (int) $input['flow_id'] : null;
		$timeout_hours = isset( $input['timeout_hours'] ) && is_numeric( $input['timeout_hours'] ) ? max( 1, (int) $input['timeout_hours'] ) : 2;

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
			$stuck_jobs = array();
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

		// Second recovery pass: timed-out jobs (processing without job_status override, older than timeout).
		$timeout_where = $wpdb->prepare(
			"WHERE status = 'processing' AND engine_data NOT LIKE %s AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
			'%"job_status"%',
			$timeout_hours
		);
		if ( $flow_id ) {
			$timeout_where .= $wpdb->prepare( ' AND flow_id = %d', $flow_id );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic WHERE clause
		$timed_out_jobs = $wpdb->get_results(
			"SELECT job_id, flow_id, engine_data FROM {$table} {$timeout_where}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$timed_out = 0;
		$requeued  = 0;

		foreach ( $timed_out_jobs as $job ) {
			$engine_data = json_decode( $job->engine_data, true );
			if ( ! is_array( $engine_data ) ) {
				$engine_data = array();
			}

			$job_id      = (int) $job->job_id;
			$job_flow_id = (int) $job->flow_id;

			if ( $dry_run ) {
				++$timed_out;
				$jobs[] = array(
					'job_id'  => $job_id,
					'flow_id' => $job_flow_id,
					'status'  => 'would_timeout',
				);
			} else {
				// Mark as failed
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					$table,
					array(
						'status'       => 'failed',
						'completed_at' => current_time( 'mysql', true ),
					),
					array( 'job_id' => $job_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				if ( false !== $result ) {
					++$timed_out;
					$jobs[] = array(
						'job_id'  => $job_id,
						'flow_id' => $job_flow_id,
						'status'  => 'timed_out',
					);

					do_action( 'datamachine_job_complete', $job_id, 'failed' );

					// Check for queued_prompt_backup and requeue if found
					if ( isset( $engine_data['queued_prompt_backup']['prompt'] ) && isset( $engine_data['queued_prompt_backup']['flow_step_id'] ) ) {
						$flow = $this->db_flows->get_flow( $job_flow_id );
						if ( $flow && isset( $flow['flow_config'] ) ) {
							$flow_config = $flow['flow_config'];
							$step_id     = $engine_data['queued_prompt_backup']['flow_step_id'];
							$prompt      = $engine_data['queued_prompt_backup']['prompt'];

							if ( isset( $flow_config[ $step_id ] ) && isset( $flow_config[ $step_id ]['prompt_queue'] ) ) {
								$flow_config[ $step_id ]['prompt_queue'][] = array(
									'prompt'   => $prompt,
									'added_at' => gmdate( 'c' ),
								);

								$update_result = $this->db_flows->update_flow( $job_flow_id, array( 'flow_config' => $flow_config ) );
								if ( $update_result ) {
									++$requeued;
								}
							}
						}
					}
				} else {
					$jobs[] = array(
						'job_id'  => $job_id,
						'flow_id' => $job_flow_id,
						'status'  => 'skipped',
						'reason'  => 'Database update failed for timeout',
					);
				}
			}
		}

		$message = $dry_run
			? sprintf( 'Dry run complete. Would recover %d jobs, timeout %d jobs.', $recovered, $timed_out )
			: sprintf( 'Recovery complete. Recovered: %d, Timed out: %d, Requeued: %d', $recovered, $timed_out, $requeued );

		if ( ! $dry_run && ( $recovered > 0 || $timed_out > 0 ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Stuck jobs recovered via ability',
				array(
					'recovered' => $recovered,
					'timed_out' => $timed_out,
					'requeued'  => $requeued,
					'flow_id'   => $flow_id,
				)
			);
		}

		return array(
			'success'   => true,
			'recovered' => $recovered,
			'skipped'   => $skipped,
			'timed_out' => $timed_out,
			'requeued'  => $requeued,
			'dry_run'   => $dry_run,
			'jobs'      => $jobs,
			'message'   => $message,
		);
	}
}
