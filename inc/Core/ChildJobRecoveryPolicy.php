<?php
// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Keep recovery evidence keys readable without spacing-only churn.
/**
 * Pure ownership policy for recovering pathless batch children.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class ChildJobRecoveryPolicy {
	/** Exact decoded scheduler ownership check; avoids numeric-prefix collisions. */
	public static function actionBelongsToJob( array $args, int $job_id ): bool {
		return $job_id > 0 && (int) ( $args['job_id'] ?? 0 ) === $job_id;
	}

	/**
	 * Diagnose scheduler ownership and replay eligibility.
	 *
	 * @param array<string,mixed>            $job Job row.
	 * @param array<string,mixed>            $engine_data Decoded engine data.
	 * @param array<int,array<string,mixed>> $actions Exact job action history, newest first.
	 * @param int                            $timeout_seconds In-progress freshness window.
	 * @param int                            $now Current timestamp.
	 * @return array<string,mixed>
	 */
	public static function diagnose( array $job, array $engine_data, array $actions, int $timeout_seconds, int $now ): array {
		$active = null;
		foreach ( $actions as $action ) {
			if ( self::ownsContinuation( $job, $engine_data, $action, $timeout_seconds, $now ) ) {
				$active = $action;
				break;
			}
		}

		$latest = $actions[0] ?? array();
		$args   = is_array( $latest['decoded_args'] ?? null ) ? $latest['decoded_args'] : array();
		$step   = (string) ( $args['flow_step_id'] ?? '' );
		$replay = null === $active
			&& 'failed' === (string) ( $latest['status'] ?? '' )
			&& 'datamachine_execute_step' === (string) ( $latest['hook'] ?? '' )
			&& '' !== $step
			&& isset( $engine_data['flow_config'][ $step ] )
			&& self::recoveryGenerationMatches( $engine_data, $latest, $args )
			&& self::operationGenerationMatches( $job, $args );

		return array(
			'has_active_path' => null !== $active,
			'active_action'   => $active,
			'latest_action'   => $latest,
			'retry_eligible'  => $replay,
			'retry_args'      => $replay ? $args : array(),
			'reason'          => null !== $active ? 'active_scheduler_path' : ( $replay ? 'failed_action_replayable' : ( empty( $latest ) ? 'missing_action' : 'no_replay_safe_action' ) ),
		);
	}

	private static function ownsContinuation( array $job, array $engine_data, array $action, int $timeout_seconds, int $now ): bool {
		$status = (string) ( $action['status'] ?? '' );
		if ( ! in_array( $status, array( 'pending', 'in-progress' ), true ) ) {
			return false;
		}

		if ( 'in-progress' === $status ) {
			$reference = (string) ( $action['last_attempt_gmt'] ?? $action['scheduled_date_gmt'] ?? '' );
			$started   = strtotime( $reference . ' UTC' );
			if ( false !== $started && ( $now - $started ) >= max( 1, $timeout_seconds ) ) {
				return false;
			}
		}

		$args = is_array( $action['decoded_args'] ?? null ) ? $action['decoded_args'] : array();
		if ( 'datamachine_resume_ai_step' === (string) ( $action['hook'] ?? '' ) ) {
			$owner      = is_array( $engine_data['ai_concurrency_resume_ownership'] ?? null ) ? $engine_data['ai_concurrency_resume_ownership'] : array();
			$generation = (int) ( $args['ai_resume_generation'] ?? 0 );
			$owner_state = 'pending' === $status ? 'scheduled' : 'running';

			return 0 < $generation
				&& (int) ( $owner['generation'] ?? 0 ) === $generation
				&& (string) ( $owner['status'] ?? '' ) === $owner_state
				&& (string) ( $args['flow_step_id'] ?? '' ) === (string) ( $owner['flow_step_id'] ?? '' )
				&& ( 0 === (int) ( $owner['action_id'] ?? 0 ) || (int) ( $action['action_id'] ?? 0 ) === (int) $owner['action_id'] );
		}
		if ( ! self::recoveryGenerationMatches( $engine_data, $action, $args ) ) {
			return false;
		}

		return self::operationGenerationMatches( $job, $args );
	}

	private static function recoveryGenerationMatches( array $engine_data, array $action, array $args ): bool {
		$generation = (int) ( $args['recovery_generation'] ?? 0 );
		if ( 0 === $generation ) {
			return true;
		}

		$owner   = is_array( $engine_data['scheduler_recovery'] ?? null ) ? $engine_data['scheduler_recovery'] : array();
		$receipt = is_array( $owner['receipt'] ?? null ) ? $owner['receipt'] : array();
		$token   = (string) ( $args['recovery_claim_token'] ?? '' );

		return '' !== $token
			&& 'requeued' === (string) ( $owner['state'] ?? '' )
			&& (int) ( $owner['generation'] ?? 0 ) === $generation
			&& (int) ( $receipt['generation'] ?? 0 ) === $generation
			&& hash_equals( $token, (string) ( $owner['token'] ?? '' ) )
			&& ( 0 === (int) ( $receipt['action_id'] ?? 0 ) || (int) ( $action['action_id'] ?? 0 ) === (int) $receipt['action_id'] );
	}

	private static function operationGenerationMatches( array $job, array $args ): bool {
		$generation = (int) ( $args['operation_generation'] ?? 0 );
		if ( 0 === $generation ) {
			return true;
		}

		return 'enqueued' === (string) ( $job['operation_state'] ?? '' )
			&& (int) ( $job['operation_generation'] ?? 0 ) === $generation
			&& '' !== (string) ( $args['operation_claim_token'] ?? '' )
			&& hash_equals( (string) ( $job['operation_claim_token'] ?? '' ), (string) $args['operation_claim_token'] );
	}
}
