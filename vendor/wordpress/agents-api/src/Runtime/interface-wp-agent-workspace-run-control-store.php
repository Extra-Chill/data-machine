<?php
/**
 * Workspace-aware run-control store contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( WP_Agent_Run_Control_Store::class ) ) {
	require_once __DIR__ . '/interface-wp-agent-run-control-store.php';
}

/**
 * Optional store capability for state shared by a canonical workspace.
 */
interface WP_Agent_Workspace_Run_Control_Store extends WP_Agent_Run_Control_Store {

	/**
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	public function get_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace ): array;

	/**
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state State envelope.
	 */
	public function save_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, array $state ): void;
}
