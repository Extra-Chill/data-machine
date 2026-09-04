<?php
/**
 * Agent tool visibility policy provider contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Tool_Access_Policy' ) ) {
	/**
	 * Provides host-specific tool visibility policy for a runtime context.
	 */
	interface WP_Agent_Tool_Access_Policy {

		/**
		 * Return a tool visibility policy for the current runtime context.
		 *
		 * Supported keys are: mode, tools, categories, allow_only, deny,
		 * runtime_tools, runtime_categories, mandatory_tools, and
		 * mandatory_categories.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return array<string, mixed>|null Policy fragment, or null for no opinion.
		 */
		public function get_tool_policy( array $context ): ?array;
	}
}
