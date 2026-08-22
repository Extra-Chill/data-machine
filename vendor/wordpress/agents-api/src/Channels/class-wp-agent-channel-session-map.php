<?php
/**
 * Channel session mapping facade.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Channel_Session_Map {

	private static ?WP_Agent_Channel_Session_Store $store = null;

	/**
	 * Replace the backing store. Pass null to restore the option-backed default.
	 *
	 * @param WP_Agent_Channel_Session_Store|null $store Store implementation.
	 */
	public static function set_store( ?WP_Agent_Channel_Session_Store $store ): void {
		self::$store = $store;
	}

	/**
	 * Return the active backing store.
	 *
	 * @return WP_Agent_Channel_Session_Store
	 */
	public static function store(): WP_Agent_Channel_Session_Store {
		if ( null === self::$store ) {
			self::$store = new WP_Agent_Option_Channel_Session_Store();
		}
		return self::$store;
	}

	/**
	 * Read the mapped agent session id for an external conversation.
	 *
	 * @param string $connector_id             Connector or channel instance id.
	 * @param string $external_conversation_id Opaque external conversation id.
	 * @param string $agent_slug               Agent slug or empty string for runtime-resolved agents.
	 * @return string|null Session id, or null when no mapping exists.
	 */
	public static function get( string $connector_id, string $external_conversation_id, string $agent_slug = '' ): ?string {
		return self::store()->get( $connector_id, $external_conversation_id, $agent_slug );
	}

	/**
	 * Store the mapped agent session id for an external conversation.
	 *
	 * @param string $connector_id             Connector or channel instance id.
	 * @param string $external_conversation_id Opaque external conversation id.
	 * @param string $session_id               Agent session id.
	 * @param string $agent_slug               Agent slug or empty string for runtime-resolved agents.
	 */
	public static function set( string $connector_id, string $external_conversation_id, string $session_id, string $agent_slug = '' ): void {
		self::store()->set( $connector_id, $external_conversation_id, $session_id, $agent_slug );
	}

	/**
	 * Delete the mapped session id for an external conversation.
	 *
	 * @param string $connector_id             Connector or channel instance id.
	 * @param string $external_conversation_id Opaque external conversation id.
	 * @param string $agent_slug               Agent slug or empty string for runtime-resolved agents.
	 */
	public static function delete( string $connector_id, string $external_conversation_id, string $agent_slug = '' ): void {
		self::store()->delete( $connector_id, $external_conversation_id, $agent_slug );
	}
}
