<?php
/**
 * WP_Agent_Registered_Agent_Materialization_Adapter contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Registered_Agent_Materialization_Adapter' ) ) {
	/**
	 * Storage-neutral adapter for materializing registered agent definitions.
	 */
	interface WP_Agent_Registered_Agent_Materialization_Adapter {

		/**
		 * Materializes the current request-local registered-agent snapshot.
		 *
		 * The adapter owns storage, runtime activation, owner resolution, and any
		 * comparison against previously materialized agents. Implementations must
		 * treat the same normalized `(agent_slug, owner_user_id, instance_key)`
		 * identity as idempotent: repeat calls update or reconcile the existing
		 * materialized agent instead of creating duplicates.
		 *
		 * Duplicate registered slugs are rejected by `WP_Agents_Registry` before the
		 * adapter runs. Removed definitions are represented by their absence from
		 * `$registered_agents`; adapters that track prior state may report removed
		 * or disabled instances in the returned result set according to host policy.
		 *
		 * @param array<string,WP_Agent> $registered_agents Current registered-agent snapshot keyed by slug.
		 * @param array<string,mixed>    $args              Host-owned materialization options and context.
		 * @return array<string,WP_Agent_Materialization_Result> Results keyed by registered slug or adapter-owned removed-state key.
		 */
		public function materialize_registered_agents( array $registered_agents, array $args = array() ): array;
	}
}
