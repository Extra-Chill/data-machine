<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Data Machine owns custom operational tables; reconciliation requires fresh runtime scheduler state.
/**
 * Stale claim reconciliation for crashed job runs.
 *
 * A worker that dies between completing a step and scheduling the next one
 * leaves its job row `processing` with no Action Scheduler action that will
 * ever advance it. This reconciler finds those crashed runs on a recurring
 * engine task and either resumes them from their last completed step or
 * terminalizes them with an explicit reason — so a crash no longer orphans
 * a job until an operator runs `wp datamachine jobs recover-stuck`.
 *
 * Reuses existing primitives end to end: Action Scheduler action args
 * (`operation_generation` / `operation_claim_token`) for claim identity,
 * DirectJobEnqueuer::liveGenerationExecution() for live-claim checks,
 * DirectOperationRecoveryPolicy for direct-job diagnosis and fenced
 * requeue/terminalize, ExecutionPlan + engine_data['step_results'] for
 * resume-point resolution (the same completed-step semantics
 * JobRetryPolicy uses), and engine_data['retry']['attempts'] as the
 * bounded attempt counter.
 *
 * @package DataMachine\Core
 * @since TBD
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\ExecutionPlan;

defined( 'ABSPATH' ) || exit;

class StaleClaimReconciler {

	/**
	 * Default minimum age of a job's last activity before an actionless
	 * processing run is treated as crashed.
	 *
	 * An Action Scheduler action is the only mechanism that advances a
	 * processing job, so an actionless processing row is already beyond the
	 * engine's recovery contract. The threshold exists only to stay clear of
	 * legitimate scheduling latency (queue backlogs, replication lag, the
	 * seconds-long gap between one action completing and the next being
	 * inserted). Fifteen minutes is an order of magnitude beyond any of
	 * those while keeping crash recovery well inside a single tick of the
	 * five-minute reconciliation cadence.
	 */
	public const DEFAULT_ACTIVITY_THRESHOLD_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Default bound on reconciliation resume attempts per job.
	 *
	 * Shares the engine_data['retry']['attempts'] counter with
	 * JobRetryPolicy, so retries and crash-resumes draw from one bounded
	 * budget and a genuinely poisonous step terminalizes instead of
	 * looping forever.
	 */
	public const DEFAULT_MAX_RESUME_ATTEMPTS = 3;

	/** Default candidates examined per pass. */
	public const DEFAULT_BATCH_SIZE = 50;

	/** Recovery trigger recorded in machine-readable evidence. */
	public const RECOVERY_TRIGGER = 'recurring_stale_claim_reconciliation';

	/** Hooks that may carry live scheduler work for a job. */
	private const LIVE_ACTION_HOOKS = array(
		'datamachine_execute_step',
		'datamachine_resume_ai_step',
		'datamachine_pipeline_batch_chunk',
	);

	private const JOB_DETAIL_LIMIT = 100;

	private Jobs $db_jobs;

	/** @var \Closure fn( int $job_id, int $fresh_window_seconds ): array<int,int> */
	private \Closure $active_action_finder;

	public function __construct( ?Jobs $jobs = null, ?callable $active_action_finder = null ) {
		$this->db_jobs = $jobs ?? new Jobs();
		if ( null !== $active_action_finder ) {
			$this->active_action_finder = \Closure::fromCallable( $active_action_finder );
		} else {
			$this->active_action_finder = fn( int $job_id, int $fresh_window_seconds ): array => $this->findActiveActionIds( $job_id, $fresh_window_seconds );
		}
	}

	/** Minimum last-activity age before an actionless processing run is treated as crashed. */
	public static function activityThresholdSeconds(): int {
		$seconds = (int) apply_filters( 'datamachine_stale_claim_activity_threshold', self::DEFAULT_ACTIVITY_THRESHOLD_SECONDS );
		return $seconds > 0 ? $seconds : self::DEFAULT_ACTIVITY_THRESHOLD_SECONDS;
	}

	/** Bound on resume attempts per job before terminalizing. */
	public static function maxResumeAttempts(): int {
		$attempts = (int) apply_filters( 'datamachine_stale_claim_max_resume_attempts', self::DEFAULT_MAX_RESUME_ATTEMPTS );
		return $attempts > 0 ? $attempts : self::DEFAULT_MAX_RESUME_ATTEMPTS;
	}

	/** Candidates examined per pass. */
	public static function batchSize(): int {
		$size = (int) apply_filters( 'datamachine_stale_claim_reconcile_batch_size', self::DEFAULT_BATCH_SIZE );
		return $size > 0 ? $size : self::DEFAULT_BATCH_SIZE;
	}

	/**
	 * Run one bounded reconciliation pass.
	 *
	 * Candidates are `processing` jobs created before the activity threshold
	 * that have no live Action Scheduler action and no activity inside the
	 * threshold window. Each candidate is either resumed from its last
	 * completed step or terminalized with an explicit reason, per the
	 * resume-vs-terminalize policy in reconcileJob().
	 *
	 * @param int|null $job_id Scope the pass to one job, or null for all candidates.
	 * @return array<string,mixed> Pass summary with bounded per-job details.
	 */
	public function reconcile( ?int $job_id = null ): array {
		$summary = array(
			'scanned'         => 0,
			'requeued'        => 0,
			'terminalized'    => 0,
			'skipped'         => 0,
			'details'         => array(),
			'details_omitted' => 0,
		);

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$summary['skipped_scheduler_unavailable'] = 1;
			return $summary;
		}

		$threshold = self::activityThresholdSeconds();
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - $threshold );

		global $wpdb;
		$table = $wpdb->prefix . Jobs::TABLE_NAME;

		$sql    = 'SELECT job_id, flow_id, parent_job_id, source, status, created_at, operation_state, operation_action_id, operation_generation, operation_claim_token, operation_step_id, operation_effects_begun_at FROM %i WHERE status = %s AND created_at < %s';
		$params = array( $table, JobStatus::PROCESSING, $cutoff );
		if ( null !== $job_id && $job_id > 0 ) {
			$sql     .= ' AND job_id = %d';
			$params[] = $job_id;
		}
		$sql     .= ' ORDER BY job_id ASC LIMIT %d';
		$params[] = self::batchSize();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic WHERE clause is prepared at this boundary; limit is an internal constant.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as $row ) {
			$job_id_scoped = (int) ( $row['job_id'] ?? 0 );
			if ( $job_id_scoped <= 0 ) {
				continue;
			}
			++$summary['scanned'];

			$outcome = $this->reconcileJob( $job_id_scoped, $row, $threshold );
			$key     = (string) ( $outcome['outcome'] ?? 'skipped' );
			if ( 'requeued' === $key ) {
				++$summary['requeued'];
			} elseif ( 'terminalized' === $key ) {
				++$summary['terminalized'];
			} else {
				++$summary['skipped'];
			}

			if ( count( $summary['details'] ) < self::JOB_DETAIL_LIMIT ) {
				$summary['details'][] = $outcome;
			} else {
				++$summary['details_omitted'];
			}
		}

		if ( $summary['requeued'] > 0 || $summary['terminalized'] > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Stale claim reconciliation pass recovered crashed runs',
				array(
					'scanned'      => $summary['scanned'],
					'requeued'     => $summary['requeued'],
					'terminalized' => $summary['terminalized'],
					'skipped'      => $summary['skipped'],
					'trigger'      => self::RECOVERY_TRIGGER,
				)
			);
		}

		return $summary;
	}

	/**
	 * Decide and apply the disposition for one stale candidate.
	 *
	 * Resume-vs-terminalize policy:
	 *
	 * | Evidence                                                      | Disposition |
	 * |---------------------------------------------------------------|-------------|
	 * | Live pending action (any hook)                                 | skip        |
	 * | Fresh in-progress action (inside the threshold window)         | skip        |
	 * | Batch parent row                                               | skip (BatchScheduler owns it) |
	 * | engine_data['job_status'] override present                     | skip (operator pass owns it) |
	 * | Activity inside the threshold window                           | skip        |
	 * | Direct job, effects not begun                                  | resume via fenced requeue |
	 * | Direct job, effects begun                                      | terminalize `scheduler_path_lost_after_effects` |
	 * | Flow job, resume step never started, attempts under bound      | resume from it |
	 * | Flow job, resume step already started (incomplete)             | terminalize `worker_died_mid_step` |
	 * | Flow job, attempts at bound                                    | terminalize `stale_claim_resume_exhausted` |
	 * | Flow job, step waiting on a webhook gate                       | skip (webhook owns the resume) |
	 * | Flow job, all steps complete or plan unresolvable              | terminalize `stale_claim_unresumable` |
	 *
	 * @param int   $job_id Job ID.
	 * @param array $row Candidate job row.
	 * @param int   $threshold Activity threshold in seconds.
	 * @return array<string,mixed> Outcome evidence.
	 */
	private function reconcileJob( int $job_id, array $row, int $threshold ): array {
		if ( 'batch' === (string) ( $row['source'] ?? '' ) ) {
			return $this->outcome( $job_id, 'skipped', 'batch_parent', 'BatchScheduler owns batch parent recovery' );
		}

		$engine_data = datamachine_get_engine_data( $job_id );

		if ( ! empty( $engine_data['job_status'] ) ) {
			return $this->outcome( $job_id, 'skipped', 'status_override_pending', 'Terminal job_status override belongs to the operator recovery pass' );
		}

		$last_activity = (string) ( $engine_data['run_metrics']['last_activity_at'] ?? '' );
		if ( '' === $last_activity ) {
			$last_activity = (string) ( $row['created_at'] ?? '' );
		}
		$last_activity_ts = '' !== $last_activity ? strtotime( $last_activity ) : false;
		if ( false !== $last_activity_ts && ( time() - $last_activity_ts ) < $threshold ) {
			return $this->outcome( $job_id, 'skipped', 'recent_activity', 'Last activity is inside the threshold window' );
		}

		$active_action_ids = ( $this->active_action_finder )( $job_id, $threshold );
		if ( ! empty( $active_action_ids ) ) {
			return $this->outcome( $job_id, 'skipped', 'live_action_exists', 'Pending or fresh in-progress scheduler action exists' ) + array(
				'active_action_ids' => $active_action_ids,
			);
		}

		if ( 'direct' === (string) ( $row['flow_id'] ?? '' ) && (int) ( $row['operation_generation'] ?? 0 ) > 0 ) {
			return $this->reconcileDirectJob( $job_id, $row );
		}

		return $this->reconcileFlowJob( $job_id, $engine_data );
	}

	/**
	 * Reconcile a direct job through the existing fenced recovery policy.
	 *
	 * DirectOperationRecoveryPolicy::diagnose() only returns evidence when the
	 * current row owns an absent operation receipt, and
	 * commit_missing_direct_operation_requeue() fences the requeue on the
	 * exact generation + token. If the claim generation advanced between the
	 * read and the commit, the fence loses (`operation_not_owned`) and the
	 * job is skipped — the newer owner advances it. Resumption therefore
	 * only ever happens under a current-generation fenced commit.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $row Candidate job row.
	 * @return array<string,mixed> Outcome evidence.
	 */
	private function reconcileDirectJob( int $job_id, array $row ): array {
		$generation = (int) ( $row['operation_generation'] ?? 0 );
		$token      = (string) ( $row['operation_claim_token'] ?? '' );
		$live       = ( new DirectJobEnqueuer( $this->db_jobs ) )->liveGenerationExecution( $job_id, $generation, $token );
		$diagnosis  = DirectOperationRecoveryPolicy::diagnose(
			$row,
			$live,
			DirectOperationRecoveryPolicy::recordedActionExists( (int) ( $row['operation_action_id'] ?? 0 ) )
		);
		if ( null === $diagnosis ) {
			return $this->outcome( $job_id, 'skipped', 'direct_policy_guarded', 'Direct recovery policy did not admit the diagnosis' );
		}

		$effects_begun = ! empty( $row['operation_effects_begun_at'] );
		$children      = $effects_begun ? DirectOperationRecoveryPolicy::getProcessingSystemTaskChildren( $job_id ) : array();

		if ( ! $effects_begun ) {
			$step_id = (string) ( $row['operation_step_id'] ?? '' );
			$result  = $this->db_jobs->commit_missing_direct_operation_requeue(
				$job_id,
				(int) ( $row['operation_action_id'] ?? 0 ),
				$generation,
				$token,
				self::RECOVERY_TRIGGER,
				static function ( int $new_generation, string $new_token ) use ( $job_id, $step_id ): int {
					return (int) as_schedule_single_action(
						time(),
						DirectJobEnqueuer::HOOK,
						array(
							'job_id'                => $job_id,
							'flow_step_id'          => $step_id,
							'operation_generation'  => $new_generation,
							'operation_claim_token' => $new_token,
						),
						DirectJobEnqueuer::GROUP,
						true
					);
				}
			);
			if ( ! empty( $result['success'] ) ) {
				do_action(
					'datamachine_log',
					'warning',
					'Stale claim reconciliation requeued a direct job with a missing action',
					array(
						'job_id'             => $job_id,
						'recovery_action_id' => (int) $result['action_id'],
						'generation'         => (int) $result['generation'],
						'trigger'            => self::RECOVERY_TRIGGER,
					)
				);
				return $this->outcome( $job_id, 'requeued', 'direct_operation_requeued', 'Requeued from its operation step under a fenced commit' ) + array(
					'recovery_action_id' => (int) $result['action_id'],
					'operation_step_id'  => $step_id,
				);
			}

			return $this->outcome( $job_id, 'skipped', 'direct_requeue_not_owned', 'Fenced requeue lost ownership; a newer claim owner advanced the job' ) + array(
				'fence_reason' => (string) $result['reason'],
			);
		}

		$status = JobStatus::failed( 'scheduler_path_lost_after_effects' )->toString();
		$result = $this->db_jobs->transition_missing_direct_operation(
			$job_id,
			$status,
			(int) ( $row['operation_action_id'] ?? 0 ),
			$generation,
			$token,
			self::RECOVERY_TRIGGER
		);
		if ( empty( $result['success'] ) ) {
			return $this->outcome( $job_id, 'skipped', 'direct_terminalize_not_owned', 'Fenced terminalize lost ownership; a newer claim owner advanced the job' );
		}

		$children_terminalized = 0;
		foreach ( $children as $child_id ) {
			if ( $this->db_jobs->complete_job( $child_id, $status ) ) {
				++$children_terminalized;
			}
		}

		do_action(
			'datamachine_log',
			'warning',
			'Stale claim reconciliation terminalized a direct job whose effects already began',
			array(
				'job_id'   => $job_id,
				'status'   => $status,
				'children' => $children_terminalized,
				'trigger'  => self::RECOVERY_TRIGGER,
			)
		);

		return $this->outcome( $job_id, 'terminalized', 'direct_operation_terminalized', 'Effects already began; terminalized with scheduler_path_lost_after_effects' ) + array(
			'status'                => $status,
			'children_terminalized' => $children_terminalized,
		);
	}

	/**
	 * Resume or terminalize a flow job from its last completed step.
	 *
	 * The resume point is the first step in execution order without a
	 * successful engine_data['step_results'] entry — the same completed-step
	 * semantics JobRetryPolicy applies to resumable workflows. A step with a
	 * recorded but unsuccessful result started at least once, so its effects
	 * cannot be proven absent and resumption is refused; a step with no
	 * record never started, so running it is the safe resume.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $engine_data Job engine data.
	 * @return array<string,mixed> Outcome evidence.
	 */
	private function reconcileFlowJob( int $job_id, array $engine_data ): array {
		$flow_config = is_array( $engine_data['flow_config'] ?? null ) ? $engine_data['flow_config'] : array();
		if ( empty( $flow_config ) ) {
			return $this->terminalizeFlowJob( $job_id, 'stale_claim_unresumable', 'flow_config_missing', '' );
		}

		try {
			$plan = ExecutionPlan::from_flow_config( $flow_config );
		} catch ( \InvalidArgumentException ) {
			return $this->terminalizeFlowJob( $job_id, 'stale_claim_unresumable', 'invalid_execution_plan', '' );
		}

		$step_results   = is_array( $engine_data['step_results'] ?? null ) ? $engine_data['step_results'] : array();
		$resume_step_id = '';
		foreach ( $plan->ordered_step_ids() as $step_id ) {
			$recorded = $step_results[ $step_id ] ?? null;
			if ( ! is_array( $recorded ) ) {
				// The step never started — safe to resume from it.
				$resume_step_id = (string) $step_id;
				break;
			}

			$outcome = (string) ( $recorded['result'] ?? ( $recorded['status'] ?? '' ) );
			if ( 'waiting' === $outcome ) {
				// A webhook gate owns this job's resume path; scheduling the
				// next step here would bypass the gate.
				return $this->outcome( $job_id, 'skipped', 'webhook_gate_waiting', 'A webhook gate owns the resume path' ) + array(
					'waiting_step_id' => (string) $step_id,
				);
			}

			if ( ! $this->stepCompletedSuccessfully( $recorded, $outcome ) ) {
				// The step started but never completed — its effects cannot be
				// proven absent, so resumption is refused.
				return $this->terminalizeFlowJob( $job_id, 'worker_died_mid_step', 'incomplete_step_already_started', (string) $step_id );
			}
		}

		if ( '' === $resume_step_id ) {
			// Every step completed; only the completion handoff was lost.
			// Completion accounting cannot be safely synthesized.
			return $this->terminalizeFlowJob( $job_id, 'stale_claim_unresumable', 'all_steps_completed', '' );
		}

		$attempts = is_array( $engine_data['retry'] ?? null ) ? (int) ( $engine_data['retry']['attempts'] ?? 0 ) : 0;
		if ( $attempts >= self::maxResumeAttempts() ) {
			return $this->terminalizeFlowJob( $job_id, 'stale_claim_resume_exhausted', 'resume_attempt_bound_reached', $resume_step_id );
		}

		return $this->resumeFlowJob( $job_id, $resume_step_id, $attempts );
	}

	/**
	 * Schedule the resume action for a flow job's first incomplete step.
	 *
	 * The unique action prevents concurrent reconciliation passes from
	 * double-scheduling, and the pending transition is verified afterwards so
	 * a job that moved under us is reported instead of silently assumed.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $resume_step_id First incomplete flow step ID.
	 * @param int    $attempts Resume attempts already spent.
	 * @return array<string,mixed> Outcome evidence.
	 */
	private function resumeFlowJob( int $job_id, string $resume_step_id, int $attempts ): array {
		$fresh = $this->db_jobs->get_job( $job_id );
		if ( ! is_array( $fresh ) || JobStatus::PROCESSING !== (string) ( $fresh['status'] ?? '' ) ) {
			return $this->outcome( $job_id, 'skipped', 'status_changed', 'Job is no longer processing' );
		}

		$action_id = (int) as_schedule_single_action(
			time(),
			'datamachine_execute_step',
			array(
				'job_id'       => $job_id,
				'flow_step_id' => $resume_step_id,
			),
			'data-machine',
			true
		);
		if ( $action_id <= 0 ) {
			return $this->outcome( $job_id, 'skipped', 'resume_schedule_failed', 'Action Scheduler rejected the resume action' ) + array(
				'resume_step_id' => $resume_step_id,
			);
		}

		$this->recordResumeAttempt( $job_id, $resume_step_id, $attempts + 1, $action_id );

		if ( ! $this->db_jobs->update_job_status( $job_id, JobStatus::PENDING ) ) {
			return $this->outcome( $job_id, 'skipped', 'status_changed', 'Job moved while the resume action was being recorded' ) + array(
				'resume_step_id'     => $resume_step_id,
				'recovery_action_id' => $action_id,
			);
		}

		do_action(
			'datamachine_log',
			'warning',
			'Stale claim reconciliation resumed a crashed job from its last completed step',
			array(
				'job_id'             => $job_id,
				'resume_step_id'     => $resume_step_id,
				'attempt'            => $attempts + 1,
				'recovery_action_id' => $action_id,
				'trigger'            => self::RECOVERY_TRIGGER,
			)
		);

		return $this->outcome( $job_id, 'requeued', 'resumed_from_last_completed_step', 'Scheduled the first incomplete step and returned the job to pending' ) + array(
			'resume_step_id'     => $resume_step_id,
			'attempt'            => $attempts + 1,
			'recovery_action_id' => $action_id,
		);
	}

	/**
	 * Terminalize a flow job with an explicit failure reason.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $reason Failure reason embedded in the terminal status.
	 * @param string $detail Machine-readable disposition detail.
	 * @param string $flow_step_id Step the decision concerns, when any.
	 * @return array<string,mixed> Outcome evidence.
	 */
	private function terminalizeFlowJob( int $job_id, string $reason, string $detail, string $flow_step_id ): array {
		$fresh = $this->db_jobs->get_job( $job_id );
		if ( ! is_array( $fresh ) || JobStatus::PROCESSING !== (string) ( $fresh['status'] ?? '' ) ) {
			return $this->outcome( $job_id, 'skipped', 'status_changed', 'Job is no longer processing' );
		}

		$status = JobStatus::failed( $reason )->toString();
		if ( ! $this->db_jobs->transition_job_status( $job_id, $status, true ) ) {
			return $this->outcome( $job_id, 'skipped', 'status_changed', 'Job moved while being terminalized' );
		}

		EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $reason, $detail, $flow_step_id ): array {
				$engine['stale_claim_reconciliation'] = array(
					'schema'        => 'datamachine.stale-claim-reconciliation.v1',
					'state'         => 'terminalized',
					'reason'        => $reason,
					'detail'        => $detail,
					'flow_step_id'  => $flow_step_id,
					'trigger'       => self::RECOVERY_TRIGGER,
					'reconciled_at' => gmdate( 'c' ),
				);
				return $engine;
			},
			'stale_claim_terminalize'
		);

		do_action(
			'datamachine_log',
			'warning',
			'Stale claim reconciliation terminalized a crashed job that could not be safely resumed',
			array(
				'job_id'       => $job_id,
				'status'       => $status,
				'reason'       => $reason,
				'detail'       => $detail,
				'flow_step_id' => $flow_step_id,
				'trigger'      => self::RECOVERY_TRIGGER,
			)
		);

		return $this->outcome( $job_id, 'terminalized', $detail, 'Terminalized with ' . $reason ) + array(
			'status'       => $status,
			'flow_step_id' => $flow_step_id,
		);
	}

	/**
	 * Record one resume attempt on the shared retry counter.
	 *
	 * Shares engine_data['retry']['attempts'] with JobRetryPolicy so retries
	 * and crash-resumes draw from one bounded budget.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $flow_step_id Resume step ID.
	 * @param int    $attempt Attempt number being recorded.
	 * @param int    $action_id Resume action ID.
	 * @return void
	 */
	private function recordResumeAttempt( int $job_id, string $flow_step_id, int $attempt, int $action_id ): void {
		EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $flow_step_id, $attempt, $action_id ): array {
				$retry                = is_array( $engine['retry'] ?? null ) ? $engine['retry'] : array();
				$retry['attempts']    = max( (int) ( $retry['attempts'] ?? 0 ), $attempt );
				$retry['last_reason'] = self::RECOVERY_TRIGGER;

				$history          = is_array( $retry['history'] ?? null ) ? $retry['history'] : array();
				$history[]        = array(
					'attempt'      => $attempt,
					'reason'       => self::RECOVERY_TRIGGER,
					'flow_step_id' => $flow_step_id,
					'action_id'    => $action_id,
					'recorded_at'  => gmdate( 'c' ),
				);
				$retry['history'] = $history;

				$engine['retry']                      = $retry;
				$engine['stale_claim_reconciliation'] = array(
					'schema'       => 'datamachine.stale-claim-reconciliation.v1',
					'state'        => 'resumed',
					'flow_step_id' => $flow_step_id,
					'attempt'      => $attempt,
					'action_id'    => $action_id,
					'trigger'      => self::RECOVERY_TRIGGER,
					'recovered_at' => gmdate( 'c' ),
				);
				return $engine;
			},
			'stale_claim_resume'
		);
	}

	/**
	 * Find pending or fresh in-progress Action Scheduler actions for a job.
	 *
	 * Mirrors RecoverStuckJobsAbility::getActiveStepActionIds(): a pending
	 * action is live unconditionally, an in-progress action only inside the
	 * freshness window. The LIKE prefilter is job-id-prefixed; the decoded
	 * args are compared exactly.
	 *
	 * @param int $job_id Job ID.
	 * @param int $fresh_window_seconds Freshness window for in-progress actions.
	 * @return array<int,int> Live action IDs.
	 */
	private function findActiveActionIds( int $job_id, int $fresh_window_seconds ): array {
		global $wpdb;

		if ( $job_id <= 0 ) {
			return array();
		}

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$like_job_id   = '%"job_id":' . $wpdb->esc_like( (string) $job_id ) . '%';

		$wpdb->last_error = '';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Hook list is a class constant merged into the prepared replacements array at this boundary.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action_id, args, status, scheduled_date_gmt, last_attempt_gmt
				 FROM %i
				 WHERE hook IN ( %s, %s, %s )
				 AND status IN ( %s, %s )
				 AND args LIKE %s',
				array_merge(
					array( $actions_table ),
					self::LIVE_ACTION_HOOKS,
					array( 'pending', 'in-progress', $like_job_id )
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// @phpstan-ignore-next-line The stub types last_error as its empty default; wpdb mutates it at runtime after failed queries.
		if ( '' !== (string) $wpdb->last_error ) {
			// Fail closed: unreadable scheduler evidence is not proof of a crash.
			return array( 0 );
		}

		$now_gmt = time();
		$active  = array();
		foreach ( $rows as $action ) {
			$args = json_decode( (string) ( $action['args'] ?? '' ), true );
			if ( ! is_array( $args ) || ! ChildJobRecoveryPolicy::actionBelongsToJob( $args, $job_id ) ) {
				continue;
			}

			if ( 'pending' === (string) ( $action['status'] ?? '' ) ) {
				$active[] = (int) ( $action['action_id'] ?? 0 );
				continue;
			}

			$last_attempt = (string) ( $action['last_attempt_gmt'] ?? '' );
			$scheduled    = (string) ( $action['scheduled_date_gmt'] ?? '' );
			$reference    = '' !== $last_attempt && '0000-00-00 00:00:00' !== $last_attempt ? $last_attempt : $scheduled;
			$started_at   = '' !== $reference ? strtotime( $reference ) : false;

			if ( false === $started_at || ( $now_gmt - $started_at ) < $fresh_window_seconds ) {
				$active[] = (int) ( $action['action_id'] ?? 0 );
			}
		}

		return $active;
	}

	/**
	 * Whether a recorded step result represents a successful completion.
	 *
	 * Same completion semantics as JobRetryPolicy::stepCompletedSuccessfully().
	 *
	 * @param array  $recorded Recorded step result entry.
	 * @param string $outcome Pre-resolved outcome string.
	 * @return bool True when the step completed successfully.
	 */
	private function stepCompletedSuccessfully( array $recorded, string $outcome ): bool {
		if ( array_key_exists( 'step_success', $recorded ) ) {
			return (bool) $recorded['step_success'];
		}

		return in_array(
			$outcome,
			array( 'completed', 'completed_override', 'inline_continuation', 'batch_scheduled', 'completed_no_items' ),
			true
		);
	}

	/**
	 * Build one bounded outcome evidence row.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $outcome Outcome class (requeued|terminalized|skipped).
	 * @param string $detail Machine-readable disposition detail.
	 * @param string $reason Human-readable reason.
	 * @return array<string,mixed>
	 */
	private function outcome( int $job_id, string $outcome, string $detail, string $reason ): array {
		return array(
			'job_id'  => $job_id,
			'outcome' => $outcome,
			'detail'  => $detail,
			'reason'  => $reason,
			'trigger' => self::RECOVERY_TRIGGER,
		);
	}
}
