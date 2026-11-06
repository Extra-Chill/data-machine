<?php
/**
 * Core job liveness classifier.
 *
 * `jobs.status` is lifecycle, not liveness. `JobLiveness` is the single
 * predicate that answers "is this job alive": scheduler evidence (one batch
 * query, see `SchedulerEvidence`) plus persisted engine state in, a
 * classification out. `alive()` collapses the classification to a boolean.
 *
 * @package DataMachine\Core\Jobs
 * @since 0.180.0
 */

namespace DataMachine\Core\Jobs;

use DataMachine\Core\ActionScheduler\PathlessBatchRecovery;
use DataMachine\Core\ChildJobRecoveryPolicy;
use DataMachine\Core\RunMetrics;
use DataMachine\Engine\AI\AIConcurrencyBackpressure;

defined( 'ABSPATH' ) || exit;

class JobLiveness {

	public const ACTIVE_PROCESSING       = 'active_processing';
	public const STALE_IN_PROGRESS       = 'stale_in_progress';
	public const SCHEDULER_STARVED       = 'scheduler_starved';
	public const AI_CONCURRENCY_DEFERRED = 'ai_concurrency_deferred';
	public const QUEUED_NEXT_STEP        = 'queued_next_step';
	public const WAITING_CHILDREN        = 'waiting_children';
	public const EVIDENCE_PRUNED         = 'evidence_pruned';
	public const NO_SCHEDULER_PATH       = 'no_scheduler_path';
	public const EVIDENCE_INCOMPLETE     = 'evidence_incomplete';

	/**
	 * Classifications with a live path that can still advance the job.
	 *
	 * `evidence_incomplete` is alive by construction: absence cannot be proven,
	 * so nothing may be inferred dead.
	 *
	 * @var array<int,string>
	 */
	private const ALIVE_CLASSIFICATIONS = array(
		self::ACTIVE_PROCESSING,
		self::SCHEDULER_STARVED,
		self::AI_CONCURRENCY_DEFERRED,
		self::QUEUED_NEXT_STEP,
		self::WAITING_CHILDREN,
		self::EVIDENCE_INCOMPLETE,
	);

	/**
	 * Action Scheduler's default retention window: 31 days in seconds.
	 *
	 * Mirrors ActionScheduler_QueueCleaner::$month_in_seconds.
	 */
	private const ACTION_SCHEDULER_DEFAULT_RETENTION_SECONDS = 2678400;

	/**
	 * The single liveness predicate: can any path still advance this job?
	 *
	 * @param array<string,mixed> $job             Job row with decoded engine_data.
	 * @param SchedulerEvidence   $evidence        Batch scheduler evidence for the pass.
	 * @param array{active?:int,total?:int,evidence_complete?:bool,action_ids?:list<int>,active_ids?:list<int>,stale_ids?:list<int>} $child_counts    Batch child counts.
	 * @param int                 $overdue_minutes In-progress heartbeat threshold in minutes.
	 * @param int                 $now             Current unix time.
	 */
	public static function alive( array $job, SchedulerEvidence $evidence, array $child_counts, int $overdue_minutes, int $now ): bool {
		return self::isAliveClassification( (string) self::diagnoseWithEvidence( $job, $evidence, $child_counts, $overdue_minutes, $now )['classification'] );
	}

	/**
	 * Batch-parent child counts in the shape `alive()` and `diagnoseWithEvidence()` consume.
	 *
	 * @param int               $parent_job_id   Batch parent job ID.
	 * @param int               $overdue_minutes In-progress heartbeat threshold in minutes.
	 * @param SchedulerEvidence $evidence        Batch scheduler evidence for the pass.
	 * @return array{}|array{total:int,active:int,active_ids:list<int>,stale_ids:list<int>,action_ids:list<int>,evidence_complete:bool}
	 */
	public static function childCounts( int $parent_job_id, int $overdue_minutes, SchedulerEvidence $evidence ): array {
		if ( $parent_job_id <= 0 ) {
			return array();
		}

		$diagnosis = PathlessBatchRecovery::diagnoseChildWork( $parent_job_id, max( 1, $overdue_minutes ) * MINUTE_IN_SECONDS, time(), $evidence );

		return array(
			'total'             => (int) $diagnosis['total_children'],
			'active'            => count( $diagnosis['active_job_ids'] ),
			'active_ids'        => $diagnosis['active_job_ids'],
			'stale_ids'         => $diagnosis['stale_job_ids'],
			'action_ids'        => $diagnosis['active_action_ids'],
			'evidence_complete' => $diagnosis['evidence_complete'],
		);
	}

