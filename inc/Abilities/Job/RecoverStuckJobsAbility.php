<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Data Machine owns custom operational tables; recovery evidence uses descriptive keys.
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
use DataMachine\Core\ChildJobRecoveryPolicy;
use DataMachine\Core\EngineData;

defined( 'ABSPATH' ) || exit;

class RecoverStuckJobsAbility {

	use JobHelpers;

	private const CANDIDATE_BATCH_SIZE = 50;
	private const JOB_DETAIL_LIMIT     = 100;
	private const ACTION_HISTORY_LIMIT = 20;
	private const ACTION_SCAN_PAGE_SIZE = 50;
	private const RECOVERY_CLAIM_TTL   = 300;
	private const DEFAULT_APPLY_LIMIT   = 3;
	private const MAX_APPLY_LIMIT       = 100;

	/**
	 * Data Machine-owned Action Scheduler hooks that may be reconciled.
	 *
	 * @var array<string,string>
	 */
	private const RECOVERABLE_ACTION_HOOK_ARGS = array(
		'datamachine_execute_step'         => 'job_id',
		'datamachine_resume_ai_step'       => 'job_id',
		'datamachine_pipeline_batch_chunk' => 'parent_job_id',
		'datamachine_run_flow_now'         => 'job_id',
	);

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
							'job_id'        => array(
								'type'        => array( 'integer', 'null' ),
								'minimum'     => 1,
								'description' => __( 'Recover one exact job ID', 'data-machine' ),
							),
							'limit'         => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => self::MAX_APPLY_LIMIT,
								'default'     => self::DEFAULT_APPLY_LIMIT,
								'description' => __( 'Hard maximum attempted storage touches in one invocation', 'data-machine' ),
							),
							'recover_pathless_children' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Explicitly authorize applying pathless child recovery', 'data-machine' ),
							),
							'timeout_hours' => array(
								'type'        => 'integer',
								'default'     => 2,
								'description' => __( 'Hours before a processing job without status override is considered timed out', 'data-machine' ),
							),
							'recovery_trigger' => array(
								'type'        => 'string',
								'default'     => 'operator',
								'description' => __( 'Recovery initiator used in machine-readable evidence', 'data-machine' ),
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
							'pathless_terminal' => array( 'type' => 'integer' ),
							'pathless_requeued' => array( 'type' => 'integer' ),
							'claimed_elsewhere' => array( 'type' => 'integer' ),
							'pathless_policy_skipped' => array( 'type' => 'integer' ),
							'mutations'     => array( 'type' => 'integer' ),
							'attempted'     => array( 'type' => 'integer' ),
							'touched'       => array( 'type' => 'integer' ),
							'mutated'       => array( 'type' => 'integer' ),
							'apply_limit'   => array( 'type' => 'integer' ),
							'limit_reached' => array( 'type' => 'boolean' ),
							'scope'         => array( 'type' => 'object' ),
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

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
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
		$requested_job_id = array_key_exists( 'job_id', $input ) && null !== $input['job_id'] ? filter_var( $input['job_id'], FILTER_VALIDATE_INT ) : null;
		if ( array_key_exists( 'job_id', $input ) && null !== $input['job_id'] && ( false === $requested_job_id || $requested_job_id <= 0 ) ) {
			return array(
				'success' => false,
				'error'   => 'job_id must be a positive integer.',
			);
		}

		$dry_run       = ! empty( $input['dry_run'] );
		$flow_id       = isset( $input['flow_id'] ) && is_numeric( $input['flow_id'] ) ? (int) $input['flow_id'] : null;
		$job_id_scope  = is_int( $requested_job_id ) ? $requested_job_id : null;
		$timeout_hours = isset( $input['timeout_hours'] ) && is_numeric( $input['timeout_hours'] ) ? max( 1, (int) $input['timeout_hours'] ) : 2;
		$apply_limit   = isset( $input['limit'] ) && is_numeric( $input['limit'] ) ? max( 1, min( self::MAX_APPLY_LIMIT, (int) $input['limit'] ) ) : self::DEFAULT_APPLY_LIMIT;
		$recover_pathless_children = ! empty( $input['recover_pathless_children'] );

		$recovered      = 0;
		$skipped        = 0;
		$jobs           = array();
		$jobs_omitted   = 0;
		$jobs_truncated = false;
		$mutations      = 0;
		$attempted      = 0;
		$touched        = 0;
		$mutated        = 0;
		$limit_reached  = false;

		$last_job_id = 0;
		while ( true ) {
			$where_clause = $wpdb->prepare(
				"WHERE status = 'processing' AND job_id > %d AND engine_data LIKE %s",
				$last_job_id,
				'%"job_status"%'
			);
			if ( $flow_id ) {
				$where_clause .= $wpdb->prepare( ' AND flow_id = %d', $flow_id );
			}
			if ( $job_id_scope ) {
				$where_clause .= $wpdb->prepare( ' AND job_id = %d', $job_id_scope );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic WHERE clause is prepared above; limit is an internal constant.
			$stuck_jobs = $wpdb->get_results(
				"SELECT job_id, flow_id
					 FROM {$table}
					 {$where_clause}
					 ORDER BY job_id ASC
					 LIMIT " . self::CANDIDATE_BATCH_SIZE
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

			if ( empty( $stuck_jobs ) ) {
				break;
			}

			foreach ( $stuck_jobs as $job ) {
				if ( ! $dry_run && $touched >= $apply_limit ) {
					$limit_reached = true;
					break 2;
				}
				$last_job_id = max( $last_job_id, (int) $job->job_id );
				$engine_data = $this->getJobEngineData( (int) $job->job_id );
				$status      = $engine_data['job_status'] ?? null;

				// Truncate to fit varchar(255) column. Full reason is in engine_data.
				if ( $status && strlen( $status ) > 255 ) {
					$status = substr( $status, 0, 252 ) . '...';
				}

				if ( ! $status || ! JobStatus::isStatusFinal( $status ) ) {
					++$skipped;
					$this->appendJobDetail( $jobs, $jobs_omitted, array(
						'job_id'  => (int) $job->job_id,
						'flow_id' => (int) $job->flow_id,
						'status'  => 'skipped',
						'reason'  => sprintf( 'Invalid or non-final status: %s', $status ?? 'null' ),
					) );
					continue;
				}

				if ( $dry_run ) {
					++$recovered;
					$this->appendJobDetail( $jobs, $jobs_omitted, array(
						'job_id'        => (int) $job->job_id,
						'flow_id'       => (int) $job->flow_id,
						'status'        => 'would_recover',
						'target_status' => $status,
					) );
				} else {
					if ( ! $this->consumeTouchBudget( $attempted, $touched, $apply_limit ) ) {
						$limit_reached = true;
						break 2;
					}
					$result = $this->db_jobs->transition_job_status( (int) $job->job_id, $status, true );

					if ( $result ) {
						++$recovered;
						++$mutations;
						++$mutated;
						$this->appendJobDetail( $jobs, $jobs_omitted, array(
							'job_id'        => (int) $job->job_id,
							'flow_id'       => (int) $job->flow_id,
							'status'        => 'recovered',
							'target_status' => $status,
						) );
					} else {
						++$skipped;
						$this->appendJobDetail( $jobs, $jobs_omitted, array(
							'job_id'  => (int) $job->job_id,
							'flow_id' => (int) $job->flow_id,
							'status'  => 'skipped',
							'reason'  => 'Database update failed',
						) );
					}
				}
			}
		}

		// Second recovery pass: timed-out jobs (processing without job_status override, older than timeout).
		$timed_out     = 0;
		$stale_actions = 0;
		$requeued      = 0;
		$pathless_terminal = 0;
		$pathless_requeued = 0;
		$claimed_elsewhere = 0;
		$pathless_policy_skipped = 0;
		$recovery_trigger = isset( $input['recovery_trigger'] ) ? sanitize_key( (string) $input['recovery_trigger'] ) : 'operator';

		$last_job_id = 0;
		while ( true ) {
			$timeout_where = $wpdb->prepare(
				"WHERE status = 'processing' AND job_id > %d AND engine_data NOT LIKE %s AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
				$last_job_id,
				'%"job_status"%',
				$timeout_hours
			);
			if ( $flow_id ) {
				$timeout_where .= $wpdb->prepare( ' AND flow_id = %d', $flow_id );
			}
			if ( $job_id_scope ) {
				$timeout_where .= $wpdb->prepare( ' AND job_id = %d', $job_id_scope );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic WHERE clause is prepared above; limit is an internal constant.
			$timed_out_jobs = $wpdb->get_results(
				"SELECT job_id, flow_id
					 FROM {$table}
					 {$timeout_where}
					 ORDER BY job_id ASC
					 LIMIT " . self::CANDIDATE_BATCH_SIZE
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

			if ( empty( $timed_out_jobs ) ) {
				break;
			}

			foreach ( $timed_out_jobs as $job ) {
				if ( ! $dry_run && $touched >= $apply_limit ) {
					$limit_reached = true;
					break 2;
				}
				$last_job_id = max( $last_job_id, (int) $job->job_id );
				$engine_data = $this->getJobEngineData( (int) $job->job_id );

				$job_id      = (int) $job->job_id;
				$job_flow_id = (int) $job->flow_id;

				$job_row = $this->db_jobs->get_job( $job_id );
				$child_diagnosis = null;
				if ( is_array( $job_row ) && (int) ( $job_row['parent_job_id'] ?? 0 ) > 0 ) {
					$child_diagnosis = ChildJobRecoveryPolicy::diagnose(
						$job_row,
						$engine_data,
						$this->getStepActionHistory( $job_id ),
						$timeout_hours * HOUR_IN_SECONDS,
						time()
					);
				}

				if ( is_array( $child_diagnosis ) && ! empty( $child_diagnosis['has_active_path'] ) ) {
					++$skipped;
					$this->appendJobDetail( $jobs, $jobs_omitted, $this->childRecoveryEvidence( $job_row, $child_diagnosis, 'skipped', $recovery_trigger ) );
					continue;
				}

				if ( is_array( $child_diagnosis ) ) {
					$planned_status = ! empty( $child_diagnosis['retry_eligible'] ) ? 'would_requeue_pathless_child' : 'would_transition_pathless_child';
					if ( $dry_run ) {
						if ( ! empty( $child_diagnosis['retry_eligible'] ) ) {
							++$requeued;
							++$pathless_requeued;
						} else {
							++$pathless_terminal;
						}
						$this->appendJobDetail( $jobs, $jobs_omitted, $this->childRecoveryEvidence( $job_row, $child_diagnosis, $planned_status, $recovery_trigger ) );
						continue;
					}
					if ( ! $recover_pathless_children ) {
						++$skipped;
						++$pathless_policy_skipped;
						$this->appendJobDetail( $jobs, $jobs_omitted, $this->childRecoveryEvidence( $job_row, $child_diagnosis, 'skipped', $recovery_trigger, 'pathless_child_apply_policy_required' ) );
						continue;
					}
					// Diagnosis can change after claiming, so reserve claim + rollback/requeue + finish/terminal.
					$required_touches = 3;
					if ( ! $this->hasTouchCapacity( $touched, $apply_limit, $required_touches ) || ! $this->consumeTouchBudget( $attempted, $touched, $apply_limit ) ) {
						$limit_reached = true;
						break 2;
					}

					$claim = $this->claimPathlessChildRecovery( $job_id, $recovery_trigger );
					if ( empty( $claim['owned'] ) ) {
						++$skipped;
						++$claimed_elsewhere;
						$this->appendJobDetail( $jobs, $jobs_omitted, $this->childRecoveryEvidence( $job_row, $child_diagnosis, 'skipped', $recovery_trigger, 'recovery_claim_not_owned' ) );
						continue;
					}
					++$mutated;

					$engine_data  = $this->getJobEngineData( $job_id );
					$claimed_job  = $this->db_jobs->get_job( $job_id );
					$claimed_job  = is_array( $claimed_job ) ? $claimed_job : array();
					$child_diagnosis = ChildJobRecoveryPolicy::diagnose( $claimed_job, $engine_data, $this->getStepActionHistory( $job_id ), $timeout_hours * HOUR_IN_SECONDS, time() );
					if ( ! empty( $child_diagnosis['has_active_path'] ) ) {
						++$skipped;
						$this->consumeTouchBudget( $attempted, $touched, $apply_limit );
						if ( $this->finishPathlessChildRecovery( $job_id, (string) $claim['token'], (int) $claim['generation'], 'scheduler_path_observed', array() ) ) {
							++$mutated;
						}
						$this->appendJobDetail( $jobs, $jobs_omitted, $this->childRecoveryEvidence( $job_row, $child_diagnosis, 'skipped', $recovery_trigger, 'scheduler_path_appeared_after_claim' ) );
						continue;
					}

					if ( ! empty( $child_diagnosis['retry_eligible'] ) ) {
						$this->consumeTouchBudget( $attempted, $touched, $apply_limit );
						$retry_args = array_merge(
							$child_diagnosis['retry_args'],
							array(
								'recovery_generation'  => (int) $claim['generation'],
								'recovery_claim_token' => (string) $claim['token'],
							)
						);
						$requeue = $this->db_jobs->commit_recovery_owned_requeue(
							$job_id,
							(string) $claim['token'],
							(int) $claim['generation'],
							static fn(): int => (int) as_schedule_single_action( time(), 'datamachine_execute_step', $retry_args, 'data-machine', true )
						);
						$action_id = (int) ( $requeue['action_id'] ?? 0 );
						if ( ! empty( $requeue['success'] ) && $action_id > 0 ) {
							++$requeued;
							++$pathless_requeued;
							++$mutations;
							++$mutated;
							$this->appendJobDetail( $jobs, $jobs_omitted, $this->childRecoveryEvidence( $job_row, $child_diagnosis, 'requeued_pathless_child', $recovery_trigger ) + array( 'recovery_action_id' => (int) $action_id ) );
							continue;
						}
						$race_job       = $this->db_jobs->get_job( $job_id );
						$race_job       = is_array( $race_job ) ? $race_job : array();
						$race_diagnosis = ChildJobRecoveryPolicy::diagnose( $race_job, $this->getJobEngineData( $job_id ), $this->getStepActionHistory( $job_id ), $timeout_hours * HOUR_IN_SECONDS, time() );
						if ( ! empty( $race_diagnosis['has_active_path'] ) ) {
							++$skipped;
							$this->consumeTouchBudget( $attempted, $touched, $apply_limit );
							if ( $this->finishPathlessChildRecovery( $job_id, (string) $claim['token'], (int) $claim['generation'], 'scheduler_path_observed', array() ) ) {
								++$mutated;
							}
							$this->appendJobDetail( $jobs, $jobs_omitted, $this->childRecoveryEvidence( $job_row, $race_diagnosis, 'skipped', $recovery_trigger, 'concurrent_scheduler_path_won' ) );
							continue;
						}
					}

					$this->consumeTouchBudget( $attempted, $touched, $apply_limit );
					$result = $this->db_jobs->transition_recovery_owned_child( $job_id, JobStatus::failed( 'scheduler_path_lost' )->toString(), (string) $claim['token'], (int) $claim['generation'] );
					if ( ! empty( $result['success'] ) ) {
						++$pathless_terminal;
						++$mutations;
						++$mutated;
						$this->appendJobDetail( $jobs, $jobs_omitted, $this->childRecoveryEvidence( $job_row, $child_diagnosis, 'transitioned_pathless_child', $recovery_trigger ) );
					} else {
						++$skipped;
					}
					continue;
				}

				if ( $this->hasActiveSchedulerWork( $job_id, $engine_data, $timeout_hours ) ) {
					++$skipped;
					$this->appendJobDetail(
						$jobs,
						$jobs_omitted,
						array(
							'job_id'  => $job_id,
							'flow_id' => $job_flow_id,
							'status'  => 'skipped',
							'reason'  => 'Pending or in-progress scheduler work exists',
						)
					);
					continue;
				}

				if ( $dry_run ) {
					++$timed_out;
					$this->appendJobDetail( $jobs, $jobs_omitted, array(
						'job_id'  => $job_id,
						'flow_id' => $job_flow_id,
						'status'  => 'would_timeout',
					) );
				} else {
					$backup = $engine_data['queued_prompt_backup'] ?? array();
					$required_touches = empty( $backup ) ? 1 : 2;
					if ( ! $this->hasTouchCapacity( $touched, $apply_limit, $required_touches ) || ! $this->consumeTouchBudget( $attempted, $touched, $apply_limit ) ) {
						$limit_reached = true;
						break 2;
					}
					$result = $this->db_jobs->transition_job_status( $job_id, JobStatus::FAILED, true );

					if ( $result ) {
						++$timed_out;
						++$mutations;
						++$mutated;
						$this->appendJobDetail( $jobs, $jobs_omitted, array(
							'job_id'  => $job_id,
							'flow_id' => $job_flow_id,
							'status'  => 'timed_out',
						) );
						// Restore drain-mode queued_prompt_backup if the prior run removed an entry.
						if ( ! empty( $backup ) ) {
							$this->consumeTouchBudget( $attempted, $touched, $apply_limit );
							if ( $this->restoreQueuedPromptBackup( $job_flow_id, $backup ) ) {
								++$requeued;
								++$mutations;
								++$mutated;
							}
						}
					} else {
						$this->appendJobDetail( $jobs, $jobs_omitted, array(
							'job_id'  => $job_id,
							'flow_id' => $job_flow_id,
							'status'  => 'skipped',
							'reason'  => 'Database update failed for timeout',
						) );
					}
				}
			}
		}

		$terminal_actions = $this->getTerminalBackedInProgressActions( $flow_id, $job_id_scope );
		foreach ( $terminal_actions as $action ) {
			if ( ! $dry_run && $touched >= $apply_limit ) {
				$limit_reached = true;
				break;
			}
			if ( ! $dry_run && ! $this->consumeTouchBudget( $attempted, $touched, $apply_limit ) ) {
				$limit_reached = true;
				break;
			}
			$job_id         = (int) $action['job_id'];
			$action_id      = (int) $action['action_id'];
			$job_flow_id    = (int) $action['flow_id'];
			$terminal_state = (string) $action['job_status'];
			$action_hook    = (string) $action['hook'];

			if ( $dry_run ) {
				++$stale_actions;
				$this->appendJobDetail( $jobs, $jobs_omitted, array(
					'job_id'        => $job_id,
					'flow_id'       => $job_flow_id,
					'action_id'     => $action_id,
					'hook'          => $action_hook,
					'status'        => 'would_reconcile_action',
					'target_status' => $terminal_state,
				) );
				continue;
			}

			if ( $this->completeTerminalBackedAction( $action_id, $action_hook, $job_id, $terminal_state ) ) {
				++$stale_actions;
				++$mutations;
				++$mutated;
				$this->appendJobDetail( $jobs, $jobs_omitted, array(
					'job_id'        => $job_id,
					'flow_id'       => $job_flow_id,
					'action_id'     => $action_id,
					'hook'          => $action_hook,
					'status'        => 'reconciled_action',
					'target_status' => $terminal_state,
				) );
			} else {
				++$skipped;
				$this->appendJobDetail( $jobs, $jobs_omitted, array(
					'job_id'    => $job_id,
					'flow_id'   => $job_flow_id,
					'action_id' => $action_id,
					'hook'      => $action_hook,
					'status'    => 'skipped',
					'reason'    => 'Action Scheduler reconciliation failed',
				) );
			}
		}

		$jobs_truncated = $jobs_omitted > 0;

		$message = $dry_run
			? sprintf( 'Dry run complete. Would recover %d jobs, timeout %d jobs, requeue %d pathless children, terminalize %d pathless children, and reconcile %d terminal-backed actions.', $recovered, $timed_out, $pathless_requeued, $pathless_terminal, $stale_actions )
			: sprintf( 'Recovery complete. Attempted/touched/mutated: %d/%d/%d (limit %d), outcomes: %d, recovered: %d, timed out: %d, pathless requeued: %d, pathless terminal: %d, reconciled actions: %d, policy-skipped: %d', $attempted, $touched, $mutated, $apply_limit, $mutations, $recovered, $timed_out, $pathless_requeued, $pathless_terminal, $stale_actions, $pathless_policy_skipped );

		if ( ! $dry_run && ( $mutations > 0 || $claimed_elsewhere > 0 || $pathless_policy_skipped > 0 ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Stuck jobs recovered via ability',
				array(
					'recovered'     => $recovered,
					'timed_out'     => $timed_out,
					'stale_actions' => $stale_actions,
					'requeued'      => $requeued,
					'pathless_requeued' => $pathless_requeued,
					'pathless_terminal' => $pathless_terminal,
					'claimed_elsewhere' => $claimed_elsewhere,
					'pathless_policy_skipped' => $pathless_policy_skipped,
					'mutations'     => $mutations,
					'attempted'     => $attempted,
					'touched'       => $touched,
					'mutated'       => $mutated,
					'apply_limit'   => $apply_limit,
					'limit_reached' => $limit_reached,
					'job_id'        => $job_id_scope,
					'flow_id'       => $flow_id,
				)
			);
		}

		return array(
			'success'        => true,
			'recovered'      => $recovered,
			'skipped'        => $skipped,
			'timed_out'      => $timed_out,
			'stale_actions'  => $stale_actions,
			'requeued'       => $requeued,
			'pathless_terminal' => $pathless_terminal,
			'pathless_requeued' => $pathless_requeued,
			'claimed_elsewhere' => $claimed_elsewhere,
			'pathless_policy_skipped' => $pathless_policy_skipped,
			'mutations'      => $mutations,
			'attempted'      => $attempted,
			'touched'        => $touched,
			'mutated'        => $mutated,
			'apply_limit'    => $apply_limit,
			'limit_reached'  => $limit_reached,
			'scope'          => array(
				'job_id'                    => $job_id_scope,
				'flow_id'                   => $flow_id,
				'statuses'                  => array( 'processing' ),
				'includes_pending_ai'       => false,
				'recover_pathless_children' => $recover_pathless_children,
			),
			'dry_run'        => $dry_run,
			'jobs'           => $jobs,
			'jobs_omitted'   => $jobs_omitted,
			'jobs_truncated' => $jobs_truncated,
			'message'        => $message,
		);
	}

	/**
	 * Count stale processing work without loading job payloads.
	 *
	 * @param int|null $flow_id Optional flow filter.
	 * @param int      $timeout_hours Timeout window.
	 * @return int Stale processing candidate count.
	 */
	public static function countStuckCandidates( ?int $flow_id = null, int $timeout_hours = 2 ): int {
		global $wpdb;
		$table         = $wpdb->prefix . 'datamachine_jobs';
		$timeout_hours = max( 1, $timeout_hours );

		$where = $wpdb->prepare(
			"WHERE status = 'processing' AND ( engine_data LIKE %s OR ( engine_data NOT LIKE %s AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR) ) )",
			'%"job_status"%',
			'%"job_status"%',
			$timeout_hours
		);
		if ( $flow_id ) {
			$where .= $wpdb->prepare( ' AND flow_id = %d', $flow_id );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic WHERE clause is prepared above.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<int,array<string,mixed>> */
	private function getStepActionHistory( int $job_id, int $required_action_id = 0 ): array {
		global $wpdb;
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$job_arg_prefix = '%"job_id":' . $wpdb->esc_like( (string) $job_id );
		$like_job_comma = $job_arg_prefix . ',%';
		$like_job_end   = $job_arg_prefix . '}%';
		$history      = array();
		$history_count = 0;
		$before_id    = PHP_INT_MAX;

		while ( $history_count < self::ACTION_HISTORY_LIMIT ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WP prefix.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action_id, hook, status, claim_id, scheduled_date_gmt, last_attempt_gmt, attempts, args
					 FROM {$actions_table}
					 WHERE hook IN (%s, %s)
					 AND (args LIKE %s OR args LIKE %s)
					 AND action_id < %d
					 ORDER BY action_id DESC
					 LIMIT %d",
					'datamachine_execute_step',
					'datamachine_resume_ai_step',
					$like_job_comma,
					$like_job_end,
					$before_id,
					self::ACTION_SCAN_PAGE_SIZE
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $rows ) ) {
				break;
			}

			$last_row  = end( $rows );
			$before_id = is_array( $last_row ) ? (int) ( $last_row['action_id'] ?? 0 ) : 0;
			foreach ( $rows as $row ) {
				$args = json_decode( (string) ( $row['args'] ?? '' ), true );
				if ( ! is_array( $args ) || ! ChildJobRecoveryPolicy::actionBelongsToJob( $args, $job_id ) ) {
					continue;
				}
				$row['decoded_args'] = $args;
				$history[]           = $row;
				++$history_count;
				if ( self::ACTION_HISTORY_LIMIT <= $history_count ) {
					break;
				}
			}
		}

		if ( $required_action_id > 0 && ! in_array( $required_action_id, array_map( 'intval', array_column( $history, 'action_id' ) ), true ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WP prefix.
			$required = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT action_id, hook, status, claim_id, scheduled_date_gmt, last_attempt_gmt, attempts, args FROM {$actions_table} WHERE action_id = %d AND hook IN (%s, %s)",
					$required_action_id,
					'datamachine_execute_step',
					'datamachine_resume_ai_step'
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$args = is_array( $required ) ? json_decode( (string) ( $required['args'] ?? '' ), true ) : null;
			if ( is_array( $required ) && is_array( $args ) && ChildJobRecoveryPolicy::actionBelongsToJob( $args, $job_id ) ) {
				$required['decoded_args'] = $args;
				$history[]                = $required;
			}
		}
		return $history;
	}

	/** @return array{owned:bool,token:string,generation:int,expires_at:string} */
	private function claimPathlessChildRecovery( int $job_id, string $trigger ): array {
		$job = $this->db_jobs->get_job( $job_id );
		if ( ! is_array( $job ) || JobStatus::PROCESSING !== ( $job['status'] ?? '' ) || (int) ( $job['parent_job_id'] ?? 0 ) <= 0 ) {
			return array(
				'owned' => false,
				'token' => '',
				'generation' => 0,
				'expires_at' => '',
			);
		}
		$engine_data       = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$owner             = is_array( $engine_data['scheduler_recovery'] ?? null ) ? $engine_data['scheduler_recovery'] : array();
		$receipt           = is_array( $owner['receipt'] ?? null ) ? $owner['receipt'] : array();
		$actions           = $this->getStepActionHistory( $job_id, (int) ( $receipt['action_id'] ?? 0 ) );
		if ( ! ChildJobRecoveryPolicy::canClaimNextGeneration( $job, $engine_data, $actions, time() ) ) {
			return array(
				'owned'      => false,
				'token'      => '',
				'generation' => 0,
				'expires_at' => '',
			);
		}
		$observed_owner = is_array( $engine_data['scheduler_recovery'] ?? null ) ? $engine_data['scheduler_recovery'] : array();

		$token = bin2hex( random_bytes( 16 ) );
		$now   = time();
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $token, $trigger, $now, $job, $actions, $observed_owner ): ?array {
				$current = is_array( $engine['scheduler_recovery'] ?? null ) ? $engine['scheduler_recovery'] : array();
				if ( empty( $observed_owner ) !== empty( $current ) ) {
					return null;
				}
				if ( ! empty( $observed_owner ) && ( (int) ( $current['generation'] ?? 0 ) !== (int) ( $observed_owner['generation'] ?? 0 ) || ! hash_equals( (string) ( $observed_owner['token'] ?? '' ), (string) ( $current['token'] ?? '' ) ) ) ) {
					return null;
				}
				$current_job                = $job;
				$current_job['engine_data'] = $engine;
				if ( ! ChildJobRecoveryPolicy::canClaimNextGeneration( $current_job, $engine, $actions, $now ) ) {
					return null;
				}
				$engine['scheduler_recovery'] = array(
					'schema'     => 'datamachine.scheduler-recovery.v1',
					'state'      => 'claimed',
					'token'      => $token,
					'generation' => max( 0, (int) ( $current['generation'] ?? 0 ) ) + 1,
					'trigger'    => $trigger,
					'claimed_at' => gmdate( 'c', $now ),
					'expires_at' => gmdate( 'c', $now + self::RECOVERY_CLAIM_TTL ),
				);
				return $engine;
			},
			'scheduler_recovery_claim'
		);

		$state = is_array( $result['snapshot']['scheduler_recovery'] ?? null ) ? $result['snapshot']['scheduler_recovery'] : array();
		return array(
			'owned' => ! empty( $result['success'] ) && hash_equals( $token, (string) ( $state['token'] ?? '' ) ),
			'token' => $token,
			'generation' => (int) ( $state['generation'] ?? 0 ),
			'expires_at' => (string) ( $state['expires_at'] ?? '' ),
		);
	}

	/** @param array<string,mixed> $extra */
	private function finishPathlessChildRecovery( int $job_id, string $token, int $generation, string $state, array $extra ): bool {
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $token, $generation, $state, $extra ): ?array {
				$current = is_array( $engine['scheduler_recovery'] ?? null ) ? $engine['scheduler_recovery'] : array();
				$expiry  = strtotime( (string) ( $current['expires_at'] ?? '' ) );
				if ( ! hash_equals( $token, (string) ( $current['token'] ?? '' ) ) || (int) ( $current['generation'] ?? 0 ) !== $generation || 'claimed' !== ( $current['state'] ?? '' ) || false === $expiry || $expiry <= time() ) {
					return null;
				}
				$engine['scheduler_recovery'] = array_merge(
					$current,
					$extra,
					array(
						'state'        => $state,
						'completed_at' => gmdate( 'c' ),
					)
				);
				return $engine;
			},
			'scheduler_recovery_finish'
		);
		return ! empty( $result['success'] );
	}

	/** @return array<string,mixed> */
	private function childRecoveryEvidence( array $job, array $diagnosis, string $status, string $trigger, string $reason = '' ): array {
		$current_job = $this->db_jobs->get_job( (int) ( $job['job_id'] ?? 0 ) );
		if ( is_array( $current_job ) ) {
			$job = $current_job;
		}
		$action = is_array( $diagnosis['active_action'] ?? null ) ? $diagnosis['active_action'] : ( is_array( $diagnosis['latest_action'] ?? null ) ? $diagnosis['latest_action'] : array() );
		$args    = is_array( $action['decoded_args'] ?? null ) ? $action['decoded_args'] : array();
		$action_generation = (int) ( $args['recovery_generation'] ?? $args['ai_resume_generation'] ?? $args['operation_generation'] ?? 0 );
		$action_generation_valid = 0 < $action_generation && ( ! empty( $diagnosis['has_active_path'] ) || ! empty( $diagnosis['retry_eligible'] ) );
		$engine     = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$ownership  = ChildJobRecoveryPolicy::recoveryOwnershipEvidence( $engine, time() );
		$claim      = $this->activeClaimEvidence( $engine, (int) ( $job['job_id'] ?? 0 ) );
		$created    = strtotime( (string) ( $job['created_at'] ?? '' ) . ' UTC' );
		$claimed_at = strtotime( (string) ( $ownership['claimed_at'] ?? '' ) );
		$lease_at   = strtotime( (string) ( $ownership['renewed_at'] ?? $ownership['claimed_at'] ?? '' ) );
		$token      = (string) ( $ownership['token'] ?? '' );
		return array(
			'job_id'             => (int) ( $job['job_id'] ?? 0 ),
			'flow_id'            => (int) ( $job['flow_id'] ?? 0 ),
			'parent_job_id'      => (int) ( $job['parent_job_id'] ?? 0 ),
			'status'             => $status,
			'disposition'        => $status,
			'reason'             => '' !== $reason ? $reason : (string) ( $diagnosis['reason'] ?? '' ),
			'owner'              => '' === $token ? 'none' : substr( hash( 'sha256', $token ), 0, 16 ),
			'job_age_seconds'    => false === $created ? 0 : max( 0, time() - $created ),
			'scheduler_path'     => ! empty( $diagnosis['has_active_path'] ) ? 'active' : 'none',
			'action_id'          => (int) ( $action['action_id'] ?? 0 ),
			'action_hook'        => (string) ( $action['hook'] ?? '' ),
			'action_status'      => (string) ( $action['status'] ?? '' ),
			'action_claim_id'    => (int) ( $action['claim_id'] ?? 0 ),
			'action_generation'  => $action_generation_valid ? $action_generation : 0,
			'action_generation_state' => $action_generation_valid ? 'valid' : ( 0 === $action_generation ? 'legacy_unfenced' : 'invalid' ),
			'operation_state'    => (string) ( $job['operation_state'] ?? '' ),
			'operation_generation' => (int) ( $job['operation_generation'] ?? 0 ),
			'recovery_lease_state' => (string) ( $ownership['state'] ?? 'none' ),
			'recovery_lease_generation' => (int) ( $ownership['generation'] ?? 0 ),
			'recovery_lease_token_state' => '' === $token ? 'invalid_or_missing' : 'valid',
			'recovery_lease_age_seconds' => false === $lease_at ? 0 : max( 0, time() - $lease_at ),
			'recovery_lease_claim_age_seconds' => false === $claimed_at ? 0 : max( 0, time() - $claimed_at ),
			'recovery_lease_expires_at' => (string) ( $ownership['expires_at'] ?? '' ),
			'receipt_state'      => (string) ( $ownership['receipt_state'] ?? 'missing' ),
			'receipt_action_id'  => (int) ( $ownership['receipt_action_id'] ?? 0 ),
			'item_claim_state'   => (string) $claim['state'],
			'item_claim_job_id'  => $claim['active'] > 0 ? (int) ( $job['job_id'] ?? 0 ) : 0,
			'item_claim_declared_count' => $claim['declared'],
			'item_claim_active_count' => $claim['active'],
			'terminal_accounting_state' => isset( $job['terminal_accounting_state'] ) ? (int) $job['terminal_accounting_state'] : null,
			'terminal_accounting_owner_state' => empty( $job['terminal_accounting_owner'] ) ? 'none' : 'present',
			'retry_eligible'     => ! empty( $diagnosis['retry_eligible'] ),
			'recovery_trigger'   => $trigger,
		);
	}

	/** Validate persisted claim descriptors against current repository ownership. */
	private function activeClaimEvidence( array $engine, int $job_id ): array {
		$candidates = array();
		if ( is_array( $engine['_datamachine_item_claim'] ?? null ) ) {
			$candidates[] = $engine['_datamachine_item_claim'];
		}
		if ( is_array( $engine['_datamachine_item_claims'] ?? null ) ) {
			$candidates = array_merge( $candidates, $engine['_datamachine_item_claims'] );
		}

		$declared = 0;
		$active   = 0;
		$seen     = array();
		foreach ( $candidates as $claim ) {
			if ( ! is_array( $claim ) ) {
				continue;
			}
			$token = (string) ( $claim['ownership_token'] ?? '' );
			if ( '' === $token || isset( $seen[ $token ] ) ) {
				continue;
			}
			$seen[ $token ] = true;
			++$declared;
			if ( $this->db_processed_items->owns_active_claim( $claim, $job_id ) ) {
				++$active;
			}
		}

		return array(
			'state'    => 0 < $active ? 'active_owned' : ( 0 < $declared ? 'stale_or_unowned' : 'none' ),
			'declared' => $declared,
			'active'   => $active,
		);
	}

	/**
	 * Fetch and decode one job's engine data.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string,mixed> Decoded engine data.
	 */
	private function getJobEngineData( int $job_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WP prefix.
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT engine_data FROM {$table} WHERE job_id = %d",
				$job_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : array();
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Append a bounded job detail row to keep dry-run output memory-safe.
	 *
	 * @param array<int,array<string,mixed>> $jobs Job details.
	 * @param int                           $jobs_omitted Omitted detail count.
	 * @param array<string,mixed>           $detail Detail row.
	 */
	private function appendJobDetail( array &$jobs, int &$jobs_omitted, array $detail ): void {
		if ( count( $jobs ) < self::JOB_DETAIL_LIMIT ) {
			$jobs[] = $detail;
			return;
		}

		++$jobs_omitted;
	}

	/** Reserve one attempted storage touch before invoking it. */
	private function consumeTouchBudget( int &$attempted, int &$touched, int $limit ): bool {
		if ( $touched >= $limit ) {
			return false;
		}

		++$attempted;
		++$touched;
		return true;
	}

	/** Ensure a compound recovery path can finish without touching N+1. */
	private function hasTouchCapacity( int $touched, int $limit, int $required ): bool {
		return $required > 0 && ( $touched + $required ) <= $limit;
	}

	/**
	 * Find orphaned in-progress Data Machine Action Scheduler actions whose job is terminal.
	 *
	 * @param int|null $flow_id Optional flow filter.
	 * @return array<int,array{action_id:int,hook:string,job_id:int,flow_id:int,job_status:string}>
	 */
	private function getTerminalBackedInProgressActions( ?int $flow_id, ?int $job_id_scope = null ): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$claims_table  = $wpdb->prefix . 'actionscheduler_claims';
		$jobs_table    = $wpdb->prefix . 'datamachine_jobs';

		$action_jobs = array();
		foreach ( self::RECOVERABLE_ACTION_HOOK_ARGS as $hook => $arg_name ) {
			$arg_like = '%"' . $wpdb->esc_like( $arg_name ) . '"%';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are generated from the WP prefix.
			$actions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.action_id, a.hook, a.args
					 FROM {$actions_table} a
					 LEFT JOIN {$claims_table} c ON c.claim_id = a.claim_id
					 WHERE a.hook = %s
					 AND a.status = %s
					 AND a.group_id IN (SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s)
					 AND (a.claim_id = 0 OR c.claim_id IS NULL)
					 AND a.args LIKE %s",
					$hook,
					'in-progress',
					'data-machine',
					$arg_like
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( $actions as $action ) {
				$job_id = $this->extractActionJobIdForHook( (string) ( $action->args ?? '' ), (string) ( $action->hook ?? $hook ) );
				if ( $job_id > 0 ) {
					$action_jobs[ (int) $action->action_id ] = array(
						'hook'   => (string) ( $action->hook ?? $hook ),
						'job_id' => $job_id,
					);
				}
			}
		}

		if ( empty( $action_jobs ) ) {
			return array();
		}

		$job_ids          = array_values( array_unique( array_column( $action_jobs, 'job_id' ) ) );
		$job_placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		$query_args       = $job_ids;

		$sql = "SELECT job_id, flow_id, status
			 FROM {$jobs_table}
			 WHERE job_id IN ({$job_placeholders})";
		if ( $flow_id ) {
			$sql         .= ' AND flow_id = %d';
			$query_args[] = $flow_id;
		}
		if ( $job_id_scope ) {
			$sql         .= ' AND job_id = %d';
			$query_args[] = $job_id_scope;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholder list is prepared below.
		$terminal_jobs = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The dynamic SQL contains only generated placeholders; values are supplied in matching order.
				$sql,
				$query_args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $terminal_jobs ) ) {
			return array();
		}

		$jobs_by_id = array();
		foreach ( $terminal_jobs as $job ) {
			if ( ! JobStatus::isStatusFinal( (string) ( $job['status'] ?? '' ) ) ) {
				continue;
			}

			$jobs_by_id[ (int) $job['job_id'] ] = $job;
		}

		$terminal_actions = array();
		foreach ( $action_jobs as $action_id => $action ) {
			$job_id = (int) $action['job_id'];
			if ( ! isset( $jobs_by_id[ $job_id ] ) ) {
				continue;
			}

			$job                = $jobs_by_id[ $job_id ];
			$terminal_actions[] = array(
				'action_id'  => (int) $action_id,
				'hook'       => (string) $action['hook'],
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
				 WHERE hook IN ( %s, %s )
				 AND status IN ( %s, %s )
				 AND args LIKE %s",
				'datamachine_execute_step',
				'datamachine_resume_ai_step',
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
	 * Extract the paired Data Machine job ID for a recoverable Action Scheduler hook.
	 *
	 * @param string $args Action Scheduler args payload.
	 * @param string $hook Action Scheduler hook.
	 * @return int Job ID, or 0 when the action has no paired job.
	 */
	private function extractActionJobIdForHook( string $args, string $hook ): int {
		$arg_name = self::RECOVERABLE_ACTION_HOOK_ARGS[ $hook ] ?? 'job_id';
		$job_id   = $this->extractActionArgInt( $args, $arg_name );
		if ( $job_id > 0 ) {
			return $job_id;
		}

		if ( 'datamachine_run_flow_now' !== $hook ) {
			return 0;
		}

		return $this->extractActionPositionalInt( $args, 1 );
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
	 * Extract a numeric positional argument from an Action Scheduler args payload.
	 *
	 * @param string $args Action Scheduler args payload.
	 * @param int    $position Zero-based argument position.
	 * @return int Argument value, or 0 when unavailable.
	 */
	private function extractActionPositionalInt( string $args, int $position ): int {
		$decoded = json_decode( $args, true );
		if ( is_array( $decoded ) && isset( $decoded[ $position ] ) && is_numeric( $decoded[ $position ] ) ) {
			return (int) $decoded[ $position ];
		}

		$unserialized = maybe_unserialize( $args );
		if ( is_array( $unserialized ) && isset( $unserialized[ $position ] ) && is_numeric( $unserialized[ $position ] ) ) {
			return (int) $unserialized[ $position ];
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
	private function completeTerminalBackedAction( int $action_id, string $hook, int $job_id, string $job_status ): bool {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$claims_table  = $wpdb->prefix . 'actionscheduler_claims';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		$now_gmt       = current_time( 'mysql', true );
		$now_local     = current_time( 'mysql', false );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are generated from the WP prefix.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$actions_table} a
				 LEFT JOIN {$claims_table} c ON c.claim_id = a.claim_id
				 SET a.status = %s,
					 a.claim_id = 0,
					 a.last_attempt_gmt = %s,
					 a.last_attempt_local = %s
				 WHERE a.action_id = %d
					 AND a.hook = %s
					 AND a.status = %s
					 AND (a.claim_id = 0 OR c.claim_id IS NULL)",
				'complete',
				$now_gmt,
				$now_local,
				$action_id,
				$hook,
				'in-progress'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $result || 0 === (int) $result ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$logs_table,
			array(
				'action_id'      => $action_id,
				'message'        => sprintf(
					'Data Machine reconciled stale in-progress %s action: job %d is already terminal (%s).',
					$hook,
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
