<?php
/**
 * Atomic run-control store capability.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( __NAMESPACE__ . '\\WP_Agent_Run_Control_Store' ) ) {
	require_once __DIR__ . '/interface-wp-agent-run-control-store.php';
}

/**
 * Optional capability for serializing read-modify-write state mutations.
 */
interface WP_Agent_Atomic_Run_Control_Store extends WP_Agent_Run_Control_Store {

	/**
	 * @param callable(array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>,idempotency?:array<string,string>}):array{state:array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>,idempotency?:array<string,string>},result:mixed} $mutation State mutation.
	 * @return mixed Mutation result.
	 */
	public function mutate_state( string $store_key, callable $mutation ): mixed;
}
