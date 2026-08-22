<?php
/**
 * Atomic workspace run-control store capability.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( __NAMESPACE__ . '\\WP_Agent_Atomic_Run_Control_Store' ) ) {
	require_once __DIR__ . '/interface-wp-agent-atomic-run-control-store.php';
}
if ( ! interface_exists( __NAMESPACE__ . '\\WP_Agent_Workspace_Run_Control_Store' ) ) {
	require_once __DIR__ . '/interface-wp-agent-workspace-run-control-store.php';
}

/**
 * Optional capability for serializing workspace state mutations.
 */
interface WP_Agent_Atomic_Workspace_Run_Control_Store extends WP_Agent_Atomic_Run_Control_Store, WP_Agent_Workspace_Run_Control_Store {

	/**
	 * @param callable(array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}):array{state:array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>},result:mixed} $mutation State mutation.
	 * @return mixed Mutation result.
	 */
	public function mutate_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, callable $mutation ): mixed;
}
