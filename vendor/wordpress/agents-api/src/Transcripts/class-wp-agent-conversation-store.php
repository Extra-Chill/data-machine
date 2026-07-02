<?php
/**
 * Agent conversation transcript persistence contract.
 *
 * This is the narrow, generic storage seam for complete conversation
 * transcripts. It covers session row creation, transcript reads/writes,
 * deletion, retry deduplication, and the stored display title that belongs
 * to a transcript row. It deliberately does not include chat UI listing,
 * read-state, retention scheduling, or reporting/metrics responsibilities.
 *
 * The canonical session row is storage-neutral and runtime-neutral. Stores can
 * back it with posts, custom tables, external services, files, or memory, but
 * callers should be able to rely on these generic keys when available:
 *
 * - `session_id` (string): stable opaque session identifier.
 * - `workspace_type` / `workspace_id` (string): workspace scope.
 * - `owner_type` / `owner_key` (string): canonical principal owner. Legacy
 *   user-only stores may expose only `user_id`; principal-aware stores should
 *   expose owner keys or implement `WP_Agent_Principal_Conversation_Session_Reader`.
 * - `user_id` (int): WordPress user id for user-owned sessions, otherwise 0.
 * - `agent_slug` (string): registered agent slug, not a product storage id.
 * - `title` (string), `messages` (array), `metadata` (array), `context` (string).
 * - `provider`, `model`, `provider_response_id` (string|null): provider
 *   continuity for transcript/run resumption.
 * - `created_at`, `updated_at`, `last_read_at`, `expires_at` (string|null):
 *   timestamps in an implementation-documented format, preferably ISO-8601.
 *
 * Product-specific metadata belongs in `metadata` under a namespaced key. Generic
 * clients must treat unknown metadata and extra top-level fields as optional
 * extensions.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Conversation_Store {

	/**
	 * Create a new conversation transcript session and return its ID.
	 *
	 * `$agent_slug` is the registered agent slug used by `wp_register_agent()`,
	 * `wp_get_agent()`, and related registry functions. Consumers that materialize
	 * agents as posts may store post IDs in their concrete backend, but the generic
	 * transcript contract keys runtime agent identity by slug.
	 *
	 * `$metadata` is caller/runtime metadata for the session row. Store adapters
	 * should preserve JSON-serializable values and keep product-specific fields
	 * namespaced inside the array rather than promoting them into the generic row
	 * schema.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the session.
	 * @param int                      $user_id   WordPress user ID owning the session.
	 * @param string                   $agent_slug Registered agent slug, or empty string for agent-less sessions.
	 * @param array<mixed>                    $metadata  Arbitrary session metadata (JSON-serializable).
	 * @param string                   $context   Execution mode ('chat', 'pipeline', 'system').
	 * @return string Session ID (UUIDv4), or empty string on failure.
	 */
	public function create_session( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string;

	/**
	 * List transcript sessions for one workspace/user pair.
	 *
	 * Implementations should return newest sessions first by default and honor the
	 * `include_messages` arg. List callers pass `include_messages => false` by
	 * default so concrete stores can avoid loading full transcript payloads.
	 * Common filter/pagination keys are `limit`, `offset`, `agent_slug`, and
	 * `context`.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the sessions.
	 * @param int                      $user_id   WordPress user ID owning the sessions.
	 * @param array<mixed>                    $args      Optional host-supported filters/pagination.
	 * @return array<int,array<string,mixed>> Session rows.
	 */
	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array;

	/**
	 * Retrieve a transcript session by ID.
	 *
	 * Returns the session as an associative array with keys:
	 * session_id, workspace_type, workspace_id, user_id, agent_slug, title, messages (decoded array),
	 * metadata (decoded array), provider, model, provider_response_id, context/mode, created_at,
	 * updated_at, last_read_at, expires_at.
	 *
	 * @param string $session_id Session UUID.
	 * @return array<string,mixed>|null Session data or null if not found.
	 */
	public function get_session( string $session_id ): ?array;

	/**
	 * Replace a session's messages + metadata.
	 *
	 * The message array is the complete transcript, not a delta. `$provider`,
	 * `$model`, and `$provider_response_id` link the transcript to the most recent
	 * provider-side run/response state without requiring this store to own chat run
	 * status. Active run status is handled by the runtime-neutral chat run-control
	 * contract; adapters may also mirror run ids in namespaced metadata when their
	 * product needs that linkage for listing or reporting.
	 *
	 * @param string      $session_id           Session UUID.
	 * @param array<mixed>       $messages             Complete messages array (not a delta).
	 * @param array<mixed>       $metadata             Updated metadata.
	 * @param string      $provider             Optional AI provider identifier.
	 * @param string      $model                Optional AI model identifier.
	 * @param string|null $provider_response_id Opaque provider-side response/state ID, or null when none.
	 * @return bool True on success.
	 */
	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool;

	/**
	 * Delete a session by ID. Idempotent.
	 *
	 * @param string $session_id Session UUID.
	 * @return bool True on success.
	 */
	public function delete_session( string $session_id ): bool;

	/**
	 * Find a recent pending session for deduplication after request timeouts.
	 *
	 * Returns the most recent session that belongs to $workspace and $user_id,
	 * was created within $seconds, and is either empty or actively processing.
	 * Used by the orchestrator to avoid duplicate sessions when a timeout
	 * triggers a client retry while PHP keeps executing.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the session.
	 * @param int                 $user_id   WordPress user ID.
	 * @param int                 $seconds   Lookback window (default 600 = 10 minutes).
	 * @param string              $context   Context filter.
	 * @param int|null            $token_id  Optional token ID for login-scoped dedup.
	 * @return array<string,mixed>|null Session data or null if none.
	 */
	public function get_recent_pending_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array;

	/**
	 * Set a transcript session's stored display title.
	 *
	 * Title generation and UI policy stay above the store. This mutator remains
	 * here because the persisted title is part of the transcript/session record.
	 *
	 * @param string $session_id Session UUID.
	 * @param string $title      New title.
	 * @return bool True on success.
	 */
	public function update_title( string $session_id, string $title ): bool;
}
