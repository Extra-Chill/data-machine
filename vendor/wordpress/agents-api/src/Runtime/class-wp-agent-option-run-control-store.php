<?php
/**
 * Option-backed run-control store.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Persists run-control state in WordPress options.
 */
class WP_Agent_Option_Run_Control_Store implements WP_Agent_Run_Control_Store {

	/**
	 * @param string $store_key Store key.
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	public function get_state( string $store_key ): array {
		$state = function_exists( 'get_option' ) ? get_option( $store_key, array() ) : array();
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'runs'   => $this->stored_runs( $state['runs'] ?? array() ),
			'queues' => $this->stored_queues( $state['queues'] ?? array() ),
			'events' => $this->stored_queues( $state['events'] ?? array() ),
		);
	}

	/**
	 * @param string $store_key Store key.
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state State envelope.
	 */
	public function save_state( string $store_key, array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( $store_key, $state, false );
		}
	}

	/**
	 * @param mixed $runs Raw stored runs.
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_runs( mixed $runs ): array {
		if ( ! is_array( $runs ) ) {
			return array();
		}

		$stored = array();
		foreach ( $runs as $run_id => $run ) {
			if ( is_string( $run_id ) && is_array( $run ) ) {
				$stored[ $run_id ] = $this->assoc_array( $run );
			}
		}

		return $stored;
	}

	/**
	 * @param mixed $queues Raw stored queues.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function stored_queues( mixed $queues ): array {
		if ( ! is_array( $queues ) ) {
			return array();
		}

		$stored = array();
		foreach ( $queues as $scope => $items ) {
			if ( ! is_string( $scope ) || ! is_array( $items ) ) {
				continue;
			}

			$stored[ $scope ] = array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					$stored[ $scope ][] = $this->assoc_array( $item );
				}
			}
		}

		return $stored;
	}

	/**
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private function assoc_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}
		return $result;
	}
}
