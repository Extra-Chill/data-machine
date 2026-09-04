<?php
/**
 * Channel session mapping store contract.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Channel_Session_Store {

	/**
	 * Read the mapped agent session id for an external conversation.
	 *
	 * @param string $connector_id             Connector or channel instance id.
	 * @param string $external_conversation_id Opaque external conversation id.
	 * @param string $agent_slug               Agent slug or empty string for runtime-resolved agents.
	 * @return string|null Session id, or null when no mapping exists.
	 */
	public function get( string $connector_id, string $external_conversation_id, string $agent_slug = '' ): ?string;

	/**
	 * Store the mapped agent session id for an external conversation.
	 *
	 * @param string $connector_id             Connector or channel instance id.
	 * @param string $external_conversation_id Opaque external conversation id.
	 * @param string $session_id               Agent session id.
	 * @param string $agent_slug               Agent slug or empty string for runtime-resolved agents.
	 */
	public function set( string $connector_id, string $external_conversation_id, string $session_id, string $agent_slug = '' ): void;

	/**
	 * Delete the mapped session id for an external conversation.
	 *
	 * @param string $connector_id             Connector or channel instance id.
	 * @param string $external_conversation_id Opaque external conversation id.
	 * @param string $agent_slug               Agent slug or empty string for runtime-resolved agents.
	 */
	public function delete( string $connector_id, string $external_conversation_id, string $agent_slug = '' ): void;
}
