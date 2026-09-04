<?php
/**
 * Pure scheduler-liveness classification for active jobs.
 *
 * @package DataMachine\Cli
 */

namespace DataMachine\Cli;

use DataMachine\Core\ChildJobRecoveryPolicy;

defined( 'ABSPATH' ) || exit;

class JobLivenessClassifier {
	/**
	 * Classify one job from persisted engine state and scheduler evidence.
	 *
	 * @param array<string,mixed>              $job Job row with decoded engine_data.
	 * @param array<int,array<string,mixed>>   $actions Matching scheduler actions.
	 * @param array<string,int>                $child_counts Batch child counts.
	 * @return array<string,mixed>
	 */
	public static function diagnose( array $job, array $actions, array $child_counts, int $overdue_minutes, int $now ): array {
		$engine_data    = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$actions        = array_values(
			array_filter(
				$actions,
				static function ( array $action ) use ( $job, $engine_data ): bool {
					$hook = (string) ( $action['hook'] ?? '' );
					if ( ! in_array( $hook, array( 'datamachine_execute_step', 'datamachine_resume_ai_step' ), true ) ) {
						return true;
					}
					if ( ChildJobRecoveryPolicy::actionGenerationMatches( $job, $engine_data, $action ) ) {
						return true;
					}
					if ( 'datamachine_resume_ai_step' !== $hook ) {
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
		$pending        = array_values( array_filter( $actions, fn( $action ) => 'pending' === ( $action['status'] ?? '' ) ) );
		$in_progress    = array_values( array_filter( $actions, fn( $action ) => 'in-progress' === ( $action['status'] ?? '' ) ) );
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
				static fn( array $action ): bool => 'datamachine_resume_ai_step' === (string) ( $action['hook'] ?? '' )
			)
		);
		$contention_owned   = ! empty( $throttle )
			&& 'deferred' === ( $throttle['state'] ?? 'deferred' )
			&& ! empty( $contention_actions );
		$first_deferred     = strtotime( (string) ( $throttle['first_deferred_at'] ?? '' ) );
		$defer_age          = false === $first_deferred ? (int) ( $throttle['defer_age_seconds'] ?? 0 ) : max( 0, $now - $first_deferred );

		if ( ! empty( $fresh_progress ) ) {
			$classification = 'active_processing';
		} elseif ( ! empty( $in_progress ) ) {
			$classification = 'stale_in_progress';
		} elseif ( ! empty( $pending ) && $oldest_pending_age > $overdue_minutes ) {
			$classification = 'scheduler_starved';
		} elseif ( ! empty( $throttle ) && 'deferred' === ( $throttle['state'] ?? 'deferred' ) && ! empty( $pending ) ) {
			$classification = 'ai_concurrency_deferred';
		} elseif ( ! empty( $pending ) ) {
			$classification = 'queued_next_step';
		} elseif ( array_key_exists( 'evidence_complete', $child_counts ) && false === $child_counts['evidence_complete'] ) {
			$classification = 'waiting_children';
		} elseif ( $active_children > 0 || ( $batch_total > 0 && $total_children < $batch_total ) ) {
			$classification = 'waiting_children';
		} else {
			$classification = 'no_scheduler_path';
		}

		$last_activity = $engine_data['run_metrics']['last_activity_at'] ?? null;

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
			'owner_job_ids'           => array_values( array_map( 'intval', $child_counts['active_ids'] ?? array() ) ),
			'stale_child_job_ids'     => array_values( array_map( 'intval', $child_counts['stale_ids'] ?? array() ) ),
			'child_evidence_complete' => ! array_key_exists( 'evidence_complete', $child_counts ) || true === $child_counts['evidence_complete'],
			'child_jobs'              => $total_children,
			'active_children'         => $active_children,
			'batch_total'             => $batch_total,
			'oldest_pending'          => $oldest_pending,
			'oldest_in_progress'      => $oldest_in_progress,
			'latest_attempt'          => $latest_attempt,
		);
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

	private static function minutesSince( string $datetime, int $now ): int {
		$timestamp = strtotime( $datetime . ' UTC' );
		return false === $timestamp ? 0 : max( 0, (int) floor( ( $now - $timestamp ) / MINUTE_IN_SECONDS ) );
	}
}
