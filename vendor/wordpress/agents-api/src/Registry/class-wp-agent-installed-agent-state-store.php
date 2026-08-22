<?php
/**
 * WP_Agent_Installed_Agent_State_Store contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Installed_Agent_State_Store' ) ) {
	/**
	 * Storage-neutral installed-agent state store contract.
	 */
	interface WP_Agent_Installed_Agent_State_Store {

		/**
		 * Resolves installed state for a logical agent instance.
		 *
		 * @param string              $agent_slug    Agent slug.
		 * @param int|null            $owner_user_id Optional owner user ID.
		 * @param string              $instance_key  Product-owned instance key.
		 * @param array<string,mixed> $context       Host context.
		 * @return WP_Agent_Installed_Agent|null Installed state, or null when absent.
		 */
		public function resolve( string $agent_slug, ?int $owner_user_id = null, string $instance_key = 'default', array $context = array() ): ?WP_Agent_Installed_Agent;

		/**
		 * Materializes or reconciles installed state.
		 *
		 * Implementations own persistence and must keep the same normalized
		 * `(agent_slug, owner_user_id, instance_key)` tuple idempotent.
		 *
		 * @param WP_Agent_Materialization_Request $request Request.
		 * @return WP_Agent_Materialization_Result Result.
		 */
		public function materialize( WP_Agent_Materialization_Request $request ): WP_Agent_Materialization_Result;

		/**
		 * Deletes or disables installed state according to host policy.
		 *
		 * @param WP_Agent_Installed_Agent $installed_agent Installed state.
		 * @param array<string,mixed>      $context         Host context.
		 * @return bool Whether the operation succeeded.
		 */
		public function delete( WP_Agent_Installed_Agent $installed_agent, array $context = array() ): bool;
	}
}
