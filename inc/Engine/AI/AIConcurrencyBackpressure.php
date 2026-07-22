<?php
/**
 * Pure policy for pipeline AI concurrency backpressure.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class AIConcurrencyBackpressure {

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
}
