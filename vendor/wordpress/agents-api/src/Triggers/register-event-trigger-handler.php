<?php
/**
 * Event trigger hook wiring helpers.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Triggers;

defined( 'ABSPATH' ) || exit;

const WP_AGENT_EVENT_TRIGGER_RUN_HOOK = 'wp_agent_event_trigger_run';

add_action(
	'wp_agent_event_trigger_registered',
	__NAMESPACE__ . '\register_event_trigger_handler',
	10,
	1
);

/**
 * Attach a WordPress action listener for a registered event trigger.
 */
function register_event_trigger_handler( WP_Agent_Event_Trigger $trigger ): void {
	if ( ! $trigger->is_enabled() ) {
		return;
	}

	$hook_name = $trigger->get_hook_name();
	if ( '' === $hook_name ) {
		return;
	}

	add_action(
		$hook_name,
		static function ( ...$hook_args ) use ( $trigger ): void {
			dispatch_event_trigger_hook( $trigger, array_values( $hook_args ) );
		},
		PHP_INT_MAX,
		max( 1, count( $trigger->get_args_shape() ) )
	);
}

/**
 * Resolve a hook payload and enqueue an async event-trigger run.
 *
 * @param WP_Agent_Event_Trigger $trigger   Event trigger.
 * @param array<int, mixed>      $hook_args Positional hook arguments.
 */
function dispatch_event_trigger_hook( WP_Agent_Event_Trigger $trigger, array $hook_args ): void {
	$payload = $trigger->payload_from_hook_args( $hook_args );
	if ( ! $trigger->conditions_match( $payload ) ) {
		return;
	}

	$message = $trigger->render_prompt( $payload );
	$args    = array(
		'trigger_id' => $trigger->get_id(),
		'agent'      => $trigger->get_agent_slug(),
		'message'    => $message,
		'session_id' => $trigger->get_session_id(),
		'payload'    => $payload,
	);

	if ( ! function_exists( 'wp_schedule_single_event' ) || ! wp_schedule_single_event( time(), WP_AGENT_EVENT_TRIGGER_RUN_HOOK, array( $args ) ) ) {
		do_action(
			'wp_agent_event_trigger_dispatch_failed',
			'schedule_failed',
			array(
				'trigger_id' => $trigger->get_id(),
				'hook_name'  => $trigger->get_hook_name(),
			)
		);
		return;
	}

	do_action( 'wp_agent_event_trigger_dispatched', $trigger, $args );
}
