<?php
/**
 * Approval memory store contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Host-owned store for remembered per-tool approval decisions.
 */
interface WP_Agent_Approval_Memory_Store {

	/**
	 * Remember a resolved action policy for an agent/tool in a workspace.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace scope.
	 * @param int                      $user_id   WordPress user ID.
	 * @param string                   $agent_id  Agent slug or ID.
	 * @param string                   $tool_name Tool name.
	 * @param string                   $policy    One of direct, preview, forbidden.
	 * @return void
	 */
	public function remember( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name, string $policy ): void;

	/**
	 * Recall a remembered action policy for an agent/tool in a workspace.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace scope.
	 * @param int                      $user_id   WordPress user ID.
	 * @param string                   $agent_id  Agent slug or ID.
	 * @param string                   $tool_name Tool name.
	 * @return string|null One of direct, preview, forbidden; null when no memory exists.
	 */
	public function recall( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name ): ?string;

	/**
	 * Forget a remembered action policy for an agent/tool in a workspace.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace scope.
	 * @param int                      $user_id   WordPress user ID.
	 * @param string                   $agent_id  Agent slug or ID.
	 * @param string                   $tool_name Tool name.
	 * @return void
	 */
	public function forget( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name ): void;
}
