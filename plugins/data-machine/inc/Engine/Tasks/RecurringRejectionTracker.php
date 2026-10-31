<?php
/**
 * Recurring Rejection Tracker — correlates repeated recurring-schedule rejections.
 *
 * A one-off scheduling failure is a transient event: log an error, move on.
 * A *recurring* binding that is rejected by TaskScheduler::schedule() on every
 * tick is a different, worse failure class — a permanent misconfiguration that
 * silently disables a scheduled task. Without correlation across ticks, the two
 * look identical in the log (N identical error rows), and a dead recurring
 * binding can stay invisible for days.
 *
 * This tracker counts consecutive rejections per schedule_id (persisted in a
 * single autoloaded option), escalates once a small threshold is crossed by
 * emitting a distinct, dedup-aware log signal instead of yet another identical
 * row, and exposes the degraded set so the system health surface can flag it.
 *
 * It does NOT change the scheduler's accept/reject decision — it only observes
 * the int|false return of TaskScheduler::schedule() at the recurring dispatch
 * seam. It is reason-agnostic: any rejection (unknown task type, missing
 * ability, agent-context required, …) increments the same per-schedule counter.
 *
 * @package DataMachine\Engine\Tasks
 * @since   0.139.0
 */

namespace DataMachine\Engine\Tasks;

defined( 'ABSPATH' ) || exit;

class RecurringRejectionTracker {

	/**
	 * Option key storing the per-schedule consecutive-rejection state.
	 *
	 * Shape: array<string schedule_id, array{
	 *     count:int,
	 *     task_type:string,
	 *     reason:string,
	 *     first_rejected_gmt:string,
	 *     last_rejected_gmt:string,
	 *     escalated:bool
	 * }>
	 */
	public const OPTION = 'datamachine_recurring_rejections';

	/**
	 * Consecutive rejection ticks before a recurring binding is treated as
	 * persistently rejected (degraded) and escalated.
	 *
	 * Small on purpose: three consecutive ticks is enough to distinguish a
	 * permanent misconfiguration from a transient blip, without waiting days.
	 */
	public const DEFAULT_THRESHOLD = 3;

	/**
	 * The escalating log error code emitted once the threshold is crossed.
	 *
	 * Distinct from the per-tick gate error codes (e.g.
	 * task_scheduler_agent_context_required) so it reads as one escalating
	 * problem rather than N unrelated errors.
	 */
	public const ESCALATION_ERROR_CODE = 'recurring_schedule_persistently_rejected';

	/**
	 * Resolve the consecutive-rejection threshold.
	 *
	 * @return int Threshold (minimum 1).
	 */
	public static function threshold(): int {
		$threshold = (int) apply_filters(
			'datamachine_recurring_rejection_threshold',
			self::DEFAULT_THRESHOLD
		);

		return max( 1, $threshold );
	}

	/**
	 * Record that a recurring binding was rejected on this tick.
	 *
	 * Increments the per-schedule consecutive counter. When the count first
	 * reaches the threshold, emits a distinct escalating log entry (once per
	 * escalation, not once per tick) carrying the running count.
	 *
	 * @param string $schedule_id Recurring schedule identifier.
	 * @param string $task_type   Bound task type (for diagnostics).
	 * @param string $reason      Optional rejection reason / error code.
	 * @return int The new consecutive-rejection count.
	 */
	public static function record_rejection( string $schedule_id, string $task_type = '', string $reason = '' ): int {
		if ( '' === $schedule_id ) {
			return 0;
		}

		$state = self::all();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$entry = $state[ $schedule_id ] ?? array(
			'count'              => 0,
			'task_type'          => $task_type,
			'reason'             => $reason,
			'first_rejected_gmt' => $now,
			'last_rejected_gmt'  => $now,
			'escalated'          => false,
		);

		$entry['count']             = (int) $entry['count'] + 1;
		$entry['task_type']         = '' !== $task_type ? $task_type : ( $entry['task_type'] ?? '' );
		$entry['reason']            = '' !== $reason ? $reason : ( $entry['reason'] ?? '' );
		$entry['last_rejected_gmt'] = $now;
		if ( empty( $entry['first_rejected_gmt'] ) ) {
			$entry['first_rejected_gmt'] = $now;
		}

		$threshold      = self::threshold();
		$just_escalated = false;
		if ( $entry['count'] >= $threshold && empty( $entry['escalated'] ) ) {
			$entry['escalated'] = true;
			$just_escalated     = true;
		}

		$state[ $schedule_id ] = $entry;
		self::save( $state );

		// Emit the distinct escalating signal exactly once per escalation,
		// rather than appending another identical per-tick error row.
		if ( $just_escalated ) {
			do_action(
				'datamachine_log',
				'error',
				sprintf(
					'Recurring schedule "%s" persistently rejected: %d consecutive ticks rejected, task never ran.',
					$schedule_id,
					$entry['count']
				),
				array(
					'context'            => 'system',
					'schedule_id'        => $schedule_id,
					'task_type'          => $entry['task_type'],
					'rejection_reason'   => $entry['reason'],
					'consecutive_count'  => $entry['count'],
					'threshold'          => $threshold,
					'first_rejected_gmt' => $entry['first_rejected_gmt'],
					'error_code'         => self::ESCALATION_ERROR_CODE,
					'recommendation'     => 'A recurring binding has been rejected every tick. Fix the schedule registration or its prerequisites (agent ownership, task registration, ability availability); it will not run until the rejection stops.',
				)
			);
		}

		return $entry['count'];
	}

	/**
	 * Record that a recurring binding scheduled successfully on this tick.
	 *
	 * Clears any accumulated consecutive-rejection state for the schedule, so
	 * a recovered binding stops showing as degraded.
	 *
	 * @param string $schedule_id Recurring schedule identifier.
	 * @return void
	 */
	public static function record_success( string $schedule_id ): void {
		if ( '' === $schedule_id ) {
			return;
		}

		$state = self::all();
		if ( ! isset( $state[ $schedule_id ] ) ) {
			return;
		}

		unset( $state[ $schedule_id ] );
		self::save( $state );
	}

	/**
	 * All tracked rejection state.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Schedules currently degraded (consecutive rejections at/over threshold).
	 *
	 * @return array<string, array> Keyed by schedule_id.
	 */
	public static function degraded(): array {
		$threshold = self::threshold();
		$degraded  = array();
		foreach ( self::all() as $schedule_id => $entry ) {
			if ( (int) ( $entry['count'] ?? 0 ) >= $threshold ) {
				$degraded[ $schedule_id ] = $entry;
			}
		}

		return $degraded;
	}

	/**
	 * Persist the rejection state.
	 *
	 * @param array $state State map.
	 * @return void
	 */
	private static function save( array $state ): void {
		if ( empty( $state ) ) {
			delete_option( self::OPTION );
			return;
		}

		update_option( self::OPTION, $state, false );
	}
}
