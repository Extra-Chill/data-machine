<?php
/**
 * WP-CLI Post Query Command
 *
 * Provides CLI access to post query operations with filtering.
 * Wraps PostQueryAbilities API primitive.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;

defined( 'ABSPATH' ) || exit;

class PostsCommand extends BaseCommand {

	/**
	 * Default fields for post list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'title', 'post_type', 'status', 'handler', 'flow_id', 'pipeline_id', 'date' );

	/**
	 * Query posts by handler slug.
	 *
	 * ## OPTIONS
	 *
	 * <handler_slug>
	 * : Handler slug to filter by (e.g., "universal_web_scraper").
	 *
	 * [--post_type=<post_type>]
	 * : Post type to query.
	 * ---
	 * default: any
	 * ---
	 *
	 * [--post_status=<status>]
	 * : Post status to query.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--per_page=<number>]
	 * : Number of posts to return.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Query posts by handler
	 *     wp datamachine posts by-handler universal_web_scraper
	 *
	 *     # Query posts by handler with custom post type
	 *     wp datamachine posts by-handler universal_web_scraper --post_type=datamachine_event
	 *
	 *     # Query posts by handler with custom limit
	 *     wp datamachine posts by-handler wordpress_publish --per_page=50
	 *
	 *     # Output as CSV
	 *     wp datamachine posts by-handler wordpress_publish --format=csv
	 *
	 *     # Output only IDs (space-separated)
	 *     wp datamachine posts by-handler wordpress_publish --format=ids
	 *
	 *     # JSON output
	 *     wp datamachine posts by-handler wordpress_publish --format=json
	 *
	 * @subcommand by-handler
	 */
	public function by_handler( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Handler slug is required.' );
			return;
		}

		$handler_slug = $args[0];
		$post_type    = $assoc_args['post_type'] ?? 'any';
		$post_status  = $assoc_args['post_status'] ?? 'publish';
		$per_page     = (int) ( $assoc_args['per_page'] ?? 20 );
		$format       = $assoc_args['format'] ?? 'table';

		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		$ability = new \DataMachine\Abilities\PostQueryAbilities();
		$result  = $ability->executeQueryPosts(
			array(
				'filter_by'    => 'handler',
				'filter_value' => $handler_slug,
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'per_page'     => $per_page,
			)
		);

		$this->outputPostResult( $result, $assoc_args, $format );
	}

	/**
	 * Query posts by flow ID.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : Flow ID to filter by.
	 *
	 * [--post_type=<post_type>]
	 * : Post type to query.
	 * ---
	 * default: any
	 * ---
	 *
	 * [--post_status=<status>]
	 * : Post status to query.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--per_page=<number>]
	 * : Number of posts to return.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Query posts by flow
	 *     wp datamachine posts by-flow 7
	 *
	 *     # Query posts by flow with custom post type
	 *     wp datamachine posts by-flow 7 --post_type=datamachine_event
	 *
	 *     # Output as CSV
	 *     wp datamachine posts by-flow 7 --format=csv
	 *
	 *     # Output only IDs (space-separated)
	 *     wp datamachine posts by-flow 7 --format=ids
	 *
	 * @subcommand by-flow
	 */
	public function by_flow( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Flow ID is required.' );
			return;
		}

		$flow_id     = (int) $args[0];
		$post_type   = $assoc_args['post_type'] ?? 'any';
		$post_status = $assoc_args['post_status'] ?? 'publish';
		$per_page    = (int) ( $assoc_args['per_page'] ?? 20 );
		$format      = $assoc_args['format'] ?? 'table';

		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		$ability = new \DataMachine\Abilities\PostQueryAbilities();
		$result  = $ability->executeQueryPosts(
			array(
				'filter_by'    => 'flow',
				'filter_value' => $flow_id,
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'per_page'     => $per_page,
			)
		);

		$this->outputPostResult( $result, $assoc_args, $format );
	}

	/**
	 * Query posts by pipeline ID.
	 *
	 * ## OPTIONS
	 *
	 * <pipeline_id>
	 * : Pipeline ID to filter by.
	 *
	 * [--post_type=<post_type>]
	 * : Post type to query.
	 * ---
	 * default: any
	 * ---
	 *
	 * [--post_status=<status>]
	 * : Post status to query.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--per_page=<number>]
	 * : Number of posts to return.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Query posts by pipeline
	 *     wp datamachine posts by-pipeline 42
	 *
	 *     # Query posts by pipeline with custom post type
	 *     wp datamachine posts by-pipeline 42 --post_type=datamachine_event
	 *
	 *     # Query posts by pipeline with custom limit
	 *     wp datamachine posts by-pipeline 42 --per_page=50
	 *
	 *     # Output as CSV
	 *     wp datamachine posts by-pipeline 42 --format=csv
	 *
	 *     # Output only IDs (space-separated)
	 *     wp datamachine posts by-pipeline 42 --format=ids
	 *
	 * @subcommand by-pipeline
	 */
	public function by_pipeline( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Pipeline ID is required.' );
			return;
		}

		$pipeline_id = (int) $args[0];
		$post_type   = $assoc_args['post_type'] ?? 'any';
		$post_status = $assoc_args['post_status'] ?? 'publish';
		$per_page    = (int) ( $assoc_args['per_page'] ?? 20 );
		$format      = $assoc_args['format'] ?? 'table';

		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		$ability = new \DataMachine\Abilities\PostQueryAbilities();
		$result  = $ability->executeQueryPosts(
			array(
				'filter_by'    => 'pipeline',
				'filter_value' => $pipeline_id,
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'per_page'     => $per_page,
			)
		);

		$this->outputPostResult( $result, $assoc_args, $format );
	}

	/**
	 * List recently published posts managed by Data Machine.
	 *
	 * Queries across all post types — shows any post with DM tracking meta,
	 * ordered by publish date (newest first).
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post_type>]
	 * : Filter by post type.
	 * ---
	 * default: any
	 * ---
	 *
	 * [--post_status=<status>]
	 * : Post status to query.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--limit=<number>]
	 * : Number of posts to return.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show 20 most recent DM posts
	 *     wp datamachine posts recent
	 *
	 *     # Show 5 most recent
	 *     wp datamachine posts recent --limit=5
	 *
	 *     # Filter by post type
	 *     wp datamachine posts recent --post_type=recipe
	 *
	 *     # JSON output
	 *     wp datamachine posts recent --format=json
	 *
	 *     # Include drafts
	 *     wp datamachine posts recent --post_status=any
	 *
	 * @subcommand recent
	 */
	public function recent( array $args, array $assoc_args ): void {
		$post_type   = $assoc_args['post_type'] ?? 'any';
		$post_status = $assoc_args['post_status'] ?? 'publish';
		$per_page    = (int) ( $assoc_args['limit'] ?? 20 );
		$format      = $assoc_args['format'] ?? 'table';

		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		$ability = new \DataMachine\Abilities\PostQueryAbilities();
		$result  = $ability->executeQueryRecentPosts(
			array(
				'post_type'   => $post_type,
				'post_status' => $post_status,
				'per_page'    => $per_page,
			)
		);

		$this->outputPostResult( $result, $assoc_args, $format );
	}

	/**
	 * Output post query result using standardized formatting.
	 *
	 * @param array  $result     Query result from ability.
	 * @param array  $assoc_args Command arguments.
	 * @param string $format     Output format.
	 */
	private function outputPostResult( array $result, array $assoc_args, string $format ): void {
		if ( ! $result['posts'] ) {
			WP_CLI::warning( 'No posts found.' );
			return;
		}

		// Transform posts to flat row format.
		$items = array_map(
			function ( $post ) {
				return array(
					'id'          => $post['id'],
					'title'       => $post['title'],
					'post_type'   => $post['post_type'],
					'status'      => $post['post_status'],
					'handler'     => $post['handler_slug'] ? $post['handler_slug'] : 'N/A',
					'flow_id'     => $post['flow_id'] ? $post['flow_id'] : 'N/A',
					'pipeline_id' => $post['pipeline_id'] ? $post['pipeline_id'] : 'N/A',
					'date'        => $post['post_date'],
				);
			},
			$result['posts']
		);

		$this->format_items( $items, $this->default_fields, $assoc_args, 'id' );

		if ( 'table' === $format ) {
			WP_CLI::log( "Found {$result['total']} posts (showing " . count( $result['posts'] ) . ').' );
		}
	}
}
