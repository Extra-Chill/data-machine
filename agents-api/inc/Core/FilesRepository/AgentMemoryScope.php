<?php
/**
 * Agent Memory Scope
 *
 * Immutable value object that uniquely identifies an agent memory record
 * across the four-tuple primary key (layer, user_id, agent_id, filename).
 *
 * Same identity model as the on-disk path encoding, just decoupled from
 * the filesystem so an alternate store (e.g. database-backed, guideline-backed,
 * or host-native) can map it to its own physical key.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class AgentMemoryScope {

	/**
	 * @param string $layer    Memory layer identifier (for example shared, agent, user, or network).
	 * @param int    $user_id  Effective WordPress user ID. 0 = legacy shared / no user.
	 * @param int    $agent_id Agent ID for direct resolution. 0 = resolve from user_id.
	 * @param string $filename Filename or relative path within the layer
	 *                         (e.g. 'MEMORY.md', 'contexts/chat.md', 'daily/2026/04/17.md').
	 */
	public function __construct(
		public readonly string $layer,
		public readonly int $user_id,
		public readonly int $agent_id,
		public readonly string $filename,
	) {}

	/**
	 * Stable string key for caching / map lookups.
	 *
	 * @return string
	 */
	public function key(): string {
		return sprintf( '%s:%d:%d:%s', $this->layer, $this->user_id, $this->agent_id, $this->filename );
	}
}
