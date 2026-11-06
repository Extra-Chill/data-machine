<?php
/**
 * Action Scheduler listener for routine wake-ups.
 *
 * Closes the loop on the routines side: when AS fires the routine's
 * scheduled hook, look up the routine and dispatch its wake target. A
 * `chat` target sends the routine's prompt to its agent through the
 * canonical `agents/chat` ability with the routine's persistent session
 * id; an `ability` target executes the named ability directly with the
 * routine's input. Success/failure is recorded through the standard
 * observability hook either way.
 *
 * Errors are funneled through `agents_run_routine_dispatch_failed` rather
 * than thrown — throwing from an AS callback marks the action as failed
 * and triggers exponential back-off, which is rarely the desired outcome
 * when the failure is transient (consumer plugin redeploy, missing chat
 * handler, etc.).
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

add_action(
	WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK,
	__NAMESPACE__ . '\\dispatch_scheduled_routine_run',
	10,
	1
);

/**
 * Run the scheduled routine via its wake target.
 *
 * @since 0.105.0
 *
 * @param string|array<mixed> $args Either the bare routine_id string (when AS
 *                                  passes a single positional) or the full args
 *                                  array.
 */
function dispatch_scheduled_routine_run( $args ): void {
	$routine_id = '';
	if ( is_string( $args ) ) {
		$routine_id = $args;
	} elseif ( is_array( $args ) ) {
		$routine_id = self_extract_scheduled_routine_id( $args );
	}

	if ( '' === $routine_id ) {
		do_action( 'agents_run_routine_dispatch_failed', 'no_routine_id', array( 'source' => 'action_scheduler' ) );
		return;
	}

	$routine = WP_Agent_Routine_Registry::find( $routine_id );
	if ( null === $routine ) {
		// Routine was unregistered between schedule and wake. The bridge
		// cleans up on `wp_agent_routine_unregistered`, but a half-step
		// race can land an in-flight wake here.
		do_action(
			'agents_run_routine_dispatch_failed',
			'routine_not_registered',
			array(
				'routine_id' => $routine_id,
				'source'     => 'action_scheduler',
			)
		);
		return;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		do_action( 'agents_run_routine_dispatch_failed', 'abilities_api_missing', array( 'routine_id' => $routine_id ) );
		return;
	}

	if ( WP_Agent_Routine::TARGET_ABILITY === $routine->get_target_type() ) {
		self_dispatch_routine_ability_target( $routine );
		return;
	}

	$chat = wp_get_ability( 'agents/chat' );
	if ( null === $chat ) {
		do_action( 'agents_run_routine_dispatch_failed', 'agents_chat_missing', array( 'routine_id' => $routine_id ) );
		return;
	}

	// AS runs as the loopback / cron user — bypass the manage_options gate
	// for this scheduled invocation only. Same pattern as the workflow
	// listener.
	$grant = static fn() => true;
	add_filter( 'agents_chat_permission', $grant );
	try {
		$result = $chat->execute(
			array(
				'agent'      => $routine->get_agent_slug(),
				'message'    => $routine->get_prompt(),
				'session_id' => $routine->get_session_id(),
			)
		);
	} finally {
		remove_filter( 'agents_chat_permission', $grant );
	}

	if ( is_wp_error( $result ) ) {
		do_action(
			'agents_run_routine_dispatch_failed',
			$result->get_error_code(),
			array(
				'routine_id' => $routine_id,
				'source'     => 'action_scheduler',
			)
		);
		return;
	}

	/**
	 * Fires after a successful scheduled routine dispatch. Consumers wire
	 * up run-recording (timing, assistant reply, token usage) here.
	 *
	 * @since 0.105.0
	 *
	 * @param WP_Agent_Routine $routine
	 * @param mixed                   $result Canonical chat output.
	 */
	do_action( 'wp_agent_routine_run_completed', $routine, $result );
}

/**
 * Dispatch a scheduled routine whose wake target is an ability.
 *
 * Unlike the chat path there is no implicit permission lift: the scheduled
 * invocation runs as the cron/loopback principal, so executing an arbitrary
 * ability on a schedule requires an explicit opt-in through the
 * `wp_agent_routine_ability_permission` filter (default deny).
 *
 * @since 0.11.0
 *
 * @param WP_Agent_Routine $routine The resolved routine.
 */
function self_dispatch_routine_ability_target( WP_Agent_Routine $routine ): void {
	$routine_id = $routine->get_id();
	$ability    = wp_get_ability( $routine->get_ability() );
	if ( null === $ability ) {
		do_action(
			'agents_run_routine_dispatch_failed',
			'ability_missing',
			array(
				'routine_id' => $routine_id,
				'ability'    => $routine->get_ability(),
			)
		);
		return;
	}

	/**
	 * Filters whether a scheduled routine may execute its target ability.
	 *
	 * Defaults to false — consumers that register ability-targeted routines
	 * opt in by filtering, typically scoped to the abilities they own. The
	 * same shape as the chat gate (`agents_chat_permission`), with no
	 * hidden elevation for the scheduled invocation.
	 *
	 * @since 0.11.0
	 *
	 * @param bool             $allowed Whether the routine may execute the ability. Default false.
	 * @param WP_Agent_Routine $routine The routine attempting the wake.
	 * @param mixed            $ability The resolved target ability object.
	 */
	$allowed = (bool) apply_filters( 'wp_agent_routine_ability_permission', false, $routine, $ability );
	if ( ! $allowed ) {
		do_action(
			'agents_run_routine_dispatch_failed',
			'permission_denied',
			array(
				'routine_id' => $routine_id,
				'ability'    => $routine->get_ability(),
			)
		);
		return;
	}

	$result = $ability->execute( $routine->get_input() );

	if ( is_wp_error( $result ) ) {
		do_action(
			'agents_run_routine_dispatch_failed',
			$result->get_error_code(),
			array(
				'routine_id' => $routine_id,
				'ability'    => $routine->get_ability(),
			)
		);
		return;
	}

	/**
	 * Fires after a successful scheduled routine dispatch with an ability
	 * target. Mirrors the chat-path completion action: consumers wire up
	 * run-recording here, receiving the ability result instead of the
	 * canonical chat output.
	 *
	 * @since 0.11.0
	 *
	 * @param WP_Agent_Routine $routine
	 * @param mixed                   $result Target ability output.
	 */
	do_action( 'wp_agent_routine_run_completed', $routine, $result );
}

/**
 * Resolve the routine id out of scheduled-action args.
 *
 * @param array<mixed> $args Scheduled action args.
 */
function self_extract_scheduled_routine_id( array $args ): string {
	$value = $args['routine_id'] ?? ( $args[0] ?? '' );
	return is_string( $value ) ? $value : '';
}
