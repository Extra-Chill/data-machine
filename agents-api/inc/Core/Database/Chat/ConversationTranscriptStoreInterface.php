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
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

interface ConversationTranscriptStoreInterface {

	/**
	 * Create a new conversation transcript session and return its ID.
	 *
	 * @param int    $user_id  WordPress user ID owning the session.
	 * @param int    $agent_id Agent ID (0 = legacy agent-less session).
	 * @param array  $metadata Arbitrary session metadata (JSON-serializable).
	 * @param string $context  Execution mode ('chat', 'pipeline', 'system').
	 * @return string Session ID (UUIDv4), or empty string on failure.
	 */
	public function create_session( int $user_id, int $agent_id = 0, array $metadata = array(), string $context = 'chat' ): string;

	/**
	 * Retrieve a transcript session by ID.
	 *
	 * Returns the session as an associative array with keys:
	 * session_id, user_id, agent_id, title, messages (decoded array),
	 * metadata (decoded array), provider, model, context/mode, created_at,
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
	 * Find a recent pending session for deduplication after request timeouts.
	 *
	 * Returns the most recent session that belongs to $user_id, was created
	 * within $seconds, and is either empty or actively processing. Used by
	 * the orchestrator to avoid duplicate sessions when a timeout triggers a
	 * client retry while PHP keeps executing.
	 *
	 * @param int      $user_id  WordPress user ID.
	 * @param int      $seconds  Lookback window (default 600 = 10 minutes).
	 * @param string   $context  Context filter.
	 * @param int|null $token_id Optional token ID for login-scoped dedup.
	 * @return array|null Session data or null if none.
	 */
	public function get_recent_pending_session( int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array;

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
