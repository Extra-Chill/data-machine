<?php
/**
 * Tool usage tracker contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Tool_Usage_Tracker' ) ) {
	/**
	 * Tracks recently or frequently used tools for a workspace.
	 */
	interface WP_Agent_Tool_Usage_Tracker {

		/**
		 * Record one tool call.
		 *
		 * @param string $tool_name    Tool name.
		 * @param string $workspace_id Workspace identifier.
		 */
		public function record_call( string $tool_name, string $workspace_id ): void;

		/**
		 * Return the top tool names for a workspace.
		 *
		 * @param string $workspace_id Workspace identifier.
		 * @param int    $limit        Maximum number of names.
		 * @return string[] Tool names ordered by preference.
		 */
		public function top_n( string $workspace_id, int $limit ): array;
	}
}
