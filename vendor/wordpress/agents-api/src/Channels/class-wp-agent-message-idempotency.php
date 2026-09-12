<?php
/**
 * Inbound message idempotency facade.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Message_Idempotency {

	private static ?WP_Agent_Message_Idempotency_Store $store = null;

	/**
	 * Replace the backing store. Pass null to restore the transient default.
	 *
	 * @param WP_Agent_Message_Idempotency_Store|null $store Store implementation.
	 */
	public static function set_store( ?WP_Agent_Message_Idempotency_Store $store ): void {
		self::$store = $store;
	}

	/**
	 * Return the active backing store.
	 *
	 * @return WP_Agent_Message_Idempotency_Store
	 */
	public static function store(): WP_Agent_Message_Idempotency_Store {
		if ( null === self::$store ) {
			self::$store = new WP_Agent_Transient_Message_Idempotency_Store();
		}
		return self::$store;
	}

	/**
	 * Whether an inbound external message has already been processed.
	 *
	 * @param string $provider   External provider or connector scope.
	 * @param string $message_id Opaque provider message id.
	 * @return bool True when the message was already marked seen.
	 */
	public static function seen( string $provider, string $message_id ): bool {
		return self::store()->seen( $provider, $message_id );
	}

	/**
	 * Mark an inbound external message as processed for a bounded time.
	 *
	 * @param string $provider   External provider or connector scope.
	 * @param string $message_id Opaque provider message id.
	 * @param int    $ttl        Time-to-live in seconds.
	 */
	public static function mark_seen( string $provider, string $message_id, int $ttl ): void {
		self::store()->mark_seen( $provider, $message_id, $ttl );
	}

	/**
	 * Remove a processed marker, primarily for tests and manual recovery.
	 *
	 * @param string $provider   External provider or connector scope.
	 * @param string $message_id Opaque provider message id.
	 */
	public static function forget( string $provider, string $message_id ): void {
		self::store()->forget( $provider, $message_id );
	}
}
