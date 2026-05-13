<?php
/**
 * Agents API access-store adapter for Data Machine agent access grants.
 *
 * @package DataMachine\Core\Auth
 * @since   0.110.2
 */

namespace DataMachine\Core\Auth;

use DataMachine\Core\Database\Agents\AgentAccess;

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
	 * @param AgentAccess|null $access_repository Optional repository for tests.
	 */
	public function __construct( ?AgentAccess $access_repository = null ) {
		$this->access_repository = $access_repository ?? new AgentAccess();
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
		return $this->access_repository->grant_access( $grant );
	}

	/**
	 * Revoke a user's access grant for an agent.
	 */
	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool {
		return $this->access_repository->revoke_access( $agent_id, $user_id, $workspace_id );
	}

	/**
	 * Fetch a user's access grant for an agent.
	 */
	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?\WP_Agent_Access_Grant {
		return $this->access_repository->get_access( $agent_id, $user_id, $workspace_id );
	}

	/**
	 * List agent IDs accessible to a user.
	 *
	 * @return string[]
	 */
	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		return array_map( 'strval', $this->access_repository->get_agent_ids_for_user( $user_id, $minimum_role, $workspace_id ) );
	}

	/**
	 * List users with access to an agent.
	 *
	 * @return \WP_Agent_Access_Grant[]
	 */
	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array {
		return $this->access_repository->get_users_for_agent( $agent_id, $workspace_id );
	}
}
