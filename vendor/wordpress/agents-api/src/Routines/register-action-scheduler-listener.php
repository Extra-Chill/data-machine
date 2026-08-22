<?php
/**
 * Action Scheduler listener for routine wake-ups.
 *
 * Closes the loop on the routines side: when AS fires the routine's
 * scheduled hook, look up the routine, dispatch the canonical
 * `agents/chat` ability with the routine's persistent session id, and
 * record success/failure through the standard observability hook.
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
 * Run the scheduled routine via the canonical chat dispatcher.
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
	add_filter( 'openclawp_chat_ability_permission', $grant );
	try {
		$result = $chat->execute(
			array(
				'agent'      => $routine->get_agent_slug(),
				'message'    => $routine->get_prompt(),
				'session_id' => $routine->get_session_id(),
			)
		);
	} finally {
		remove_filter( 'openclawp_chat_ability_permission', $grant );
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
 * @param array<mixed> $args Scheduled action args.
 */
function self_extract_scheduled_routine_id( array $args ): string {
	$value = $args['routine_id'] ?? ( $args[0] ?? '' );
	return is_string( $value ) ? $value : '';
}
