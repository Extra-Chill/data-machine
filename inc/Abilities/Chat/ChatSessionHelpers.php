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
use DataMachine\Core\Database\Chat\Chat as ChatDatabase;

defined( 'ABSPATH' ) || exit;

trait ChatSessionHelpers {

	protected ChatDatabase $chat_db;

	protected function initDatabase(): void {
		$this->chat_db = new ChatDatabase();
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Verify that a session exists and belongs to the given user.
	 *
	 * @param string $session_id Session ID to verify.
	 * @param int    $user_id    User ID to check ownership against.
	 * @return array|array{error: string} Session data on success, or array with 'error' key on failure.
	 */
	protected function verifySessionOwnership( string $session_id, int $user_id ): array {
		$session = $this->chat_db->get_session( $session_id );

		if ( ! $session ) {
			return array( 'error' => 'session_not_found' );
		}

		if ( (int) $session['user_id'] !== $user_id ) {
			return array( 'error' => 'session_access_denied' );
		}

		return $session;
	}
}
