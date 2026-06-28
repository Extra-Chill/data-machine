<?php
/**
 * Global helpers for the in-memory routine registry.
 *
 * Mirrors `src/Workflows/register-workflows.php`: the class file holds
 * the class; helper functions live here so a file is either OO or
 * procedural, never both.
 *
 * Calling `wp_register_routine()` also wires the Action Scheduler bridge:
 * the registry's `wp_agent_routine_registered` action triggers a schedule
 * sync, so cron-driven wakes are live the moment the routine is
 * registered (assuming AS is loaded).
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_register_routine' ) ) {
	/**
	 * Register a code-defined routine.
	 *
	 *     wp_register_routine( 'lunar-monitor', array(
	 *         'label'    => 'Lunar Monitor',
	 *         'agent'    => 'commander',
	 *         'interval' => 3600,
	 *         'prompt'   => 'Status check. Anything new?',
	 *     ) );
	 *
	 * @since 0.105.0
	 *
	 * @param string $id   Unique routine slug.
	 * @param array<mixed> $args Routine arguments. See {@see WP_Agent_Routine::__construct()}.
	 *
	 * @return AgentsAPI\AI\Routines\WP_Agent_Routine|WP_Error
	 */
	function wp_register_routine( string $id, array $args ) {
		return AgentsAPI\AI\Routines\WP_Agent_Routine_Registry::register( $id, wp_agent_string_keyed_routine_args( $args ) );
	}
}

if ( ! function_exists( 'wp_agent_string_keyed_routine_args' ) ) {
	/**
	 * @param array<mixed> $args
	 * @return array<string,mixed>
	 */
	function wp_agent_string_keyed_routine_args( array $args ): array {
		$prepared = array();
		foreach ( $args as $key => $value ) {
			if ( is_string( $key ) ) {
				$prepared[ $key ] = $value;
			}
		}
		return $prepared;
	}
}

if ( ! function_exists( 'wp_get_routine' ) ) {
	/**
	 * Look up a registered routine by id.
	 *
	 * @since 0.105.0
	 */
	function wp_get_routine( string $routine_id ): ?AgentsAPI\AI\Routines\WP_Agent_Routine {
		return AgentsAPI\AI\Routines\WP_Agent_Routine_Registry::find( $routine_id );
	}
}

if ( ! function_exists( 'wp_get_routines' ) ) {
	/**
	 * @since 0.105.0
	 *
	 * @return AgentsAPI\AI\Routines\WP_Agent_Routine[]
	 */
	function wp_get_routines(): array {
		return AgentsAPI\AI\Routines\WP_Agent_Routine_Registry::all();
	}
}

if ( ! function_exists( 'wp_unregister_routine' ) ) {
	/**
	 * @since 0.105.0
	 *
	 * @return true|WP_Error
	 */
	function wp_unregister_routine( string $routine_id ) {
		return AgentsAPI\AI\Routines\WP_Agent_Routine_Registry::unregister( $routine_id );
	}
}

if ( ! function_exists( 'wp_pause_routine' ) ) {
	/**
	 * Pause a routine's recurring/cron schedule without removing it from the
	 * registry. The routine value object stays available via `wp_get_routine()`
	 * and can be resumed later. Persistence of the "paused" UI state is a
	 * consumer concern — the substrate just provides the verb + event.
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	function wp_pause_routine( string $routine_id ) {
		return AgentsAPI\AI\Routines\WP_Agent_Routine_Registry::pause( $routine_id );
	}
}

if ( ! function_exists( 'wp_resume_routine' ) ) {
	/**
	 * Re-establish a previously-paused routine's schedule. Idempotent —
	 * resuming an already-active routine simply re-registers (the underlying
	 * AS bridge unschedules first).
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	function wp_resume_routine( string $routine_id ) {
		return AgentsAPI\AI\Routines\WP_Agent_Routine_Registry::resume( $routine_id );
	}
}

if ( ! function_exists( 'wp_run_routine_now' ) ) {
	/**
	 * Enqueue a one-shot wake for the routine in addition to its recurring
	 * schedule. The next scheduled wake is unaffected. Useful for "Run now"
	 * buttons in admin UIs and for tests.
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	function wp_run_routine_now( string $routine_id ) {
		return AgentsAPI\AI\Routines\WP_Agent_Routine_Registry::run_now( $routine_id );
	}
}
