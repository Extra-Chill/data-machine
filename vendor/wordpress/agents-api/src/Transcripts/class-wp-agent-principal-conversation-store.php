<?php
/**
 * Principal-owned agent conversation transcript persistence contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Optional principal-owner-aware transcript persistence contract.
 *
 * Implement this in addition to WP_Agent_Conversation_Store when a backend can
 * persist sessions for non-user principals. `$owner` is the canonical shape
 * returned by WP_Agent_Execution_Principal::conversation_owner():
 * `array( 'type' => 'user|audience|token|system', 'key' => '<opaque stable key>' )`.
 * The owner key is opaque to Agents API. Runtime adapters may hash or otherwise
 * protect it at rest, but list/get/create/delete semantics must remain scoped to
 * the `(workspace, owner_type, owner_key)` tuple.
 *
 * Legacy WP_Agent_Conversation_Store implementations remain valid for user-owned
 * sessions through the int user ID methods above.
 */
interface WP_Agent_Principal_Conversation_Store extends WP_Agent_Conversation_Store {

	/**
	 * Create a new conversation transcript session for a canonical principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope     $workspace  Workspace owning the session.
	 * @param array{type:string,key:string} $owner     Canonical principal owner.
	 * @param string                       $agent_slug Registered agent slug, or empty string for agent-less sessions.
	 * @param array<mixed>                        $metadata   Arbitrary session metadata (JSON-serializable).
	 * @param string                       $context    Execution mode ('chat', 'pipeline', 'system').
	 * @return string Session ID (UUIDv4), or empty string on failure.
	 */
	public function create_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string;

	/**
	 * List transcript sessions for one workspace/principal-owner pair.
	 *
	 * @param WP_Agent_Workspace_Scope     $workspace Workspace owning the sessions.
	 * @param array{type:string,key:string} $owner    Canonical principal owner.
	 * @param array<mixed>                        $args      Optional host-supported filters/pagination.
	 * @return array<int,array<string,mixed>> Session rows.
	 */
	public function list_sessions_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, array $args = array() ): array;

	/**
	 * Find a recent pending session for principal-owner scoped deduplication.
	 *
	 * @param WP_Agent_Workspace_Scope     $workspace Workspace owning the session.
	 * @param array{type:string,key:string} $owner    Canonical principal owner.
	 * @param int                          $seconds  Lookback window.
	 * @param string                       $context  Context filter.
	 * @param int|null                     $token_id Optional token ID for token-scoped dedup.
	 * @return array<string,mixed>|null Session data or null if none.
	 */
	public function get_recent_pending_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array;
}
