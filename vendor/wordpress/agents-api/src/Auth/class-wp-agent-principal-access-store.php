<?php
/**
 * WP_Agent_Principal_Access_Store optional contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Principal_Access_Store' ) ) {
	/**
	 * Optional store contract for principal-aware access grants.
	 *
	 * Stores can implement this alongside WP_Agent_Access_Store to grant access
	 * to non-user principals such as host-resolved audiences without changing the
	 * existing WordPress-user store contract.
	 */
	interface WP_Agent_Principal_Access_Store {

		/**
		 * Fetch a principal's grant for an agent/workspace.
		 */
		public function get_access_for_principal( string $agent_id, AgentsAPI\AI\WP_Agent_Execution_Principal $principal, ?string $workspace_id = null ): ?WP_Agent_Access_Grant;

		/**
		 * List agent IDs accessible to a principal at or above the optional role.
		 *
		 * @return string[]
		 */
		public function get_agent_ids_for_principal( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, ?string $minimum_role = null, ?string $workspace_id = null ): array;
	}
}
