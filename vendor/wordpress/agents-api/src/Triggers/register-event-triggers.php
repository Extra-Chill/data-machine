<?php
/**
 * Global helpers for the in-memory event trigger registry.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_register_event_trigger' ) ) {
	/**
	 * Register a code-defined event trigger.
	 *
	 * @param string              $id   Unique trigger slug.
	 * @param array<string,mixed> $args Trigger arguments.
	 * @return AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger|WP_Error
	 */
	function wp_register_event_trigger( string $id, array $args ) {
		return AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger_Registry::register( $id, $args );
	}
}

if ( ! function_exists( 'wp_get_event_trigger' ) ) {
	function wp_get_event_trigger( string $trigger_id ): ?AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger {
		return AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger_Registry::find( $trigger_id );
	}
}

if ( ! function_exists( 'wp_get_event_triggers' ) ) {
	/** @return AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger[] */
	function wp_get_event_triggers(): array {
		return AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger_Registry::all();
	}
}

if ( ! function_exists( 'wp_unregister_event_trigger' ) ) {
	/** @return true|WP_Error */
	function wp_unregister_event_trigger( string $trigger_id ) {
		return AgentsAPI\AI\Triggers\WP_Agent_Event_Trigger_Registry::unregister( $trigger_id );
	}
}
