<?php
/**
 * Transient-backed inbound message idempotency store.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Transient_Message_Idempotency_Store implements WP_Agent_Message_Idempotency_Store {

	public function seen( string $provider, string $message_id ): bool {
		$key = $this->storage_key( $provider, $message_id );
		if ( null === $key ) {
			return false;
		}

		return false !== get_transient( $key );
	}

	public function mark_seen( string $provider, string $message_id, int $ttl ): void {
		$key = $this->storage_key( $provider, $message_id );
		if ( null === $key || $ttl < 1 ) {
			return;
		}

		set_transient( $key, '1', $ttl );
	}

	public function forget( string $provider, string $message_id ): void {
		$key = $this->storage_key( $provider, $message_id );
		if ( null === $key ) {
			return;
		}

		delete_transient( $key );
	}

	/**
	 * Build a stable transient key for a provider/message tuple.
	 *
	 * @param string $provider
	 * @param string $message_id
	 * @return string|null
	 */
	public function storage_key( string $provider, string $message_id ): ?string {
		$provider   = $this->normalize_provider( $provider );
		$message_id = trim( $message_id );

		if ( '' === $provider || '' === $message_id ) {
			return null;
		}

		return 'wp_agent_message_seen_' . md5( $provider . ':' . $message_id );
	}

	private function normalize_provider( string $provider ): string {
		return trim( strtolower( str_replace( '_', '-', $provider ) ) );
	}
}
