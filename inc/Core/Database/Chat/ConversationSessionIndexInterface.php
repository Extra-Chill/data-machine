<?php
/**
 * Conversation session index contract.
 *
 * Covers the paginated session switcher/index surface. Backends that can
 * list sessions separately from transcript storage can implement this
 * contract independently.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

interface ConversationSessionIndexInterface {

	/**
	 * Fetch a full conversation session by ID.
	 *
	 * @param string $session_id Conversation session ID.
	 * @return array<string, mixed>|null Session payload, or null when missing.
	 */
	public function get_session( string $session_id ): ?array;

	/**
	 * List sessions for a user with pagination and optional filtering.
	 *
	 * Returned entries are summary rows intended for the session switcher
	 * (session_id, title, context/mode, first_message, message_count,
	 * unread_count, agent_id, agent_slug, agent_name, created_at,
	 * updated_at). Implementations MUST include agent metadata so the
	 * switcher UI can render without an N+1 lookup.
	 *
	 * @param int         $user_id  WordPress user ID.
	 * @param int         $limit    Max rows to return (1-100).
	 * @param int         $offset   Pagination offset.
	 * @param string|null $context  Optional context filter.
	 * @param int|null    $agent_id Optional agent filter (null = all agents).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_user_sessions( int $user_id, int $limit = 20, int $offset = 0, ?string $context = null, ?int $agent_id = null ): array;

	/**
	 * Total session count for a user, honoring optional filters.
	 *
	 * @param int         $user_id  WordPress user ID.
	 * @param string|null $context  Optional context filter.
	 * @param int|null    $agent_id Optional agent filter (null = all agents).
	 * @return int
	 */
	public function get_user_session_count( int $user_id, ?string $context = null, ?int $agent_id = null ): int;

	/** List sessions through the ability-facing workspace and owner boundary. */
	public function get_user_sessions_for_workspace( WP_Agent_Workspace_Scope $workspace, int $user_id, int $limit = 20, int $offset = 0, ?string $context = null, ?int $agent_id = null, ?array $transcript_owner = null ): array;

	/** Count sessions through the ability-facing workspace and owner boundary. */
	public function get_user_session_count_for_workspace( WP_Agent_Workspace_Scope $workspace, int $user_id, ?string $context = null, ?int $agent_id = null, ?array $transcript_owner = null ): int;

	/** Read a session through the ability-facing workspace and owner boundary. */
	public function get_session_for_transcript_owner( WP_Agent_Workspace_Scope $workspace, int $user_id, array $owner, string $session_id ): ?array;

	/** Delete a session through the ability-facing workspace and owner boundary. */
	public function delete_session_for_transcript_owner( WP_Agent_Workspace_Scope $workspace, int $user_id, array $owner, string $session_id ): bool;
}
