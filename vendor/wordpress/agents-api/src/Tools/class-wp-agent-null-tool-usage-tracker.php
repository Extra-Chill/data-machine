<?php
/**
 * Null tool usage tracker.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Null_Tool_Usage_Tracker' ) ) {
	/**
	 * No-op usage tracker for consumers without durable usage storage.
	 */
	final class WP_Agent_Null_Tool_Usage_Tracker implements WP_Agent_Tool_Usage_Tracker {

		/** @inheritDoc */
		public function record_call( string $tool_name, string $workspace_id ): void {
			unset( $tool_name, $workspace_id );
		}

		/** @inheritDoc */
		public function top_n( string $workspace_id, int $limit ): array {
			unset( $workspace_id, $limit );
			return array();
		}
	}
}
