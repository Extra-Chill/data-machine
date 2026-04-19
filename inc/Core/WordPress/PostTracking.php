<?php
/**
 * Data Machine Post Tracking
 *
 * Automatic post origin tracking for every tool invocation that produces
 * a post. Stores handler_slug and flow_id as post meta; pipeline_id,
 * agent_id, and user_id are derivable from the flows table via the
 * flow_id and resolved on demand (see getPipelineIdForPost()).
 *
 * Tracking is invoked centrally in ToolExecutor::executeTool() after
 * every successful tool call whose result carries an extractable post_id,
 * covering both handler tools (PublishHandler / UpsertHandler subclasses)
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
 * @since 0.69.1 Dropped redundant pipeline_id post meta — derivable from
 *               flow_id via the flows table (#1091). Existing legacy rows
 *               are cleared by the datamachine_drop_redundant_post_pipeline_meta
 *               migration.
 */

namespace DataMachine\Core\WordPress;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Static utility for post origin tracking.
 */
class PostTracking {

	public const HANDLER_META_KEY    = '_datamachine_post_handler';
	public const FLOW_ID_META_KEY    = '_datamachine_post_flow_id';
	public const SOURCE_URL_META_KEY = '_datamachine_source_url';

	/**
	 * Store post tracking metadata from tool call context.
	 *
	 * Extracts handler_slug from the tool definition and flow_id from the
	 * job record. Writes non-empty values as post meta. pipeline_id is not
	 * stored — it is derivable from flow_id via the flows table.
	 *
	 * @param int   $post_id  WordPress post ID
	 * @param array $tool_def Tool definition (contains 'handler' key)
	 * @param int   $job_id   Job ID for flow lookup
	 */
	public static function store( int $post_id, array $tool_def, int $job_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$handler_slug = $tool_def['handler'] ?? '';
		$flow_id      = 0;

		// Look up flow from the job record
		if ( $job_id > 0 ) {
			$jobs_db = new Jobs();
			$job     = $jobs_db->get_job( $job_id );

			if ( $job ) {
				$flow_id = (int) ( $job['flow_id'] ?? 0 );
			}
		}

		if ( ! empty( $handler_slug ) ) {
			update_post_meta( $post_id, self::HANDLER_META_KEY, sanitize_text_field( $handler_slug ) );
		}

		if ( $flow_id > 0 ) {
			update_post_meta( $post_id, self::FLOW_ID_META_KEY, $flow_id );
		}

		do_action(
			'datamachine_log',
			'debug',
			'Post tracking meta stored',
			array(
				'post_id'      => $post_id,
				'handler_slug' => $handler_slug,
				'flow_id'      => $flow_id,
			)
		);
	}

	/**
	 * Resolve the pipeline ID for a DM-produced post.
	 *
	 * Reads _datamachine_post_flow_id from post meta and resolves the
	 * pipeline ID via the flows table (datamachine_flows.pipeline_id is
	 * immutable, so the resolution is stable).
	 *
	 * @param int $post_id WordPress post ID.
	 * @return int Pipeline ID, or 0 if the post was not produced by DM
	 *             or the flow row has since been deleted.
	 */
	public static function getPipelineIdForPost( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		$flow_id = (int) get_post_meta( $post_id, self::FLOW_ID_META_KEY, true );
		if ( $flow_id <= 0 ) {
			return 0;
		}

		$flow = ( new Flows() )->get_flow( $flow_id );
		if ( ! $flow || empty( $flow['pipeline_id'] ) ) {
			return 0;
		}

		return (int) $flow['pipeline_id'];
	}

	/**
	 * Collect the flow IDs that belong to a given pipeline.
	 *
	 * Used to translate "posts in pipeline N" queries into meta_query
	 * clauses on _datamachine_post_flow_id, since pipeline_id is no
	 * longer stored directly on posts.
	 *
	 * @param int $pipeline_id Pipeline ID.
	 * @return int[] Flow IDs belonging to the pipeline. Empty array if none.
	 */
	public static function getFlowIdsForPipeline( int $pipeline_id ): array {
		if ( $pipeline_id <= 0 ) {
			return array();
		}

		$flows_db = new Flows();
		$flows    = $flows_db->get_flows_for_pipeline( $pipeline_id );

		return array_map( static fn ( array $flow ): int => (int) $flow['flow_id'], $flows );
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
