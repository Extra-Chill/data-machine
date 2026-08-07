<?php
/**
 * Option-backed remote bridge store.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Option_Bridge_Store implements WP_Agent_Bridge_Store {

	private const CLIENTS_OPTION = 'wp_agent_bridge_clients';
	private const QUEUE_OPTION   = 'wp_agent_bridge_queue';

	public function register_client( WP_Agent_Bridge_Client $client ): void {
		$clients                       = $this->read_clients();
		$clients[ $client->client_id ] = $client->to_array();
		update_option( self::CLIENTS_OPTION, $clients, false );
	}

	public function get_client( string $client_id ): ?WP_Agent_Bridge_Client {
		$client_id = $this->normalize_id( $client_id );
		$clients   = $this->read_clients();
		if ( ! isset( $clients[ $client_id ] ) || ! is_array( $clients[ $client_id ] ) ) {
			return null;
		}

		return WP_Agent_Bridge_Client::from_array( $this->string_keyed_array( $clients[ $client_id ] ) );
	}

	public function enqueue( WP_Agent_Bridge_Queue_Item $item ): WP_Agent_Bridge_Queue_Item {
		$queue = $this->read_queue();

		if ( isset( $queue[ $item->queue_id ] ) && is_array( $queue[ $item->queue_id ] ) ) {
			$existing = WP_Agent_Bridge_Queue_Item::from_array( $this->string_keyed_array( $queue[ $item->queue_id ] ) );
			if ( $existing->client_id !== $item->client_id ) {
				throw new \InvalidArgumentException( 'Cannot overwrite a queue item owned by another client.' );
			}
		}

		$queue[ $item->queue_id ] = $item->to_array();
		update_option( self::QUEUE_OPTION, $queue, false );
		return $item;
	}

	public function pending( string $client_id, int $limit = 25, array $session_ids = array() ): array {
		$client_id   = $this->normalize_id( $client_id );
		$limit       = max( 1, $limit );
		$session_ids = array_values( array_filter( array_map( 'strval', $session_ids ) ) );
		$items       = array();

		foreach ( $this->read_queue() as $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$item = WP_Agent_Bridge_Queue_Item::from_array( $this->string_keyed_array( $data ) );
			if ( $item->client_id !== $client_id ) {
				continue;
			}

			if ( ! empty( $session_ids ) && ( null === $item->session_id || ! in_array( $item->session_id, $session_ids, true ) ) ) {
				continue;
			}

			$items[] = $item;
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	public function ack( string $client_id, array $queue_ids ): int {
		$client_id = $this->normalize_id( $client_id );
		$queue_ids = array_fill_keys( array_map( 'strval', $queue_ids ), true );
		$queue     = $this->read_queue();
		$acked     = 0;

		foreach ( $queue as $queue_id => $data ) {
			if ( ! isset( $queue_ids[ (string) $queue_id ] ) || ! is_array( $data ) ) {
				continue;
			}

			$item = WP_Agent_Bridge_Queue_Item::from_array( $this->string_keyed_array( $data ) );
			if ( $item->client_id !== $client_id ) {
				continue;
			}

			unset( $queue[ $queue_id ] );
			++$acked;
		}

		if ( $acked > 0 ) {
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		return $acked;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_clients(): array {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $this->string_keyed_array( $clients ) : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_queue(): array {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? $this->string_keyed_array( $queue ) : array();
	}

	/**
	 * @param array<mixed> $data
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $data ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}

	private function normalize_id( string $value ): string {
		$value = trim( strtolower( str_replace( '_', '-', $value ) ) );
		$value = preg_replace( '/[^a-z0-9-]+/', '-', $value );
		return trim( (string) $value, '-' );
	}
}
