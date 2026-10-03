<?php
/**
 * Sync the in-memory routine registry to the resolved routine backend.
 *
 * When `wp_register_routine()` succeeds, the registry fires
 * `wp_agent_routine_registered`. We listen here and ask the resolved
 * backend ({@see WP_Agent_Routine_Registry::backend()}) to (re-)schedule
 * the routine. Every listener no-ops cleanly when no backend is resolved
 * — the routine stays registered but nothing is scheduled.
 *
 * The same listeners handle unregister (cancelling the matching schedule),
 * pause, resume, and run-now requests.
 *
 * The filename keeps its historical `bridge-sync` name to avoid churn; it
 * syncs to whichever backend the `wp_agent_routine_backend` filter
 * resolves, not to any specific scheduler.
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_agent_routine_registered',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->register( $routine );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_unregistered',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->unregister( $routine->get_id() );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_paused',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->pause( $routine->get_id() );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_resumed',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->resume( $routine );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_run_now_requested',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->run_now( $routine );
	},
	10,
	1
);

// The fence hooks listen to Action Scheduler's store lifecycle so fetched
// routine actions are generation-fenced and stale recurrence successors are
// cancelled. This is Action-Scheduler-specific plumbing owned by the
// default backend; the callbacks no-op when Action Scheduler is absent.
WP_Agent_Routine_Action_Scheduler_Bridge::register_generation_fence();
