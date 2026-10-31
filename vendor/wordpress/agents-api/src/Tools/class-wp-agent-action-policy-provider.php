<?php
/**
 * Agent action policy provider contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Action_Policy_Provider' ) ) {
	/**
	 * Provides host-specific execution policy for a tool invocation.
	 */
	interface WP_Agent_Action_Policy_Provider {

		/**
		 * Resolve a policy override for a tool invocation.
		 *
		 * Return one of direct, preview, forbidden, or null for no opinion.
		 *
		 * @param array<string, mixed> $context Action policy context.
		 * @return string|null Action policy value, or null for no opinion.
		 */
		public function get_action_policy( array $context ): ?string;
	}
}
