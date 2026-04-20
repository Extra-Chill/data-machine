<?php
/**
 * Conversation Store Interface
 *
 * Single seam between chat session operations and the underlying
 * persistence backend. Default implementation ({@see Chat}) preserves
 * today's MySQL-table behavior. Consumers can swap in an alternate
 * store via the `datamachine_conversation_store` filter (e.g. an
 * AI Framework Conversation_Storage shim on managed hosts like
 * WordPress.com).
 *
 * The five Chat Session abilities (list / get / create / delete /
 * mark-read) and the Chat Orchestrator all route session I/O through
 * this seam. The DM chat UI and REST surface are unaffected — they
 * consume the ability contracts, not the store directly.
 *
 * Implementations are responsible for:
 * - normalizing messages on read to Data Machine message shape
 *   (see docs/development/hooks/core-filters.md#message-shape-contract);
 * - preserving per-session identity via UUIDv4 session IDs;
 * - honoring the `(user_id, agent_id, context)` triple when listing.
 *
 * Session-scope policy (ownership checks, token resolution, agent
 * adoption, title generation) stays in the higher-level callers
 * ({@see \DataMachine\Api\Chat\ChatOrchestrator},
 * {@see \DataMachine\Abilities\Chat\ChatSessionHelpers}). The store
 * is the dumb persistence layer underneath.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

interface ConversationStoreInterface {

	/**
	 * Create a new chat session and return its ID.
	 *
	 * @param int    $user_id  WordPress user ID owning the session.
	 * @param int    $agent_id Agent ID (0 = legacy agent-less session).
	 * @param array  $metadata Arbitrary session metadata (JSON-serializable).
	 * @param string $context  Execution context ('chat', 'pipeline', 'system').
	 * @return string Session ID (UUIDv4), or empty string on failure.
	 */
	public function create_session( int $user_id, int $agent_id = 0, array $metadata = array(), string $context = 'chat' ): string;

	/**
	 * Retrieve a session by ID.
	 *
	 * Returns the session as an associative array with keys:
	 * session_id, user_id, agent_id, title, messages (decoded array),
	 * metadata (decoded array), provider, model, context, created_at,
	 * updated_at, last_read_at, expires_at.
	 *
	 * @param string $session_id Session UUID.
	 * @return array|null Session data or null if not found.
	 */
	public function get_session( string $session_id ): ?array;

	/**
	 * Replace a session's messages + metadata.
	 *
	 * @param string $session_id Session UUID.
	 * @param array  $messages   Complete messages array (not a delta).
	 * @param array  $metadata   Updated metadata.
	 * @param string $provider   Optional AI provider identifier.
	 * @param string $model      Optional AI model identifier.
	 * @return bool True on success.
	 */
	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '' ): bool;

	/**
	 * Delete a session by ID. Idempotent.
	 *
	 * @param string $session_id Session UUID.
	 * @return bool True on success.
	 */
	public function delete_session( string $session_id ): bool;

	/**
	 * List sessions for a user with pagination and optional filtering.
	 *
	 * Returned entries are summary rows intended for the session switcher
	 * (session_id, title, context, first_message, message_count,
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

	/**
	 * Find a recent pending session for deduplication after request timeouts.
	 *
	 * Returns the most recent session that belongs to $user_id, was created
	 * within $seconds, and is either empty or actively processing. Used by
	 * the orchestrator to avoid duplicate sessions when a Cloudflare timeout
	 * triggers a client retry while PHP keeps executing.
	 *
	 * @param int      $user_id  WordPress user ID.
	 * @param int      $seconds  Lookback window (default 600 = 10 minutes).
	 * @param string   $context  Context filter.
	 * @param int|null $token_id Optional token ID for login-scoped dedup.
	 * @return array|null Session data or null if none.
	 */
	public function get_recent_pending_session( int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array;

	/**
	 * Set a session's display title.
	 *
	 * @param string $session_id Session UUID.
	 * @param string $title      New title.
	 * @return bool True on success.
	 */
	public function update_title( string $session_id, string $title ): bool;

	/**
	 * Count unread assistant messages in a decoded messages array.
	 *
	 * Pure derivation from a messages array — default impl is stateless
	 * and has no backend I/O. Included on the interface so consumers get
	 * the same unread semantics regardless of backend.
	 *
	 * @param array       $messages     Decoded messages array.
	 * @param string|null $last_read_at MySQL datetime of last read, or null.
	 * @return int
	 */
	public function count_unread( array $messages, ?string $last_read_at ): int;

	/**
	 * Mark a session as read (updates last_read_at).
	 *
	 * @param string $session_id Session UUID.
	 * @param int    $user_id    User ID for ownership scope.
	 * @return string|false New last_read_at MySQL datetime, or false on failure.
	 */
	public function mark_session_read( string $session_id, int $user_id );

	/**
	 * Delete sessions whose `expires_at` has passed.
	 *
	 * @return int Number of sessions deleted.
	 */
	public function cleanup_expired_sessions(): int;

	/**
	 * Delete sessions older than the retention window.
	 *
	 * @param int $retention_days Days to retain sessions (by updated_at).
	 * @return int Number of sessions deleted.
	 */
	public function cleanup_old_sessions( int $retention_days ): int;

	/**
	 * Delete sessions that were created but never received a message.
	 *
	 * Orphaned sessions are the fallout from request timeouts that left
	 * empty rows behind. This cleanup keeps the switcher tidy.
	 *
	 * @param int $hours Age threshold in hours (default 1).
	 * @return int Number of sessions deleted.
	 */
	public function cleanup_orphaned_sessions( int $hours = 1 ): int;

	/**
	 * List lightweight session summaries created on the given date.
	 *
	 * Returns rows with `{session_id, title, context, created_at}`.
	 * Used by the Daily Memory Task to summarize a day's chat activity
	 * without loading the full messages array.
	 *
	 * Implementations determine their own date comparison semantics.
	 * The default MySQL store uses `DATE(created_at) = $date` on the
	 * stored timestamp (MySQL-server timezone, which in a WordPress
	 * install is typically UTC for Data Machine DATETIME columns).
	 *
	 * @param string $date Date string in `Y-m-d` format.
	 * @return array<int, array{session_id: string, title: string|null, context: string, created_at: string}>
	 */
	public function list_sessions_for_day( string $date ): array;

	/**
	 * Report storage metrics for the retention CLI.
	 *
	 * Returns `['rows' => int, 'size_mb' => string]` for the default
	 * MySQL store. Stores that cannot report byte size (e.g. an external
	 * API-backed store) return `size_mb => '0.0'`. Stores that cannot
	 * report rows either return `null` to opt out of the metrics table.
	 *
	 * The CLI displays the "Chat sessions" row only when this method
	 * returns a non-null value, so the metrics table stays meaningful
	 * regardless of backend.
	 *
	 * @return array{rows: int, size_mb: string}|null
	 */
	public function get_storage_metrics(): ?array;
}
