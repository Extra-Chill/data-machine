<?php
/**
 * Remote bridge store contract.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Bridge_Store {

	public function register_client( WP_Agent_Bridge_Client $client ): void;

	public function get_client( string $client_id ): ?WP_Agent_Bridge_Client;

	public function enqueue( WP_Agent_Bridge_Queue_Item $item ): WP_Agent_Bridge_Queue_Item;

	/**
	 * Return pending queue items for a bridge client.
	 *
	 * @param string   $client_id   Bridge client id.
	 * @param int      $limit       Maximum items to return.
	 * @param string[] $session_ids Optional session id filter.
	 * @return WP_Agent_Bridge_Queue_Item[] Pending items.
	 */
	public function pending( string $client_id, int $limit = 25, array $session_ids = array() ): array;

	/**
	 * Acknowledge accepted queue items and remove them from pending delivery.
	 *
	 * @param string   $client_id Bridge client id.
	 * @param string[] $queue_ids Queue ids to acknowledge.
	 * @return int Number of acknowledged items.
	 */
	public function ack( string $client_id, array $queue_ids ): int;
}