	/**
	 * Whether a classification represents a live path.
	 */
	public static function isAliveClassification( string $classification ): bool {
		return in_array( $classification, self::ALIVE_CLASSIFICATIONS, true );
	}

	/**
	 * Classify one job against a scheduler evidence snapshot.
	 *
	 * An incomplete snapshot cannot prove absence, so the job is reported as
	 * `evidence_incomplete` (alive) instead of guessed dead.
	 *
	 * @param array<string,mixed> $job          Job row with decoded engine_data.
	 * @param array{active?:int,total?:int,evidence_complete?:bool,action_ids?:list<int>,active_ids?:list<int>,stale_ids?:list<int>} $child_counts Batch child counts.
	 * @return array<string,mixed>
	 */
	public static function diagnoseWithEvidence( array $job, SchedulerEvidence $evidence, array $child_counts, int $overdue_minutes, int $now ): array {
		$actions   = $evidence->isComplete() ? $evidence->actionsFor( (int) ( $job['job_id'] ?? 0 ) ) : array();
		$diagnosis = self::diagnose( $job, $actions, $child_counts, $overdue_minutes, $now );
		if ( ! $evidence->isComplete() ) {
			$diagnosis['classification'] = self::EVIDENCE_INCOMPLETE;
		}
		return $diagnosis;
	}

