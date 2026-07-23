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
		$engine_data = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$actions     = array_values(
			array_filter(
				$actions,
				static function ( array $action ) use ( $job, $engine_data ): bool {
					$hook = (string) ( $action['hook'] ?? '' );
					return ! in_array( $hook, array( 'datamachine_execute_step', 'datamachine_resume_ai_step' ), true )
						|| ChildJobRecoveryPolicy::actionGenerationMatches( $job, $engine_data, $action );
				}
			)
		);
		$pending     = array_values( array_filter( $actions, fn( $action ) => 'pending' === ( $action['status'] ?? '' ) ) );
		$in_progress = array_values( array_filter( $actions, fn( $action ) => 'in-progress' === ( $action['status'] ?? '' ) ) );
		$complete    = array_values( array_filter( $actions, fn( $action ) => 'complete' === ( $action['status'] ?? '' ) ) );
		$failed      = array_values( array_filter( $actions, fn( $action ) => 'failed' === ( $action['status'] ?? '' ) ) );

		$oldest_pending      = self::actionDatetime( $pending, 'scheduled_date_gmt', false );
		$oldest_in_progress  = self::actionDatetime( $in_progress, 'scheduled_date_gmt', false );
		$latest_attempt      = self::actionDatetime( $actions, 'last_attempt_gmt', true );
		$oldest_pending_age  = self::minutesSince( $oldest_pending, $now );
		$oldest_progress_age = self::minutesSince( $oldest_in_progress, $now );

		$job_id          = (int) ( $job['job_id'] ?? 0 );
		$active_children = (int) ( $child_counts['active'] ?? 0 );
		$total_children  = (int) ( $child_counts['total'] ?? 0 );
		$batch_total     = (int) ( $engine_data['batch_total'] ?? 0 );
		$throttle        = is_array( $engine_data['ai_concurrency_throttle'] ?? null ) ? $engine_data['ai_concurrency_throttle'] : array();
		$first_deferred  = strtotime( (string) ( $throttle['first_deferred_at'] ?? '' ) );
		$defer_age       = false === $first_deferred ? (int) ( $throttle['defer_age_seconds'] ?? 0 ) : max( 0, $now - $first_deferred );

		if ( ! empty( $in_progress ) && $oldest_progress_age > $overdue_minutes ) {
			$classification = 'stale_in_progress';
		} elseif ( ! empty( $in_progress ) ) {
			$classification = 'active_processing';
		} elseif ( ! empty( $pending ) && $oldest_pending_age > $overdue_minutes ) {
			$classification = 'scheduler_starved';
		} elseif ( ! empty( $throttle ) && 'deferred' === ( $throttle['state'] ?? 'deferred' ) && ! empty( $pending ) ) {
			$classification = 'ai_concurrency_deferred';
		} elseif ( ! empty( $pending ) ) {
			$classification = 'queued_next_step';
		} elseif ( $active_children > 0 || ( $batch_total > 0 && $total_children < $batch_total ) ) {
			$classification = 'waiting_children';
		} else {
			$classification = 'no_scheduler_path';
		}

		$last_activity = $engine_data['run_metrics']['last_activity_at'] ?? null;

		return array(
			'id'                  => $job_id,
			'flow_id'             => (string) ( $job['flow_id'] ?? '' ),
			'pipeline_id'         => (string) ( $job['pipeline_id'] ?? '' ),
			'agent_id'            => isset( $job['agent_id'] ) ? (int) $job['agent_id'] : null,
			'classification'      => $classification,
			'created_at'          => (string) ( $job['created_at'] ?? '' ),
			'age_hours'           => round( self::minutesSince( (string) ( $job['created_at'] ?? '' ), $now ) / 60, 1 ),
			'last_activity_at'    => is_string( $last_activity ) ? $last_activity : '',
			'defer_count'         => max( 0, (int) ( $throttle['attempts'] ?? 0 ) ),
			'defer_age_seconds'   => $defer_age,
			'contention_active'   => ! empty( $throttle ) && 'deferred' === ( $throttle['state'] ?? 'deferred' ),
			'contention_provider' => (string) ( $throttle['provider'] ?? '' ),
			'pending_actions'     => count( $pending ),
			'in_progress_actions' => count( $in_progress ),
			'complete_actions'    => count( $complete ),
			'failed_actions'      => count( $failed ),
			'child_jobs'          => $total_children,
			'active_children'     => $active_children,
			'batch_total'         => $batch_total,
			'oldest_pending'      => $oldest_pending,
			'oldest_in_progress'  => $oldest_in_progress,
			'latest_attempt'      => $latest_attempt,
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

	private static function minutesSince( string $datetime, int $now ): int {
		$timestamp = strtotime( $datetime . ' UTC' );
		return false === $timestamp ? 0 : max( 0, (int) floor( ( $now - $timestamp ) / MINUTE_IN_SECONDS ) );
	}
}
