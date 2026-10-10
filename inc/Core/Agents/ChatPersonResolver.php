<?php
/**
 * Chat person resolver.
 *
 * Resolves a chat actor (platform + external actor ID) to a WordPress person
 * for a given agent: who they are, whether they may talk to the agent, which
 * role they hold, and the memory sections that should be injected for them.
 *
 * Fails closed: unlinked actors, unknown agents and unauthorized users all
 * resolve to `allowed: false` with no sections.
 *
 * @package DataMachine\Core\Agents
 */

namespace DataMachine\Core\Agents;

use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Engine\AI\MemoryFileRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves chat actors to WordPress users, roles and memory sections.
 */
class ChatPersonResolver {

	/**
	 * Event that carries the speaker's personal (user/principal) layers.
	 */
	const EVENT_TURN = 'turn';

	/**
	 * Name of the synthetic section stating the current speaker.
	 */
	const CURRENT_USER_SECTION = 'wordpress-current-user';

	/**
	 * Per-file size cap in bytes.
	 */
	const MAX_FILE_BYTES = 65536;

	/**
	 * Total size cap in bytes across memory sections.
	 */
	const MAX_TOTAL_BYTES = 131072;

	/**
	 * Maximum number of memory sections.
	 */
	const MAX_SECTIONS = 50;

	/**
	 * Layers that are not tied to a person; injected on non-turn events.
	 */
	const AGENT_LAYERS = array(
		MemoryFileRegistry::LAYER_SHARED,
		MemoryFileRegistry::LAYER_AGENT,
		MemoryFileRegistry::LAYER_NETWORK,
	);

	/**
	 * Layers owned by the speaker; injected on turn events only.
	 */
	const PERSON_LAYERS = array(
		MemoryFileRegistry::LAYER_USER,
		MemoryFileRegistry::LAYER_PRINCIPAL,
	);

	/**
	 * Resolve a chat actor for an agent.
	 *
	 * @param string $agent_slug Agent slug.
	 * @param string $platform   Chat platform name (data, not interpreted).
	 * @param string $actor_id   External actor ID on that platform.
	 * @param string $event      Event name; 'turn' selects the speaker's personal layers.
	 * @return array{allowed: bool, user_id: int, role: string, sections: array, reason: string}
	 */
	public function resolve( string $agent_slug, string $platform, string $actor_id, string $event = '' ): array {
		$user_id = ChatIdentityLinks::resolve_user_id( $platform, $actor_id );
		if ( $user_id <= 0 ) {
			return self::denied( 0, 'actor_not_linked' );
		}

		try {
			$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( $agent_slug );
		} catch ( \InvalidArgumentException $e ) {
			return self::denied( $user_id, 'agent_not_found' );
		}

		$role = $this->resolve_role( $identity, $user_id );
		if ( null === $role ) {
			return self::denied( $user_id, 'access_denied' );
		}

		$is_turn  = self::EVENT_TURN === $event;
		$sections = $this->build_sections( $identity, $user_id, $is_turn );

		if ( $is_turn ) {
			array_unshift(
				$sections,
				array(
					'name'     => self::CURRENT_USER_SECTION,
					'layer'    => 'runtime',
					'priority' => 0,
					'content'  => sprintf( 'The current speaker is WordPress user ID %d.', $user_id ),
				)
			);
		}

		return array(
			'allowed'  => true,
			'user_id'  => $user_id,
			'role'     => $role,
			'sections' => $sections,
			'reason'   => '',
		);
	}

