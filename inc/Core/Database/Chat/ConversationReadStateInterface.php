<?php
/**
 * Conversation read-state contract.
 *
 * Covers unread-count derivation and marking a session as read. Stores
 * that do not own read state can avoid this contract and still implement
 * transcript/index interfaces.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

interface ConversationReadStateInterface {

	/**
	 * Count unread assistant messages in a decoded messages array.
	 *
	 * Pure derivation from a messages array. Default impl is stateless and
	 * has no backend I/O. Included here so consumers can share unread
	 * semantics regardless of backend.
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
}
