<?php
/**
 * In-memory registry of code-defined routines.
 *
 * Mirrors {@see WP_Agent_Workflow_Registry}: plugins call
 * {@see wp_register_routine()} during boot, the substrate keeps the
 * resolved Routine in process memory for the duration of the request, and
 * the Action Scheduler bridge (separate file) reads the registry to
 * (re-)register cron schedules on each plugin load.
 *
 * Like the workflow registry, this is stateless across requests — not a
 * cache. Persistence (DB-backed routines) is a consumer concern.
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

namespace AgentsAPI\AI\Routines;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Routine_Registry {

	/**
	 * @var array<string,WP_Agent_Routine>
	 */
	private static array $routines = array();

	/**
	 * @param array<string,mixed> $args See {@see WP_Agent_Routine::__construct()}.
	 * @return WP_Agent_Routine|WP_Error
	 */
	public static function register( string $id, array $args ) {
		try {
			$routine = new WP_Agent_Routine( $id, $args );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_routine', $e->getMessage() );
		}

		self::$routines[ $routine->get_id() ] = $routine;

		/**
		 * Fires after a routine is added to the in-memory registry. The
		 * Action Scheduler bridge subscribes to this hook to (re-)register
		 * the cron schedule.
		 *
		 * @since 0.105.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_registered', $routine );

		return $routine;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function unregister( string $routine_id ) {
		if ( ! isset( self::$routines[ $routine_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no routine registered with id `%s`', $routine_id )
			);
		}
		$routine = self::$routines[ $routine_id ];
		unset( self::$routines[ $routine_id ] );

		/**
		 * Fires after a routine is removed from the in-memory registry. The
		 * AS bridge subscribes to cancel the matching schedule.
		 *
		 * @since 0.105.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_unregistered', $routine );

		return true;
	}

	/**
	 * Pause a registered routine's schedule without unregistering the routine
	 * itself. The value object stays in the registry; the cron schedule is
	 * cancelled. Use {@see resume()} to re-establish it later.
	 *
	 * State (paused-vs-active) is intentionally NOT stored on the value
	 * object or the registry — both are stateless across requests. Consumers
	 * that want a "this routine is paused" UI persist that fact themselves
	 * (typically a `wp_options` flag) and re-fire `pause()` on each plugin
	 * boot. The substrate just provides the verb and the event.
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	public static function pause( string $routine_id ) {
		if ( ! isset( self::$routines[ $routine_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no routine registered with id `%s`', $routine_id )
			);
		}
		$routine = self::$routines[ $routine_id ];

		/**
		 * Fires when a caller requests pausing a routine. The Action Scheduler
		 * bridge listens to cancel the schedule (without unregistering).
		 *
		 * @since 0.106.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_paused', $routine );

		return true;
	}

	/**
	 * Resume a previously-paused routine by re-establishing its schedule.
	 *
	 * Idempotent: resuming a routine whose schedule is still active just
	 * re-fires `wp_agent_routine_resumed`. The AS bridge's register call is
	 * already idempotent (unschedules first), so the net effect is safe.
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	public static function resume( string $routine_id ) {
		if ( ! isset( self::$routines[ $routine_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no routine registered with id `%s`', $routine_id )
			);
		}
		$routine = self::$routines[ $routine_id ];

		/**
		 * Fires when a caller requests resuming a paused routine. The AS
		 * bridge listens to re-register the recurring/cron schedule.
		 *
		 * @since 0.106.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_resumed', $routine );

		return true;
	}

	/**
	 * Trigger an immediate one-shot wake of the routine, in addition to its
	 * recurring schedule. The next scheduled wake is unaffected.
	 *
	 * Useful for "Run now" buttons in admin UIs and for testing — the routine
	 * fires through the same listener as any scheduled wake, so the agent's
	 * conversation session, prompt, and tool surface are identical.
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	public static function run_now( string $routine_id ) {
		if ( ! isset( self::$routines[ $routine_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no routine registered with id `%s`', $routine_id )
			);
		}
		$routine = self::$routines[ $routine_id ];

		/**
		 * Fires when a caller requests an immediate one-shot wake of a
		 * routine. The AS bridge listens to enqueue a single-action job for
		 * the same scheduled-hook the recurring schedule uses.
		 *
		 * @since 0.106.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_run_now_requested', $routine );

		return true;
	}

	public static function find( string $routine_id ): ?WP_Agent_Routine {
		return self::$routines[ $routine_id ] ?? null;
	}

	/**
	 * @return WP_Agent_Routine[]
	 */
	public static function all(): array {
		return array_values( self::$routines );
	}

	/**
	 * Test-only: clear the in-memory registry.
	 *
	 * @since 0.105.0
	 */
	public static function reset(): void {
		self::$routines = array();
	}
}
