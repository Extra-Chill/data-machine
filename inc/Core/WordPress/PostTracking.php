<?php
/**
 * Data Machine Post Tracking
 *
 * Automatic post origin tracking for every tool invocation that produces
 * a post. Stores handler_slug, flow_id, and pipeline_id as post meta.
 *
 * Tracking is invoked centrally in ToolExecutor::executeTool() after
 * every successful tool call whose result carries an extractable post_id,
 * covering both handler tools (PublishHandler / UpdateHandler subclasses)
 * and ability tools (PublishWordPressAbility, InsertContentAbility,
 * EditPostBlocksAbility, ReplacePostBlocksAbility, third-party abilities
 * registered as pipeline tools). Individual handlers and abilities do
 * not call any tracking methods themselves.
 *
 * @package DataMachine\Core\WordPress
 * @since 0.12.0
 * @since 0.32.0 Refactored from trait to static utility. Tracking is
 *               automatic in base handler handle_tool_call() methods.
 * @since 0.69.0 Tracking moved to ToolExecutor::executeTool() so ability
 *               tools receive the same origin meta as handler tools
 *               (#1084).
 */

namespace DataMachine\Core\WordPress;

use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Static utility for post origin tracking.
 */
class PostTracking {

	public const HANDLER_META_KEY     = '_datamachine_post_handler';
	public const FLOW_ID_META_KEY     = '_datamachine_post_flow_id';
	public const PIPELINE_ID_META_KEY = '_datamachine_post_pipeline_id';
	public const SOURCE_URL_META_KEY  = '_datamachine_source_url';

	/**
	 * Store post tracking metadata from tool call context.
	 *
	 * Extracts handler_slug from the tool definition and flow_id/pipeline_id
	 * from the job record. Writes non-empty values as post meta.
	 *
	 * @param int   $post_id  WordPress post ID
	 * @param array $tool_def Tool definition (contains 'handler' key)
	 * @param int   $job_id   Job ID for flow/pipeline lookup
	 */
	public static function store( int $post_id, array $tool_def, int $job_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$handler_slug = $tool_def['handler'] ?? '';
		$flow_id      = 0;
		$pipeline_id  = 0;

		// Look up flow and pipeline from the job record
		if ( $job_id > 0 ) {
			$jobs_db = new Jobs();
			$job     = $jobs_db->get_job( $job_id );

			if ( $job ) {
				$flow_id     = (int) ( $job['flow_id'] ?? 0 );
				$pipeline_id = (int) ( $job['pipeline_id'] ?? 0 );
			}
		}

		if ( ! empty( $handler_slug ) ) {
			update_post_meta( $post_id, self::HANDLER_META_KEY, sanitize_text_field( $handler_slug ) );
		}

		if ( $flow_id > 0 ) {
			update_post_meta( $post_id, self::FLOW_ID_META_KEY, $flow_id );
		}

		if ( $pipeline_id > 0 ) {
			update_post_meta( $post_id, self::PIPELINE_ID_META_KEY, $pipeline_id );
		}

		do_action(
			'datamachine_log',
			'debug',
			'Post tracking meta stored',
			array(
				'post_id'      => $post_id,
				'handler_slug' => $handler_slug,
				'flow_id'      => $flow_id,
				'pipeline_id'  => $pipeline_id,
			)
		);
	}

	/**
	 * Extract post_id from a handler result array.
	 *
	 * Checks both top-level and nested data.post_id locations,
	 * covering both Update handlers (top-level) and Publish handlers (nested).
	 *
	 * @param array $result Handler result array
	 * @return int Post ID or 0 if not found
	 */
	public static function extractPostId( array $result ): int {
		// Update handlers: result.data.post_id
		if ( ! empty( $result['data']['post_id'] ) ) {
			return (int) $result['data']['post_id'];
		}

		// Direct post_id in result
		if ( ! empty( $result['post_id'] ) ) {
			return (int) $result['post_id'];
		}

		return 0;
	}
}
