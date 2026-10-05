<?php
/**
 * Inbound message idempotency store contract.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Message_Idempotency_Store {

	/**
	 * Whether an inbound external message has already been processed.
	 *
	 * @param string $provider   External provider or connector scope.
	 * @param string $message_id Opaque provider message id.
	 * @return bool True when the message was already marked seen.
	 */
	public function seen( string $provider, string $message_id ): bool;

	/**
	 * Mark an inbound external message as processed for a bounded time.
	 *
	 * @param string $provider   External provider or connector scope.
	 * @param string $message_id Opaque provider message id.
	 * @param int    $ttl        Time-to-live in seconds.
	 */
	public function mark_seen( string $provider, string $message_id, int $ttl ): void;

	/**
	 * Remove a processed marker, primarily for tests and manual recovery.
	 *
	 * @param string $provider   External provider or connector scope.
	 * @param string $message_id Opaque provider message id.
	 */
	public function forget( string $provider, string $message_id ): void;
}
