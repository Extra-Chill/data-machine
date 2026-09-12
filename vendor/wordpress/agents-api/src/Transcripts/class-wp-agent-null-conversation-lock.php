<?php
/**
 * No-op conversation transcript lock implementation.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Reference no-op lock for hosts that intentionally run without locking.
 */
class WP_Agent_Null_Conversation_Lock implements WP_Agent_Conversation_Lock {

	/**
	 * Decline lock acquisition.
	 *
	 * @param string $session_id   Session UUID.
	 * @param int    $ttl_seconds  Lock TTL.
	 * @return string|null Always null.
	 */
	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		unset( $session_id, $ttl_seconds );
		return null;
	}

	/**
	 * Decline lock release.
	 *
	 * @param string $session_id  Session UUID.
	 * @param string $lock_token  Lock token.
	 * @return bool Always false.
	 */
	public function release_session_lock( string $session_id, string $lock_token ): bool {
		unset( $session_id, $lock_token );
		return false;
	}
}
