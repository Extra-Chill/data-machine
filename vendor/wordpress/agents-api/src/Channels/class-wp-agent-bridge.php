<?php
/**
 * Remote bridge facade for out-of-process chat clients.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Bridge {

	private static ?WP_Agent_Bridge_Store $store = null;

	public static function set_store( ?WP_Agent_Bridge_Store $store ): void {
		self::$store = $store;
	}

	public static function store(): WP_Agent_Bridge_Store {
		if ( null === self::$store ) {
			self::$store = new WP_Agent_Option_Bridge_Store();
		}
		return self::$store;
	}

	/**
	 * Register or update a remote bridge client.
	 *
	 * @param string              $client_id    Stable remote bridge client id.
	 * @param string|null         $callback_url Optional callback URL for best-effort delivery.
	 * @param array<string,mixed> $context      Opaque client metadata.
	 * @param string|null         $connector_id Optional Core Connectors API connector id.
	 * @return WP_Agent_Bridge_Client Registered client.
	 */
	public static function register_client( string $client_id, ?string $callback_url = null, array $context = array(), ?string $connector_id = null ): WP_Agent_Bridge_Client {
		$client = new WP_Agent_Bridge_Client( $client_id, $connector_id, $callback_url, $context );
		self::store()->register_client( $client );
		return $client;
	}

	public static function get_client( string $client_id ): ?WP_Agent_Bridge_Client {
		return self::store()->get_client( $client_id );
	}

	/**
	 * Queue an outbound bridge message. Items remain pending until acknowledged.
	 *
	 * @param array<string,mixed> $args Queue item fields.
	 * @return WP_Agent_Bridge_Queue_Item Queued item.
	 */
	public static function enqueue( array $args ): WP_Agent_Bridge_Queue_Item {
		$item = new WP_Agent_Bridge_Queue_Item( $args );
		return self::store()->enqueue( $item );
	}

	/**
	 * Return pending queue items for a bridge client.
	 *
	 * @param string   $client_id   Bridge client id.
	 * @param int      $limit       Maximum items to return.
	 * @param string[] $session_ids Optional session id filter.
	 * @return WP_Agent_Bridge_Queue_Item[] Pending items.
	 */
	public static function pending( string $client_id, int $limit = 25, array $session_ids = array() ): array {
		return self::store()->pending( $client_id, $limit, $session_ids );
	}

	/**
	 * Acknowledge accepted queue items.
	 *
	 * @param string   $client_id Bridge client id.
	 * @param string[] $queue_ids Queue ids to acknowledge.
	 * @return int Number of acknowledged items.
	 */
	public static function ack( string $client_id, array $queue_ids ): int {
		return self::store()->ack( $client_id, $queue_ids );
	}
}
