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

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/recover-stuck-jobs',
				array(
					'label'               => __( 'Recover Stuck Jobs', 'data-machine' ),
					'description'         => __( 'Recover jobs stuck in processing state: jobs with status override in engine_data, and jobs exceeding timeout threshold.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
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
							'success'       => array( 'type' => 'boolean' ),
							'recovered'     => array( 'type' => 'integer' ),
							'skipped'       => array( 'type' => 'integer' ),
							'timed_out'     => array( 'type' => 'integer' ),
							'stale_actions' => array( 'type' => 'integer' ),
							'requeued'      => array( 'type' => 'integer' ),
							'dry_run'       => array( 'type' => 'boolean' ),
							'jobs'          => array( 'type' => 'array' ),
							'message'       => array( 'type' => 'string' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
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
			"SELECT job_id, flow_id, engine_data
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
			$engine_data = json_decode( $job->engine_data, true );
			$status      = $engine_data['job_status'] ?? null;

			// Truncate to fit varchar(255) column. Full reason is in engine_data.
			if ( $status && strlen( $status ) > 255 ) {
				$status = substr( $status, 0, 252 ) . '...';
			}

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

		$timed_out     = 0;
		$stale_actions = 0;
		$requeued      = 0;

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

					// Check for queued_prompt_backup and requeue if found.
					// Slot-aware: AI backups go back to prompt_queue,
					// fetch backups go back to config_patch_queue.
					$backup = $engine_data['queued_prompt_backup'] ?? array();
					if ( ! empty( $backup ) && isset( $backup['flow_step_id'] ) ) {
						$slot = $backup['slot'] ?? \DataMachine\Abilities\Flow\QueueAbility::SLOT_PROMPT_QUEUE;
						$flow = $this->db_flows->get_flow( $job_flow_id );
						if ( $flow && isset( $flow['flow_config'] ) ) {
							$flow_config = $flow['flow_config'];
							$step_id     = $backup['flow_step_id'];

							if ( isset( $flow_config[ $step_id ] ) ) {
								$entry = null;

								if ( \DataMachine\Abilities\Flow\QueueAbility::SLOT_CONFIG_PATCH_QUEUE === $slot && isset( $backup['patch'] ) && is_array( $backup['patch'] ) ) {
									$entry = array(
										'patch'    => $backup['patch'],
										'added_at' => gmdate( 'c' ),
									);
								} elseif ( isset( $backup['prompt'] ) ) {
									$entry = array(
										'prompt'   => $backup['prompt'],
										'added_at' => gmdate( 'c' ),
									);
									$slot  = \DataMachine\Abilities\Flow\QueueAbility::SLOT_PROMPT_QUEUE;
								}

								if ( null !== $entry ) {
									if ( ! isset( $flow_config[ $step_id ][ $slot ] ) || ! is_array( $flow_config[ $step_id ][ $slot ] ) ) {
										$flow_config[ $step_id ][ $slot ] = array();
									}
									$flow_config[ $step_id ][ $slot ][] = $entry;

									$update_result = $this->db_flows->update_flow( $job_flow_id, array( 'flow_config' => $flow_config ) );
									if ( $update_result ) {
										++$requeued;
									}
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

		$terminal_actions = $this->getTerminalBackedInProgressActions( $flow_id );
		foreach ( $terminal_actions as $action ) {
			$job_id         = (int) $action['job_id'];
			$action_id      = (int) $action['action_id'];
			$job_flow_id    = (int) $action['flow_id'];
			$terminal_state = (string) $action['job_status'];

			if ( $dry_run ) {
				++$stale_actions;
				$jobs[] = array(
					'job_id'        => $job_id,
					'flow_id'       => $job_flow_id,
					'action_id'     => $action_id,
					'status'        => 'would_reconcile_action',
					'target_status' => $terminal_state,
				);
				continue;
			}

			if ( $this->completeTerminalBackedAction( $action_id, $job_id, $terminal_state ) ) {
				++$stale_actions;
				$jobs[] = array(
					'job_id'        => $job_id,
					'flow_id'       => $job_flow_id,
					'action_id'     => $action_id,
					'status'        => 'reconciled_action',
					'target_status' => $terminal_state,
				);
			} else {
				++$skipped;
				$jobs[] = array(
					'job_id'    => $job_id,
					'flow_id'   => $job_flow_id,
					'action_id' => $action_id,
					'status'    => 'skipped',
					'reason'    => 'Action Scheduler reconciliation failed',
				);
			}
		}

		$message = $dry_run
			? sprintf( 'Dry run complete. Would recover %d jobs, timeout %d jobs, reconcile %d terminal-backed actions.', $recovered, $timed_out, $stale_actions )
			: sprintf( 'Recovery complete. Recovered: %d, Timed out: %d, Reconciled actions: %d, Requeued: %d', $recovered, $timed_out, $stale_actions, $requeued );

		if ( ! $dry_run && ( $recovered > 0 || $timed_out > 0 || $stale_actions > 0 ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Stuck jobs recovered via ability',
				array(
					'recovered'     => $recovered,
					'timed_out'     => $timed_out,
					'stale_actions' => $stale_actions,
					'requeued'      => $requeued,
					'flow_id'       => $flow_id,
				)
			);
		}

		return array(
			'success'       => true,
			'recovered'     => $recovered,
			'skipped'       => $skipped,
			'timed_out'     => $timed_out,
			'stale_actions' => $stale_actions,
			'requeued'      => $requeued,
			'dry_run'       => $dry_run,
			'jobs'          => $jobs,
			'message'       => $message,
		);
	}

	/**
	 * Find in-progress Action Scheduler step actions whose Data Machine job is terminal.
	 *
	 * @param int|null $flow_id Optional flow filter.
	 * @return array<int,array{action_id:int,job_id:int,flow_id:int,job_status:string}>
	 */
	private function getTerminalBackedInProgressActions( ?int $flow_id ): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$jobs_table    = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are generated from the WP prefix.
		$actions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, args
				 FROM {$actions_table}
				 WHERE hook = %s
				 AND status = %s
				 AND args LIKE %s",
				'datamachine_execute_step',
				'in-progress',
				'%"job_id"%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $actions ) ) {
			return array();
		}

		$action_job_ids = array();
		foreach ( $actions as $action ) {
			$job_id = $this->extractActionJobId( (string) ( $action->args ?? '' ) );
			if ( $job_id > 0 ) {
				$action_job_ids[ (int) $action->action_id ] = $job_id;
			}
		}

		if ( empty( $action_job_ids ) ) {
			return array();
		}

		$job_placeholders    = implode( ',', array_fill( 0, count( $action_job_ids ), '%d' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( JobStatus::FINAL_STATUSES ), '%s' ) );
		$query_args          = array_values( $action_job_ids );
		$query_args          = array_merge( $query_args, JobStatus::FINAL_STATUSES );

		$sql = "SELECT job_id, flow_id, status
			 FROM {$jobs_table}
			 WHERE job_id IN ({$job_placeholders})
				 AND status IN ({$status_placeholders})";
		if ( $flow_id ) {
			$sql         .= ' AND flow_id = %d';
			$query_args[] = $flow_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic placeholder list is prepared below.
		$terminal_jobs = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The dynamic SQL contains only generated placeholders; values are supplied in matching order.
				$sql,
				$query_args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $terminal_jobs ) ) {
			return array();
		}

		$jobs_by_id = array();
		foreach ( $terminal_jobs as $job ) {
			$jobs_by_id[ (int) $job['job_id'] ] = $job;
		}

		$terminal_actions = array();
		foreach ( $action_job_ids as $action_id => $job_id ) {
			if ( ! isset( $jobs_by_id[ $job_id ] ) ) {
				continue;
			}

			$job                = $jobs_by_id[ $job_id ];
			$terminal_actions[] = array(
				'action_id'  => (int) $action_id,
				'job_id'     => (int) $job_id,
				'flow_id'    => (int) $job['flow_id'],
				'job_status' => (string) $job['status'],
			);
		}

		return $terminal_actions;
	}

	/**
	 * Extract the Data Machine job ID from Action Scheduler args.
	 *
	 * @param string $args Action Scheduler args payload.
	 * @return int Job ID, or 0 when unavailable.
	 */
	private function extractActionJobId( string $args ): int {
		$decoded = json_decode( $args, true );
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['job_id'] ) && is_numeric( $decoded['job_id'] ) ) {
				return (int) $decoded['job_id'];
			}

			foreach ( $decoded as $value ) {
				if ( is_array( $value ) && isset( $value['job_id'] ) && is_numeric( $value['job_id'] ) ) {
					return (int) $value['job_id'];
				}
			}
		}

		$unserialized = maybe_unserialize( $args );
		if ( is_array( $unserialized ) ) {
			if ( isset( $unserialized['job_id'] ) && is_numeric( $unserialized['job_id'] ) ) {
				return (int) $unserialized['job_id'];
			}

			foreach ( $unserialized as $value ) {
				if ( is_array( $value ) && isset( $value['job_id'] ) && is_numeric( $value['job_id'] ) ) {
					return (int) $value['job_id'];
				}
			}
		}

		return 0;
	}

	/**
	 * Complete a stale in-progress Action Scheduler action without touching the terminal job.
	 *
	 * @param int    $action_id Action Scheduler action ID.
	 * @param int    $job_id Data Machine job ID.
	 * @param string $job_status Terminal Data Machine job status.
	 * @return bool Whether the Action Scheduler row was reconciled.
	 */
	private function completeTerminalBackedAction( int $action_id, int $job_id, string $job_status ): bool {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		$now_gmt       = current_time( 'mysql', true );
		$now_local     = current_time( 'mysql', false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$actions_table,
			array(
				'status'             => 'complete',
				'claim_id'           => 0,
				'last_attempt_gmt'   => $now_gmt,
				'last_attempt_local' => $now_local,
			),
			array(
				'action_id' => $action_id,
				'status'    => 'in-progress',
			),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $result || 0 === (int) $result ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$logs_table,
			array(
				'action_id'      => $action_id,
				'message'        => sprintf(
					'Data Machine reconciled stale in-progress action: job %d is already terminal (%s).',
					$job_id,
					$job_status
				),
				'log_date_gmt'   => $now_gmt,
				'log_date_local' => $now_local,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return true;
	}
}