	/**
	 * Classify one job from persisted engine state and scheduler evidence.
	 *
	 * @param array<string,mixed>              $job Job row with decoded engine_data.
	 * @param array<int,array<string,mixed>>   $actions Matching scheduler actions.
	 * @param array{active?:int,total?:int,evidence_complete?:bool,action_ids?:list<int>,active_ids?:list<int>,stale_ids?:list<int>} $child_counts Batch child counts.
	 * @return array<string,mixed>
	 */
	public static function diagnose( array $job, array $actions, array $child_counts, int $overdue_minutes, int $now ): array {
		$engine_data    = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$actions        = array_values(
			array_filter(
				$actions,
				static function ( array $action ) use ( $job, $engine_data ): bool {
					$hook = (string) ( $action['hook'] ?? '' );
					if ( ! in_array( $hook, SchedulerEvidence::stepHooks(), true ) ) {
						return true;
					}
					if ( ChildJobRecoveryPolicy::actionGenerationMatches( $job, $engine_data, $action ) ) {
						return true;
					}
					if ( AIConcurrencyBackpressure::RESUME_HOOK !== $hook ) {
						return false;
					}

					$throttle = is_array( $engine_data['ai_concurrency_throttle'] ?? null ) ? $engine_data['ai_concurrency_throttle'] : array();
					$owner    = is_array( $engine_data['ai_concurrency_resume_ownership'] ?? null ) ? $engine_data['ai_concurrency_resume_ownership'] : array();
					$args     = is_array( $action['decoded_args'] ?? null ) ? $action['decoded_args'] : array();
					return empty( $owner )
						&& ! isset( $throttle['resume_generation'] )
						&& (int) ( $throttle['action_id'] ?? 0 ) > 0
						&& (int) ( $throttle['action_id'] ?? 0 ) === (int) ( $action['action_id'] ?? 0 )
						&& (string) ( $throttle['flow_step_id'] ?? '' ) === (string) ( $args['flow_step_id'] ?? '' )
						&& ChildJobRecoveryPolicy::actionBelongsToJob( $args, (int) ( $job['job_id'] ?? 0 ) );
				}
			)
		);
		$pending        = array_values( array_filter( $actions, fn( $action ) => SchedulerEvidence::STATUS_PENDING === ( $action['status'] ?? '' ) ) );
		$in_progress    = array_values( array_filter( $actions, fn( $action ) => SchedulerEvidence::STATUS_IN_PROGRESS === ( $action['status'] ?? '' ) ) );
		$complete       = array_values( array_filter( $actions, fn( $action ) => 'complete' === ( $action['status'] ?? '' ) ) );
		$failed         = array_values( array_filter( $actions, fn( $action ) => 'failed' === ( $action['status'] ?? '' ) ) );
		$fresh_progress = array_values(
			array_filter(
				$in_progress,
				static fn( array $action ): bool => self::minutesSince( self::actionReference( $action ), $now ) <= $overdue_minutes
			)
		);

		$oldest_pending      = self::actionDatetime( $pending, 'scheduled_date_gmt', false );
		$oldest_in_progress  = self::actionDatetime( $in_progress, 'scheduled_date_gmt', false );
		$latest_attempt      = self::actionDatetime( $actions, 'last_attempt_gmt', true );
		$oldest_pending_age  = self::minutesSince( $oldest_pending, $now );
		$oldest_progress_age = self::minutesSince( $oldest_in_progress, $now );
		$owner_actions       = array_merge( $pending, $fresh_progress );

		$job_id             = (int) ( $job['job_id'] ?? 0 );
		$active_children    = (int) ( $child_counts['active'] ?? 0 );
		$total_children     = (int) ( $child_counts['total'] ?? 0 );
		$batch_total        = (int) ( $engine_data['batch_total'] ?? 0 );
		$throttle           = is_array( $engine_data['ai_concurrency_throttle'] ?? null ) ? $engine_data['ai_concurrency_throttle'] : array();
		$contention_actions = array_values(
			array_filter(
				$owner_actions,
				static fn( array $action ): bool => AIConcurrencyBackpressure::RESUME_HOOK === (string) ( $action['hook'] ?? '' )
			)
		);
		$contention_owned   = ! empty( $throttle )
			&& 'deferred' === ( $throttle['state'] ?? 'deferred' )
			&& ! empty( $contention_actions );
		$first_deferred     = strtotime( (string) ( $throttle['first_deferred_at'] ?? '' ) );
		$defer_age          = false === $first_deferred ? (int) ( $throttle['defer_age_seconds'] ?? 0 ) : max( 0, $now - $first_deferred );

		if ( ! empty( $fresh_progress ) ) {
			$classification = self::ACTIVE_PROCESSING;
		} elseif ( ! empty( $in_progress ) ) {
			$classification = self::STALE_IN_PROGRESS;
		} elseif ( ! empty( $pending ) && $oldest_pending_age > $overdue_minutes ) {
			$classification = self::SCHEDULER_STARVED;
		} elseif ( ! empty( $throttle ) && 'deferred' === ( $throttle['state'] ?? 'deferred' ) && ! empty( $pending ) ) {
			$classification = self::AI_CONCURRENCY_DEFERRED;
		} elseif ( ! empty( $pending ) ) {
			$classification = self::QUEUED_NEXT_STEP;
		} elseif ( array_key_exists( 'evidence_complete', $child_counts ) && false === $child_counts['evidence_complete'] ) {
			$classification = self::WAITING_CHILDREN;
		} elseif ( $active_children > 0 || ( $batch_total > 0 && $total_children < $batch_total ) ) {
			$classification = self::WAITING_CHILDREN;
		} else {
			$age_seconds    = self::ageSeconds( (string) ( $job['created_at'] ?? '' ), $now );
			$classification = $age_seconds > self::schedulerRetentionSeconds() ? self::EVIDENCE_PRUNED : self::NO_SCHEDULER_PATH;
		}

		$last_activity = $engine_data[ RunMetrics::KEY ]['last_activity_at'] ?? null;

		return array(
			'id'                      => $job_id,
			'flow_id'                 => (string) ( $job['flow_id'] ?? '' ),
			'pipeline_id'             => (string) ( $job['pipeline_id'] ?? '' ),
			'agent_id'                => isset( $job['agent_id'] ) ? (int) $job['agent_id'] : null,
			'classification'          => $classification,
			'created_at'              => (string) ( $job['created_at'] ?? '' ),
			'age_hours'               => round( self::minutesSince( (string) ( $job['created_at'] ?? '' ), $now ) / 60, 1 ),
			'last_activity_at'        => is_string( $last_activity ) ? $last_activity : '',
			'defer_count'             => max( 0, (int) ( $throttle['attempts'] ?? 0 ) ),
			'defer_age_seconds'       => $defer_age,
			'contention_active'       => $contention_owned,
			'contention_provider'     => (string) ( $throttle['provider'] ?? '' ),
			'pending_actions'         => count( $pending ),
			'in_progress_actions'     => count( $in_progress ),
			'complete_actions'        => count( $complete ),
			'failed_actions'          => count( $failed ),
			'owner_action_ids'        => array_values( array_unique( array_merge( array_map( 'intval', array_column( $owner_actions, 'action_id' ) ), array_map( 'intval', $child_counts['action_ids'] ?? array() ) ) ) ),
			'owner_job_ids'           => $child_counts['active_ids'] ?? array(),
			'stale_child_job_ids'     => $child_counts['stale_ids'] ?? array(),
			'child_evidence_complete' => ! array_key_exists( 'evidence_complete', $child_counts ) || true === $child_counts['evidence_complete'],
			'child_jobs'              => $total_children,
			'active_children'         => $active_children,
			'batch_total'             => $batch_total,
			'oldest_pending'          => $oldest_pending,
			'oldest_in_progress'      => $oldest_in_progress,
			'latest_attempt'          => $latest_attempt,
		);
	}

