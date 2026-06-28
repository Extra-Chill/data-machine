<?php
/**
 * Generic run-control store contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Persists addressable run-control state.
 */
interface WP_Agent_Run_Control_Store {

	/**
	 * Read the state for a store key.
	 *
	 * @param string $store_key Store key.
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	public function get_state( string $store_key ): array;

	/**
	 * Save the state for a store key.
	 *
	 * @param string $store_key Store key.
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state State envelope.
	 */
	public function save_state( string $store_key, array $state ): void;
}
