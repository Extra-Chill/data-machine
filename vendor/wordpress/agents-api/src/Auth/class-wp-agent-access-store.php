<?php
/**
 * WP_Agent_Access_Store contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Access_Store' ) ) {
	/**
	 * Store contract for agent access grants.
	 */
	interface WP_Agent_Access_Store {

		/**
		 * Create or update a grant.
		 */
		public function grant_access( WP_Agent_Access_Grant $grant ): WP_Agent_Access_Grant;

		/**
		 * Revoke a user's grant for an agent/workspace.
		 */
		public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool;

		/**
		 * Fetch a user's grant for an agent/workspace.
		 */
		public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?WP_Agent_Access_Grant;

		/**
		 * List agent IDs accessible to a user at or above the optional role.
		 *
		 * @return string[]
		 */
		public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array;

		/**
		 * List grants for an agent/workspace.
		 *
		 * @return WP_Agent_Access_Grant[]
		 */
		public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array;
	}
}
