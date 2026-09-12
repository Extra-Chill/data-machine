<?php
/**
 * Agent conversation transcript lock contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Optional single-writer lock primitive for transcript sessions.
 *
 * Locks are advisory: orchestrators acquire before mutating a transcript and
 * release after persistence. Stores are not required to enforce lock ownership
 * inside update_session().
 */
interface WP_Agent_Conversation_Lock {

	/**
	 * Acquire a single-writer lock on this session.
	 *
	 * Implementations must make expired locks reclaimable after $ttl_seconds.
	 *
	 * @param string $session_id   Session UUID.
	 * @param int    $ttl_seconds  Lock TTL. After expiry the lock is reclaimable.
	 * @return string|null Lock token, or null when contention prevents acquisition.
	 */
	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string;

	/**
	 * Release a previously acquired lock.
	 *
	 * Implementations must verify the supplied token matches the active lock. A
	 * stale token must not release a lock reacquired by another runner after TTL.
	 *
	 * @param string $session_id  Session UUID.
	 * @param string $lock_token  Token returned by acquire_session_lock().
	 * @return bool True on successful release; false on mismatch or no active lock.
	 */
	public function release_session_lock( string $session_id, string $lock_token ): bool;
}
