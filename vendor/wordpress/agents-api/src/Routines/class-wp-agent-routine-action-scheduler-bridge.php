<?php
/**
 * Optional Action Scheduler bridge for routines.
 *
 * Mirrors {@see \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge}:
 * agents-api does not require Action Scheduler. When AS is available we
 * register one recurring (or cron-expression) action per routine with a
 * stable args array so the listener can resolve the routine on wake.
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Routine_Action_Scheduler_Bridge {

	/** @since 0.105.0 */
	public const SCHEDULED_HOOK = 'wp_agent_routine_run_scheduled';

	/** @since 0.105.0 */
	public const GROUP = 'agents-api';

	public static function is_available(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_schedule_cron_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Register the routine's schedule with Action Scheduler. Existing
	 * schedules for the same routine are unscheduled first to make this
	 * idempotent — call freely on every plugin boot.
	 *
	 * @since 0.105.0
	 *
	 * @return bool True when a schedule was registered (or the
	 *              `wp_agent_routine_schedule_requested` hook was fired
	 *              even without AS); false on no-op.
	 */
	public static function register( WP_Agent_Routine $routine ): bool {
		/**
		 * Fires whenever the bridge would schedule a routine, regardless
		 * of whether Action Scheduler is loaded. Custom schedulers can
		 * hook this to take over.
		 *
		 * @since 0.105.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_schedule_requested', $routine );

		if ( ! self::is_available() ) {
			return false;
		}

		$args = array( 'routine_id' => $routine->get_id() );

		// Unschedule prior occurrences for idempotency.
		as_unschedule_all_actions( self::SCHEDULED_HOOK, $args, self::GROUP );

		if ( WP_Agent_Routine::TRIGGER_EXPRESSION === $routine->get_trigger_type() ) {
			return ! empty( as_schedule_cron_action(
				time(),
				$routine->get_expression(),
				self::SCHEDULED_HOOK,
				$args,
				self::GROUP
			) );
		}

		return ! empty( as_schedule_recurring_action(
			time(),
			$routine->get_interval_seconds(),
			self::SCHEDULED_HOOK,
			$args,
			self::GROUP
		) );
	}

	/**
	 * Cancel every scheduled action this bridge owns for the given routine.
	 *
	 * @since 0.105.0
	 */
	public static function unregister( string $routine_id ): void {
		if ( ! self::is_available() ) {
			return;
		}
		as_unschedule_all_actions(
			self::SCHEDULED_HOOK,
			array( 'routine_id' => $routine_id ),
			self::GROUP
		);
	}

	/**
	 * Cancel the recurring/cron schedule without removing the routine from
	 * the registry. Mirrors {@see unregister()}; the only behavioural
	 * difference is the upstream caller's intent (the routine stays in
	 * memory and can be {@see resume()}d).
	 *
	 * @since 0.106.0
	 */
	public static function pause( string $routine_id ): void {
		self::unregister( $routine_id );
	}

	/**
	 * Re-establish the recurring/cron schedule for a previously-paused
	 * routine. Idempotent — calling on a routine whose schedule is still
	 * active simply re-registers (the underlying register call unschedules
	 * first).
	 *
	 * @since 0.106.0
	 */
	public static function resume( WP_Agent_Routine $routine ): bool {
		return self::register( $routine );
	}

	/**
	 * Enqueue a single-shot action for the routine, in addition to its
	 * recurring schedule. The listener already resolves the routine by
	 * `routine_id`, so the same wake handler fires for both kinds of
	 * dispatch.
	 *
	 * @since 0.106.0
	 */
	public static function run_now( WP_Agent_Routine $routine ): bool {
		if ( ! self::is_available() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		as_enqueue_async_action(
			self::SCHEDULED_HOOK,
			array( 'routine_id' => $routine->get_id() ),
			self::GROUP
		);
		return true;
	}
}