	/**
	 * Effective Action Scheduler retention window in seconds.
	 *
	 * Reads the live `action_scheduler_retention_period` filter so the
	 * evidence boundary tracks runtime configuration. Non-positive filtered
	 * values fall back to Action Scheduler's default rather than treating
	 * the whole history as pruned.
	 *
	 * @return int
	 */
	public static function schedulerRetentionSeconds(): int {
		$default  = self::ACTION_SCHEDULER_DEFAULT_RETENTION_SECONDS;
		$filtered = function_exists( 'apply_filters' )
			? (int) apply_filters( 'action_scheduler_retention_period', $default )
			: $default;
		return $filtered > 0 ? $filtered : $default;
	}

	/** @param array<int,array<string,mixed>> $actions */
	private static function actionDatetime( array $actions, string $field, bool $latest ): string {
		$values = array_values(
			array_filter(
				array_map( static fn( array $action ): string => (string) ( $action[ $field ] ?? '' ), $actions ),
				static fn( string $value ): bool => '' !== $value && '0000-00-00 00:00:00' !== $value
			)
		);
		if ( empty( $values ) ) {
			return '';
		}

		sort( $values );
		return $latest ? (string) end( $values ) : $values[0];
	}

	/** Use the same in-progress heartbeat preference as timeout recovery. */
	private static function actionReference( array $action ): string {
		$last_attempt = (string) ( $action['last_attempt_gmt'] ?? '' );
		return '' !== $last_attempt && '0000-00-00 00:00:00' !== $last_attempt
			? $last_attempt
			: (string) ( $action['scheduled_date_gmt'] ?? '' );
	}

	private static function ageSeconds( string $datetime, int $now ): int {
		$timestamp = strtotime( $datetime . ' UTC' );
		return false === $timestamp ? 0 : max( 0, $now - $timestamp );
	}

	private static function minutesSince( string $datetime, int $now ): int {
		return (int) floor( self::ageSeconds( $datetime, $now ) / MINUTE_IN_SECONDS );
	}
}
