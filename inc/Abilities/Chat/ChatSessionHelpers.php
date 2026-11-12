<?php
/**
 * Chat Session Helpers Trait
 *
 * Shared helper methods used across all Chat Session ability classes.
 * Provides database access and ownership verification.
 *
 * @package DataMachine\Abilities\Chat
 * @since 0.31.0
 */

namespace DataMachine\Abilities\Chat;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Chat\ConversationStoreInterface;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;

defined( 'ABSPATH' ) || exit;

trait ChatSessionHelpers {

	protected ConversationStoreInterface $chat_db;

	protected function initDatabase(): void {
		$this->chat_db = ConversationStoreFactory::get();
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can( 'chat' );
	}

	/**
	 * Check whether a requester can access a target user's chat sessions.
	 *
	 * @param int $target_user_id Target user ID.
	 * @return bool
	 */
	protected function can_access_user_sessions( int $target_user_id ): bool {
		$acting_user_id = PermissionHelper::acting_user_id();

		if ( $acting_user_id > 0 && $acting_user_id === $target_user_id ) {
			return true;
		}

		return PermissionHelper::can( 'manage_agents' );
	}

	/**
	 * Resolve the transcript owner used by session abilities.
	 *
	 * @param array $input           Ability input.
	 * @param int   $fallback_user_id User ID compatibility fallback.
	 * @return array|\WP_Error
	 */
	protected function resolve_transcript_owner( array $input, int $fallback_user_id ) {
		return ChatTranscriptOwner::resolve_for_request( $input, $fallback_user_id );
	}

	/**
	 * Verify that a session exists and belongs to the given user.
	 *
	 * @param string $session_id Session ID to verify.
	 * @param int    $user_id    User ID to check ownership against.
	 * @return array|\WP_Error Session data on success, or an error on failure.
	 */
	protected function verifySessionOwnership( string $session_id, int $user_id, ?array $transcript_owner = null ): array|\WP_Error {
		$session = $this->chat_db->get_session_for_transcript_owner( WordPressWorkspaceScope::current(), $user_id, $transcript_owner ?? array(), $session_id );

		if ( ! $session ) {
			return new \WP_Error( 'session_not_found', __( 'Chat session not found.', 'data-machine' ), array( 'status' => 404 ) );
		}

		return $session;
	}
}
