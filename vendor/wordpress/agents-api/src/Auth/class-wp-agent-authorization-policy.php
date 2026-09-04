<?php
/**
 * WP_Agent_Authorization_Policy contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Authorization_Policy' ) ) {
	/**
	 * Contract for host-extensible agent authorization checks.
	 */
	interface WP_Agent_Authorization_Policy {

		/**
		 * Check whether a principal can use a WordPress capability.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal  Execution principal.
		 * @param string                               $capability Required WordPress capability.
		 * @param array<string,mixed>                  $context    Host-owned authorization context.
		 */
		public function can( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $capability, array $context = array() ): bool;

		/**
		 * Check whether a principal can access an agent at a minimum role.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal    Execution principal.
		 * @param string                               $agent_id     Agent identifier.
		 * @param string                               $minimum_role Minimum access role.
		 * @param array<string,mixed>                  $context      Host-owned authorization context.
		 */
		public function can_access_agent( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $agent_id, string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): bool;
	}
}
