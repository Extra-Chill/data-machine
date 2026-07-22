<?php
/**
 * Pure policy for pipeline AI concurrency backpressure.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class AIConcurrencyBackpressure {
	private const EXECUTE_STEP_HOOK = 'datamachine_execute_step';
	private const SCHEDULER_GROUP   = 'data-machine';

	/**
	 * Resolve the next durable contention state.
	 *
	 * @param array  $existing     Existing throttle state.
	 * @param string $flow_step_id Current flow step ID.
	 * @param int    $now          Current Unix timestamp.
	 * @param int    $max_age      Maximum contention age in seconds.
	 * @return array<string,mixed>
	 */
	public static function nextState( array $existing, string $flow_step_id, int $now, int $max_age ): array {
		$same_step = (string) ( $existing['flow_step_id'] ?? '' ) === $flow_step_id;
		$attempts  = $same_step ? max( 0, (int) ( $existing['attempts'] ?? 0 ) ) + 1 : 1;

		$first_deferred_at = $same_step ? strtotime( (string) ( $existing['first_deferred_at'] ?? '' ) ) : false;
		if ( false === $first_deferred_at || $first_deferred_at > $now ) {
			$first_deferred_at = $now;
		}

		$age_seconds = max( 0, $now - $first_deferred_at );

		return array(
			'state'                 => $age_seconds >= $max_age ? 'stranded' : 'deferred',
			'flow_step_id'          => $flow_step_id,
			'attempts'              => $attempts,
			'first_deferred_at'     => gmdate( 'c', $first_deferred_at ),
			'last_deferred_at'      => gmdate( 'c', $now ),
			'defer_age_seconds'     => $age_seconds,
			'max_defer_age_seconds' => $max_age,
		);
	}

	/**
	 * Calculate capped exponential contention backoff.
	 */
	public static function delaySeconds( int $base_delay, int $prior_attempts, int $max_delay ): int {
		return min(
			max( 1, $max_delay ),
			max( 1, $base_delay ) * ( 2 ** min( max( 0, $prior_attempts ), 16 ) )
		);
	}

	/**
	 * Schedule one unique continuation, resolving a zero return only when the
	 * scheduler can prove that an equivalent pending action won the race.
	 *
	 * @return array{success:bool,action_id:int,reused:bool}
	 */
	public static function scheduleContinuation( int $timestamp, array $args ): array {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return array(
				'success'   => false,
				'action_id' => 0,
				'reused'    => false,
			);
		}

		$scheduled = as_schedule_single_action(
			$timestamp,
			self::EXECUTE_STEP_HOOK,
			$args,
			self::SCHEDULER_GROUP,
			true
		);
		$action_id = is_numeric( $scheduled ) ? (int) $scheduled : 0;
		if ( $action_id > 0 ) {
			return array(
				'success'   => true,
				'action_id' => $action_id,
				'reused'    => false,
			);
		}

		$action_id = self::pendingContinuationId( $args );
		return array(
			'success'   => $action_id > 0,
			'action_id' => $action_id,
			'reused'    => $action_id > 0,
		);
	}

	/**
	 * Convert active contention into bounded historical evidence.
	 *
	 * @return array<string,mixed>
	 */
	public static function resolvedState( array $throttle, int $now ): array {
		$first_deferred = strtotime( (string) ( $throttle['first_deferred_at'] ?? '' ) );
		$age_seconds    = false === $first_deferred ? max( 0, (int) ( $throttle['defer_age_seconds'] ?? 0 ) ) : max( 0, $now - $first_deferred );

		return array(
			'state'             => 'resolved',
			'reason'            => (string) ( $throttle['reason'] ?? 'ai_concurrency_limit' ),
			'provider'          => (string) ( $throttle['provider'] ?? '' ),
			'flow_step_id'      => (string) ( $throttle['flow_step_id'] ?? '' ),
			'defer_count'       => max( 0, (int) ( $throttle['attempts'] ?? 0 ) ),
			'defer_age_seconds' => $age_seconds,
			'first_deferred_at' => $throttle['first_deferred_at'] ?? null,
			'last_deferred_at'  => $throttle['last_deferred_at'] ?? null,
			'resolved_at'       => gmdate( 'c', $now ),
		);
	}

	private static function pendingContinuationId( array $args ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => self::EXECUTE_STEP_HOOK,
				'args'     => $args,
				'group'    => self::SCHEDULER_GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);

		$action_id = is_array( $action_ids ) ? reset( $action_ids ) : 0;
		return is_numeric( $action_id ) && (int) $action_id > 0 ? (int) $action_id : 0;
	}
}
