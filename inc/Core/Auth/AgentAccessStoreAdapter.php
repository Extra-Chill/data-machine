<?php
/**
 * Agents API access-store adapter for Data Machine agent access grants.
 *
 * @package DataMachine\Core\Auth
 * @since   0.110.2
 */

namespace DataMachine\Core\Auth;

use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes Data Machine's existing agent access table through Agents API.
 */
class AgentAccessStoreAdapter implements \WP_Agent_Access_Store {

	/**
	 * Existing Data Machine access repository.
	 *
	 * @var AgentAccess
	 */
	private AgentAccess $access_repository;

	/**
	 * Agent identity repository used to map Data Machine IDs to Agents API slugs.
	 *
	 * @var Agents
	 */
	private Agents $agents_repository;

	/**
	 * @param AgentAccess|null $access_repository Optional repository for tests.
	 * @param Agents|null      $agents_repository Optional repository for tests.
	 */
	public function __construct( ?AgentAccess $access_repository = null, ?Agents $agents_repository = null ) {
		$this->access_repository = $access_repository ?? new AgentAccess();
		$this->agents_repository = $agents_repository ?? new Agents();
	}

	/**
	 * Register this adapter as the Agents API access store when no host supplied one.
	 */
	public static function register(): void {
		add_filter( 'wp_agent_access_store', array( self::class, 'filter_access_store' ) );
	}

	/**
	 * Provide Data Machine's store unless another host already provided one.
	 *
	 * @param mixed $store Existing filtered store.
	 * @return mixed
	 */
	public static function filter_access_store( $store ) {
		if ( $store instanceof \WP_Agent_Access_Store ) {
			return $store;
		}

		static $adapter = null;

		if ( null === $adapter ) {
			$adapter = new self();
		}

		return $adapter;
	}

	/**
	 * Create or update an access grant.
	 */
	public function grant_access( \WP_Agent_Access_Grant $grant ): \WP_Agent_Access_Grant {
		$resolved = $this->access_repository->grant_access( $this->grant_for_storage( $grant ) );
		return $this->grant_for_contract( $resolved, $grant->agent_id );
	}

	/**
	 * Revoke a user's access grant for an agent.
	 */
	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool {
		return $this->access_repository->revoke_access( $this->storage_agent_id( $agent_id ), $user_id, $workspace_id );
	}

	/**
	 * Fetch a user's access grant for an agent.
	 */
	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?\WP_Agent_Access_Grant {
		$grant = $this->access_repository->get_access( $this->storage_agent_id( $agent_id ), $user_id, $workspace_id );
		return $grant ? $this->grant_for_contract( $grant, $agent_id ) : null;
	}

	/**
	 * List agent IDs accessible to a user.
	 *
	 * @return string[]
	 */
	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		$agent_ids = $this->access_repository->get_agent_ids_for_user( $user_id, $minimum_role, $workspace_id );
		if ( empty( $agent_ids ) ) {
			return array();
		}

		$rows = $this->agents_repository->get_agents_by_ids( array_map( 'intval', $agent_ids ) );
		$slugs_by_id = array();
		foreach ( $rows as $row ) {
			$agent_id = (int) ( $row['agent_id'] ?? 0 );
			$slug     = sanitize_title( (string) ( $row['agent_slug'] ?? '' ) );
			if ( $agent_id > 0 && '' !== $slug ) {
				$slugs_by_id[ $agent_id ] = $slug;
			}
		}

		$slugs = array();
		foreach ( $agent_ids as $agent_id ) {
			$agent_id = (int) $agent_id;
			if ( isset( $slugs_by_id[ $agent_id ] ) ) {
				$slugs[] = $slugs_by_id[ $agent_id ];
			}
		}

		return $slugs;
	}

	/**
	 * List users with access to an agent.
	 *
	 * @return \WP_Agent_Access_Grant[]
	 */
	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array {
		return array_map(
			fn( \WP_Agent_Access_Grant $grant ): \WP_Agent_Access_Grant => $this->grant_for_contract( $grant, $agent_id ),
			$this->access_repository->get_users_for_agent( $this->storage_agent_id( $agent_id ), $workspace_id )
		);
	}

	/**
	 * Convert an Agents API slug to the Data Machine numeric storage ID.
	 */
	private function storage_agent_id( string $agent_id ): string {
		if ( is_numeric( $agent_id ) ) {
			return $agent_id;
		}

		$row = $this->agents_repository->get_by_slug( $agent_id );
		if ( $row && ! empty( $row['agent_id'] ) ) {
			return (string) (int) $row['agent_id'];
		}

		return $agent_id;
	}

	/**
	 * Convert a contract grant into Data Machine's numeric storage shape.
	 */
	private function grant_for_storage( \WP_Agent_Access_Grant $grant ): \WP_Agent_Access_Grant {
		$storage_agent_id = $this->storage_agent_id( $grant->agent_id );
		if ( $storage_agent_id === $grant->agent_id ) {
			return $grant;
		}

		return new \WP_Agent_Access_Grant(
			$storage_agent_id,
			$grant->user_id,
			$grant->role,
			$grant->workspace_id,
			$grant->grant_id,
			$grant->granted_by_user_id,
			$grant->granted_at,
			$grant->metadata
		);
	}

	/**
	 * Convert a Data Machine numeric grant into the Agents API slug shape.
	 */
	private function grant_for_contract( \WP_Agent_Access_Grant $grant, string $requested_agent_id = '' ): \WP_Agent_Access_Grant {
		$agent_slug = $this->contract_agent_id( $grant->agent_id );
		if ( ! is_numeric( $requested_agent_id ) ) {
			$requested_slug = sanitize_title( $requested_agent_id );
			if ( '' !== $requested_slug ) {
				$agent_slug = $requested_slug;
			}
		}

		if ( $agent_slug === $grant->agent_id ) {
			return $grant;
		}

		return new \WP_Agent_Access_Grant(
			$agent_slug,
			$grant->user_id,
			$grant->role,
			$grant->workspace_id,
			$grant->grant_id,
			$grant->granted_by_user_id,
			$grant->granted_at,
			$grant->metadata
		);
	}

	/**
	 * Convert a Data Machine numeric storage ID to an Agents API slug.
	 */
	private function contract_agent_id( string $agent_id ): string {
		if ( ! is_numeric( $agent_id ) ) {
			return sanitize_title( $agent_id );
		}

		$row = $this->agents_repository->get_agent( (int) $agent_id );
		if ( $row && ! empty( $row['agent_slug'] ) ) {
			return sanitize_title( (string) $row['agent_slug'] );
		}

		return $agent_id;
	}
}
