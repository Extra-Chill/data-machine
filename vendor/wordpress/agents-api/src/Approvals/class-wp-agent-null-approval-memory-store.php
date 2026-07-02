<?php
/**
 * Null approval memory store.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Default transparent store for hosts that do not persist approval memories.
 */
final class WP_Agent_Null_Approval_Memory_Store implements WP_Agent_Approval_Memory_Store {

	/**
	 * @inheritDoc
	 */
	public function remember( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name, string $policy ): void {
		unset( $workspace, $user_id, $agent_id, $tool_name, $policy );
	}

	/**
	 * @inheritDoc
	 */
	public function recall( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name ): ?string {
		unset( $workspace, $user_id, $agent_id, $tool_name );
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function forget( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name ): void {
		unset( $workspace, $user_id, $agent_id, $tool_name );
	}
}
