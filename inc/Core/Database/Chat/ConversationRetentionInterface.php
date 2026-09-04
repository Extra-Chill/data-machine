<?php
/**
 * Conversation retention contract.
 *
 * Covers destructive cleanup operations for chat-session retention. This
 * contract is intentionally separate from transcript CRUD so external
 * backends can expose cleanup only when they own retention policy.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

interface ConversationRetentionInterface {

	/**
	 * Delete sessions whose `expires_at` has passed.
	 *
	 * @return int Number of sessions deleted.
	 */
	public function cleanup_expired_sessions(): int;

	/**
	 * Count sessions older than the retention window.
	 *
	 * @param int  $retention_days               Days to retain sessions (by updated_at).
	 * @param bool $exclude_pipeline_transcripts Whether pipeline transcripts are counted separately.
	 * @return int Number of matching sessions.
	 */
	public function count_old_sessions( int $retention_days, bool $exclude_pipeline_transcripts = false ): int;

	/**
	 * Delete sessions older than the retention window.
	 *
	 * @param int $retention_days Days to retain sessions (by updated_at).
	 * @return int Number of sessions deleted.
	 */
	public function cleanup_old_sessions( int $retention_days ): int;

	/**
	 * Count pipeline transcript sessions older than the retention window.
	 *
	 * @param int $retention_days Days to retain pipeline transcripts.
	 * @return int Number of matching transcript sessions.
	 */
	public function count_old_pipeline_transcripts( int $retention_days ): int;

	/**
	 * Delete pipeline transcript sessions older than the retention window.
	 *
	 * @param int $retention_days Days to retain pipeline transcripts.
	 * @return int Number of deleted transcript sessions.
	 */
	public function cleanup_pipeline_transcripts( int $retention_days ): int;

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
}