	/**
	 * Resolve the role a user holds on an agent.
	 *
	 * Allowed when the user owns the agent, has manage_options, or holds an
	 * AgentAccess grant at 'viewer' or above; the result is then filtered through
	 * `datamachine_can_access_agent`. Owner and manage_options resolve to admin.
	 *
	 * @param AgentIdentity $identity Agent identity.
	 * @param int           $user_id  WordPress user ID.
	 * @return string|null Role (admin|operator|viewer), null when denied.
	 */
	private function resolve_role( AgentIdentity $identity, int $user_id ): ?string {
		$is_admin = $user_id === $identity->owner_id || user_can( $user_id, 'manage_options' );

		$grant      = ( new AgentAccess() )->get_access( (string) $identity->agent_id, $user_id );
		$has_grant  = $grant instanceof \WP_Agent_Access_Grant && $grant->role_meets( 'viewer' );
		$can_access = $is_admin || $has_grant;

		/** This filter is documented in inc/Abilities/PermissionHelper.php */
		$can_access = (bool) apply_filters( 'datamachine_can_access_agent', $can_access, $identity->agent_id, $user_id, 'viewer' );
		if ( ! $can_access ) {
			return null;
		}

		if ( $is_admin ) {
			return \WP_Agent_Access_Grant::ROLE_ADMIN;
		}

		return $has_grant ? $grant->role : \WP_Agent_Access_Grant::ROLE_VIEWER;
	}

	/**
	 * Build capped memory sections.
	 *
	 * Uses explicit layers on every AgentMemory so a missing user never falls
	 * back to the default (owner) user.
	 *
	 * @param AgentIdentity $identity Agent identity.
	 * @param int           $user_id  Speaker user ID.
	 * @param bool          $is_turn  Whether to build the speaker's personal layers.
	 * @return array<int, array{name: string, layer: string, priority: int, content: string}>
	 */
	private function build_sections( AgentIdentity $identity, int $user_id, bool $is_turn ): array {
		$layers = $is_turn ? self::PERSON_LAYERS : self::AGENT_LAYERS;

		$files = MemoryFileRegistry::get_for_modes(
			array( MemoryFileRegistry::MODE_ALL ),
			array(
				MemoryFileRegistry::INJECTION_AGENT_IDENTITY,
				MemoryFileRegistry::INJECTION_AGENT_MEMORY,
				MemoryFileRegistry::INJECTION_USER_PROFILE,
			)
		);

		$candidates = array();
		foreach ( $files as $filename => $meta ) {
			$layer = (string) ( $meta['layer'] ?? MemoryFileRegistry::LAYER_AGENT );
			if ( in_array( $layer, $layers, true ) ) {
				$candidates[ (string) $filename ] = array(
					'layer'    => $layer,
					'priority' => (int) ( $meta['priority'] ?? 50 ),
				);
			}
		}

		uksort(
			$candidates,
			static function ( string $a, string $b ) use ( $candidates ): int {
				return array( $candidates[ $a ]['priority'], $a ) <=> array( $candidates[ $b ]['priority'], $b );
			}
		);

		$sections = array();
		$total    = 0;
		foreach ( $candidates as $filename => $info ) {
			if ( count( $sections ) >= self::MAX_SECTIONS || $total >= self::MAX_TOTAL_BYTES ) {
				break;
			}

			// Agent-scoped layers read as the agent owner; personal layers as the speaker.
			$read_user_id = $is_turn ? $user_id : $identity->owner_id;
			$memory       = new AgentMemory( $read_user_id, $identity->agent_id, $filename, $info['layer'] );
			$read         = $memory->read();
			if ( ! $read->exists || '' === trim( (string) $read->content ) ) {
				continue;
			}

			$limit   = min( self::MAX_FILE_BYTES, self::MAX_TOTAL_BYTES - $total );
			$content = (string) $read->content;
			if ( strlen( $content ) > $limit ) {
				$content = function_exists( 'mb_strcut' ) ? mb_strcut( $content, 0, $limit, 'UTF-8' ) : substr( $content, 0, $limit );
			}

			$total     += strlen( $content );
			$sections[] = array(
				'name'     => $filename,
				'layer'    => $info['layer'],
				'priority' => $info['priority'],
				'content'  => $content,
			);
		}

		return $sections;
	}

	/**
	 * Build a fail-closed result.
	 *
	 * @param int    $user_id Linked user ID when known, else 0.
	 * @param string $reason  Machine-readable denial reason.
	 * @return array{allowed: bool, user_id: int, role: string, sections: array, reason: string}
	 */
	private static function denied( int $user_id, string $reason ): array {
		return array(
			'allowed'  => false,
			'user_id'  => $user_id,
			'role'     => '',
			'sections' => array(),
			'reason'   => $reason,
		);
	}
}
