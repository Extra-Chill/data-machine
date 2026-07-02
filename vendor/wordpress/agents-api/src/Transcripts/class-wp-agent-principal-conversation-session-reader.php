<?php
/**
 * Principal-owned conversation session read contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Optional owner-aware single-session read contract.
 *
 * Stores that keep opaque owner keys hashed can implement this to verify a
 * session ID belongs to a principal without exposing the raw owner key in
 * generic session rows. Stores that expose `owner_type` and `owner_key` in their
 * generic rows can skip this interface; the canonical abilities will verify
 * ownership from those row fields.
 */
interface WP_Agent_Principal_Conversation_Session_Reader extends WP_Agent_Principal_Conversation_Store {

	/**
	 * Read one transcript session for a canonical principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace  Workspace owning the session.
	 * @param array{type:string,key:string} $owner      Canonical principal owner.
	 * @param string                        $session_id Session ID.
	 * @return array<string,mixed>|null Session row, or null when missing/not owned.
	 */
	public function get_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $session_id ): ?array;
}
