<?php
/**
 * Agent Memory Scope
 *
 * Immutable value object that uniquely identifies an agent memory record
 * across (layer, workspace_type, workspace_id, user_id, agent_id, filename).
 *
 * Same identity model as the on-disk path encoding, just decoupled from
 * the filesystem so an alternate store (e.g. database-backed, guideline-backed,
 * or host-native) can map it to its own physical key.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Memory_Scope {

	public readonly string $layer;

	public readonly string $workspace_type;

	public readonly string $workspace_id;

	public readonly int $user_id;

	public readonly int $agent_id;

	public readonly string $filename;

	/**
	 * @param string $layer          Memory layer identifier (for example shared, agent, user, or network).
	 * @param string $workspace_type Generic workspace kind.
	 * @param string $workspace_id   Stable workspace identifier within the workspace type.
	 * @param int    $user_id        Effective WordPress user ID. 0 = shared / no user.
	 * @param int    $agent_id       Agent ID for direct resolution. 0 = resolve from user_id.
	 * @param string $filename       Filename or relative path within the layer
	 *                               (e.g. 'MEMORY.md', 'contexts/chat.md', 'daily/2026/04/17.md').
	 */
	public function __construct(
		string $layer,
		string $workspace_type,
		string $workspace_id,
		int $user_id,
		int $agent_id,
		string $filename,
	) {
		$workspace = WP_Agent_Workspace_Scope::from_parts( $workspace_type, $workspace_id );

		$this->layer          = trim( $layer );
		$this->workspace_type = $workspace->workspace_type;
		$this->workspace_id   = $workspace->workspace_id;
		$this->user_id        = $user_id;
		$this->agent_id       = $agent_id;
		$this->filename       = trim( $filename );
	}

	/**
	 * Return the normalized workspace identity.
	 *
	 * @return WP_Agent_Workspace_Scope
	 */
	public function workspace(): WP_Agent_Workspace_Scope {
		return WP_Agent_Workspace_Scope::from_parts( $this->workspace_type, $this->workspace_id );
	}

	/**
	 * Stable string key for caching / map lookups.
	 *
	 * @return string
	 */
	public function key(): string {
		return sprintf( '%s:%s:%s:%d:%d:%s', $this->layer, $this->workspace_type, $this->workspace_id, $this->user_id, $this->agent_id, $this->filename );
	}
}
