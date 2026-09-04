<?php
/**
 * Sync the in-memory routine registry to the Action Scheduler bridge.
 *
 * When `wp_register_routine()` succeeds, the registry fires
 * `wp_agent_routine_registered`. We listen here and ask the AS bridge to
 * (re-)schedule the routine. The bridge no-ops cleanly when AS isn't
 * loaded — same pattern as the workflow side.
 *
 * Same listener handles unregister, cancelling the matching schedule.
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_agent_routine_registered',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Action_Scheduler_Bridge::register( $routine );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_unregistered',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Action_Scheduler_Bridge::unregister( $routine->get_id() );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_paused',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Action_Scheduler_Bridge::pause( $routine->get_id() );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_resumed',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Action_Scheduler_Bridge::resume( $routine );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_run_now_requested',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Action_Scheduler_Bridge::run_now( $routine );
	},
	10,
	1
);
