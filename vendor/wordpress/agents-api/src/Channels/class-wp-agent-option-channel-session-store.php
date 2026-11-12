<?php
/**
 * Option-backed channel session mapping store.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Option_Channel_Session_Store implements WP_Agent_Channel_Session_Store {

	public function get( string $connector_id, string $external_conversation_id, string $agent_slug = '' ): ?string {
		$value = get_option( $this->storage_key( $connector_id, $external_conversation_id, $agent_slug ), '' );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	public function set( string $connector_id, string $external_conversation_id, string $session_id, string $agent_slug = '' ): void {
		update_option( $this->storage_key( $connector_id, $external_conversation_id, $agent_slug ), $session_id, false );
	}

	public function delete( string $connector_id, string $external_conversation_id, string $agent_slug = '' ): void {
		delete_option( $this->storage_key( $connector_id, $external_conversation_id, $agent_slug ) );
	}

	/**
	 * Build the option key for a connector/conversation/agent tuple.
	 *
	 * @param string $connector_id
	 * @param string $external_conversation_id
	 * @param string $agent_slug
	 * @return string
	 */
	public function storage_key( string $connector_id, string $external_conversation_id, string $agent_slug = '' ): string {
		return 'wp_agent_channel_session_' . md5(
			implode(
				':',
				array(
					$this->normalize_part( $connector_id ),
					$external_conversation_id,
					$this->normalize_part( $agent_slug ),
				)
			)
		);
	}

	private function normalize_part( string $value ): string {
		return trim( strtolower( str_replace( '_', '-', $value ) ) );
	}
}
