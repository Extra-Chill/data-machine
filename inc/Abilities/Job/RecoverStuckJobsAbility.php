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

			if ( $this->hasActiveSchedulerWork( $job_id, $engine_data, $timeout_hours ) ) {
				++$skipped;
				$jobs[] = array(
					'job_id'  => $job_id,
					'flow_id' => $job_flow_id,
					'status'  => 'skipped',
					'reason'  => 'Pending or in-progress scheduler work exists',
				);
				continue;
			}

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

					// Restore drain-mode queued_prompt_backup if the prior run removed an entry.
					$backup = $engine_data['queued_prompt_backup'] ?? array();
					if ( ! empty( $backup ) && $this->restoreQueuedPromptBackup( $job_flow_id, $backup ) ) {
						++$requeued;
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
	 * Check whether scheduler or child work still owns executable work for a job.
	 *
	 * @param int                  $job_id Job ID.
	 * @param array<string, mixed> $engine_data Job engine data.
	 * @param int                  $timeout_hours Hours before in-progress actions are considered stale.
	 * @return bool True when pending or fresh in-progress work exists.
	 */
	private function hasActiveSchedulerWork( int $job_id, array $engine_data, int $timeout_hours ): bool {
		if ( $this->hasActiveStepAction( $job_id, $timeout_hours ) ) {
			return true;
		}

		if ( $this->hasActiveBatchWork( $job_id, $engine_data, $timeout_hours ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether Action Scheduler still owns executable step work for a job.
	 *
	 * Pipeline jobs can stay in the `processing` state across multiple scheduled
	 * steps. If a later `datamachine_execute_step` action is still pending or
	 * in-progress, timeout recovery must not mark the job failed just because the
	 * original job row is old.
	 *
	 * @param int $job_id Job ID.
	 * @param int $timeout_hours Hours before in-progress actions are considered stale.
	 * @return bool True when a pending or fresh in-progress step action exists.
	 */
	private function hasActiveStepAction( int $job_id, int $timeout_hours ): bool {
		global $wpdb;

		if ( $job_id <= 0 ) {
			return false;
		}

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$like_job_id   = '%"job_id":' . $wpdb->esc_like( (string) $job_id ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WP prefix.
		$actions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, args, status, scheduled_date_gmt, last_attempt_gmt
				 FROM {$actions_table}
				 WHERE hook = %s
				 AND status IN ( %s, %s )
				 AND args LIKE %s",
				'datamachine_execute_step',
				'pending',
				'in-progress',
				$like_job_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$timeout_seconds = max( 1, $timeout_hours ) * HOUR_IN_SECONDS;
		$now_gmt         = strtotime( current_time( 'mysql', true ) );

		foreach ( $actions as $action ) {
			if ( $job_id === $this->extractActionJobId( (string) ( $action->args ?? '' ) ) ) {
				if ( 'pending' === (string) $action->status ) {
					return true;
				}

				$last_attempt = (string) ( $action->last_attempt_gmt ?? '' );
				$scheduled    = (string) ( $action->scheduled_date_gmt ?? '' );
				$reference    = $last_attempt && '0000-00-00 00:00:00' !== $last_attempt ? $last_attempt : $scheduled;
				$started_at   = $reference ? strtotime( $reference ) : false;

				if ( false === $started_at || false === $now_gmt ) {
					return true;
				}

				if ( ( $now_gmt - $started_at ) < $timeout_seconds ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check whether a pipeline batch parent still has scheduled chunk or child work.
	 *
	 * Batch parents wait on `datamachine_pipeline_batch_chunk` actions and child jobs,
	 * not `datamachine_execute_step` actions. Treat both as live work so recovery
	 * does not fail a parent while fan-out is still queued or children are active.
	 *
	 * @param int                  $parent_job_id Parent job ID.
	 * @param array<string, mixed> $engine_data Parent engine data.
	 * @param int                  $timeout_hours Hours before in-progress actions are considered stale.
	 * @return bool True when batch chunk or child work is still active.
	 */
	private function hasActiveBatchWork( int $parent_job_id, array $engine_data, int $timeout_hours ): bool {
		if ( $parent_job_id <= 0 || empty( $engine_data['batch'] ) ) {
			return false;
		}

		if ( $this->hasActiveActionForArg( 'datamachine_pipeline_batch_chunk', 'parent_job_id', $parent_job_id, $timeout_hours ) ) {
			return true;
		}

		global $wpdb;
		$jobs_table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WP prefix.
		$active_children = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$jobs_table}
				 WHERE parent_job_id = %d
				 AND status IN ( %s, %s )",
				$parent_job_id,
				'pending',
				'processing'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $active_children > 0;
	}

	/**
	 * Check for a pending or fresh in-progress Action Scheduler action by arg ID.
	 *
	 * @param string $hook Action hook.
	 * @param string $arg_name Numeric argument name to match.
	 * @param int    $arg_id Expected numeric argument value.
	 * @param int    $timeout_hours Hours before in-progress actions are considered stale.
	 * @return bool True when matching action is pending or freshly in-progress.
	 */
	private function hasActiveActionForArg( string $hook, string $arg_name, int $arg_id, int $timeout_hours ): bool {
		global $wpdb;

		if ( $arg_id <= 0 ) {
			return false;
		}

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$like_arg_id   = '%"' . $wpdb->esc_like( $arg_name ) . '":' . $wpdb->esc_like( (string) $arg_id ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WP prefix.
		$actions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, args, status, scheduled_date_gmt, last_attempt_gmt
				 FROM {$actions_table}
				 WHERE hook = %s
				 AND status IN ( %s, %s )
				 AND args LIKE %s",
				$hook,
				'pending',
				'in-progress',
				$like_arg_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$timeout_seconds = max( 1, $timeout_hours ) * HOUR_IN_SECONDS;
		$now_gmt         = strtotime( current_time( 'mysql', true ) );

		foreach ( $actions as $action ) {
			if ( $arg_id !== $this->extractActionArgInt( (string) ( $action->args ?? '' ), $arg_name ) ) {
				continue;
			}

			if ( 'pending' === (string) $action->status ) {
				return true;
			}

			$last_attempt = (string) ( $action->last_attempt_gmt ?? '' );
			$scheduled    = (string) ( $action->scheduled_date_gmt ?? '' );
			$reference    = $last_attempt && '0000-00-00 00:00:00' !== $last_attempt ? $last_attempt : $scheduled;
			$started_at   = $reference ? strtotime( $reference ) : false;

			if ( false === $started_at || false === $now_gmt ) {
				return true;
			}

			if ( ( $now_gmt - $started_at ) < $timeout_seconds ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the Data Machine job ID from Action Scheduler args.
	 *
	 * @param string $args Action Scheduler args payload.
	 * @return int Job ID, or 0 when unavailable.
	 */
	private function extractActionJobId( string $args ): int {
		return $this->extractActionArgInt( $args, 'job_id' );
	}

	/**
	 * Extract a numeric argument from an Action Scheduler args payload.
	 *
	 * @param string $args Action Scheduler args payload.
	 * @param string $arg_name Argument name to extract.
	 * @return int Argument value, or 0 when unavailable.
	 */
	private function extractActionArgInt( string $args, string $arg_name ): int {
		$decoded = json_decode( $args, true );
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded[ $arg_name ] ) && is_numeric( $decoded[ $arg_name ] ) ) {
				return (int) $decoded[ $arg_name ];
			}

			foreach ( $decoded as $value ) {
				if ( is_array( $value ) && isset( $value[ $arg_name ] ) && is_numeric( $value[ $arg_name ] ) ) {
					return (int) $value[ $arg_name ];
				}
			}
		}

		$unserialized = maybe_unserialize( $args );
		if ( is_array( $unserialized ) ) {
			if ( isset( $unserialized[ $arg_name ] ) && is_numeric( $unserialized[ $arg_name ] ) ) {
				return (int) $unserialized[ $arg_name ];
			}

			foreach ( $unserialized as $value ) {
				if ( is_array( $value ) && isset( $value[ $arg_name ] ) && is_numeric( $value[ $arg_name ] ) ) {
					return (int) $value[ $arg_name ];
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
