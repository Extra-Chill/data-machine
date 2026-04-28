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

defined( 'ABSPATH' ) || exit;

interface ConversationSessionIndexInterface {

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
}
