<?php
/**
 * WP-CLI Links Command
 *
 * Provides CLI access to internal linking diagnostics and cross-linking.
 * Wraps InternalLinkingAbilities API primitives.
 *
 * Subcommands:
 * - crosslink — Queue system agent link insertion.
 * - diagnose  — Meta-based coverage report.
 * - audit     — Scan content, build + cache link graph.
 * - orphans   — List orphaned posts (zero inbound links).
 * - broken    — HTTP HEAD checks for broken internal links.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.24.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\InternalLinkingAbilities;

defined( 'ABSPATH' ) || exit;

class LinksCommand extends BaseCommand {

	/**
	 * Queue internal cross-linking for posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post_id=<id>]
	 * : Specific post ID to cross-link.
	 *
	 * [--category=<slug>]
	 * : Process all published posts in a category.
	 *
	 * [--all]
	 * : Process all published posts.
	 *
	 * [--links-per-post=<number>]
	 * : Maximum internal links per post.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--force]
	 * : Force re-processing even if already linked.
	 *
	 * [--dry-run]
	 * : Preview which posts would be queued without processing.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Cross-link a specific post
	 *     wp datamachine links crosslink --post_id=123
	 *
	 *     # Cross-link all posts in a category
	 *     wp datamachine links crosslink --category=nature
	 *
	 *     # Cross-link all posts with 5 links each
	 *     wp datamachine links crosslink --all --links-per-post=5
	 *
	 *     # Dry run
	 *     wp datamachine links crosslink --all --dry-run
	 *
	 *     # Force re-processing, JSON output
	 *     wp datamachine links crosslink --post_id=123 --force --format=json
	 *
	 * @subcommand crosslink
	 */
	public function crosslink( array $args, array $assoc_args ): void {
		$post_id        = isset( $assoc_args['post_id'] ) ? absint( $assoc_args['post_id'] ) : 0;
		$category       = sanitize_text_field( $assoc_args['category'] ?? '' );
		$all            = isset( $assoc_args['all'] );
		$links_per_post = absint( $assoc_args['links-per-post'] ?? 3 );
		$force          = isset( $assoc_args['force'] );
		$dry_run        = isset( $assoc_args['dry-run'] );
		$format         = $assoc_args['format'] ?? 'table';

		$post_ids = array();

		if ( $post_id > 0 ) {
			$post_ids[] = $post_id;
		}

		if ( $all ) {
			$all_posts = get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			);
			$post_ids  = array_merge( $post_ids, $all_posts );
		}

		if ( 0 === $post_id && empty( $category ) && ! $all ) {
			WP_CLI::error( 'Required: --post_id=<id>, --category=<slug>, or --all' );
			return;
		}

		$result = InternalLinkingAbilities::queueInternalLinking(
			array(
				'post_ids'       => $post_ids,
				'category'       => $category,
				'links_per_post' => $links_per_post,
				'dry_run'        => $dry_run,
				'force'          => $force,
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to queue internal linking.' );
			return;
		}

		$queued_count = (int) ( $result['queued_count'] ?? 0 );
		$queued_ids   = $result['post_ids'] ?? array();
		$queued_ids   = is_array( $queued_ids ) ? array_values( array_map( 'intval', $queued_ids ) ) : array();

		if ( 'json' === $format ) {
			WP_CLI::line(
				\wp_json_encode(
					array(
						'queued_count' => $queued_count,
						'post_ids'     => $queued_ids,
						'message'      => $result['message'] ?? '',
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		if ( 'table' === $format && ! empty( $result['message'] ) ) {
			WP_CLI::success( $result['message'] );
		}

		$items = array(
			array(
				'queued_count' => $queued_count,
				'post_ids'     => empty( $queued_ids ) ? '' : implode( ', ', $queued_ids ),
			),
		);

		$this->format_items( $items, array( 'queued_count', 'post_ids' ), $assoc_args );
	}

	/**
	 * Diagnose internal link coverage across published posts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Diagnose internal link coverage
	 *     wp datamachine links diagnose
	 *
	 *     # JSON output
	 *     wp datamachine links diagnose --format=json
	 *
	 * @subcommand diagnose
	 */
	public function diagnose( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$result = InternalLinkingAbilities::diagnoseInternalLinks();

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to diagnose internal links.' );
			return;
		}

		$total_posts         = (int) ( $result['total_posts'] ?? 0 );
		$posts_with_links    = (int) ( $result['posts_with_links'] ?? 0 );
		$posts_without_links = (int) ( $result['posts_without_links'] ?? 0 );
		$avg_links_per_post  = (float) ( $result['avg_links_per_post'] ?? 0 );
		$by_category         = $result['by_category'] ?? array();

		if ( 'json' === $format ) {
			WP_CLI::line(
				\wp_json_encode(
					array(
						'total_posts'         => $total_posts,
						'posts_with_links'    => $posts_with_links,
						'posts_without_links' => $posts_without_links,
						'avg_links_per_post'  => $avg_links_per_post,
						'by_category'         => $by_category,
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		// Summary row + category breakdown.
		$items   = array();
		$items[] = array(
			'category'      => 'ALL',
			'total_posts'   => $total_posts,
			'with_links'    => $posts_with_links,
			'without_links' => $posts_without_links,
			'avg_links'     => $avg_links_per_post,
		);

		foreach ( $by_category as $row ) {
			$items[] = array(
				'category'      => $row['category'] ?? 'unknown',
				'total_posts'   => (int) ( $row['total_posts'] ?? 0 ),
				'with_links'    => (int) ( $row['with_links'] ?? 0 ),
				'without_links' => (int) ( $row['without_links'] ?? 0 ),
				'avg_links'     => '',
			);
		}

		$this->format_items( $items, array( 'category', 'total_posts', 'with_links', 'without_links', 'avg_links' ), $assoc_args );
	}

	/**
	 * Audit internal links by scanning actual post content.
	 *
	 * Scans post HTML for <a> tags pointing to internal URLs, builds
	 * a link graph, and caches results (24hr TTL). Use --show to
	 * display specific sections of the audit.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to audit.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--category=<slug>]
	 * : Limit audit to a specific category.
	 *
	 * [--post_id=<id>]
	 * : Audit specific post IDs (comma-separated).
	 *
	 * [--force]
	 * : Force rebuild even if cached graph exists.
	 *
	 * [--show=<section>]
	 * : Which section to display: summary, orphans, top, all.
	 * ---
	 * default: summary
	 * options:
	 *   - summary
	 *   - orphans
	 *   - top
	 *   - all
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
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Full audit of all posts
	 *     wp datamachine links audit
	 *
	 *     # Audit a specific category
	 *     wp datamachine links audit --category=tutorials
	 *
	 *     # Show orphaned posts (no inbound links)
	 *     wp datamachine links audit --show=orphans
	 *
	 *     # Show top linked posts
	 *     wp datamachine links audit --show=top
	 *
	 *     # Force rebuild, full JSON output
	 *     wp datamachine links audit --force --show=all --format=json
	 *
	 * @subcommand audit
	 */
	public function audit( array $args, array $assoc_args ): void {
		$format    = $assoc_args['format'] ?? 'table';
		$show      = $assoc_args['show'] ?? 'summary';
		$post_type = $assoc_args['post_type'] ?? 'post';
		$category  = $assoc_args['category'] ?? '';
		$force     = isset( $assoc_args['force'] );

		$post_ids = array();
		if ( isset( $assoc_args['post_id'] ) ) {
			$post_ids = array_map( 'absint', explode( ',', $assoc_args['post_id'] ) );
		}

		WP_CLI::log( 'Scanning post content for internal links...' );

		$result = InternalLinkingAbilities::auditInternalLinks(
			array(
				'post_type' => $post_type,
				'category'  => $category,
				'post_ids'  => $post_ids,
				'force'     => $force,
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Audit failed.' );
			return;
		}

		if ( ! empty( $result['cached'] ) ) {
			WP_CLI::log( 'Using cached link graph (use --force to rebuild).' );
		}

		// Strip internal keys from JSON output.
		$clean_result = array_filter(
			$result,
			fn( $key ) => 0 !== strpos( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);

		if ( 'json' === $format && 'all' === $show ) {
			WP_CLI::line( \wp_json_encode( $clean_result, JSON_PRETTY_PRINT ) );
			return;
		}

		$total   = (int) ( $result['total_scanned'] ?? 0 );
		$links   = (int) ( $result['total_links'] ?? 0 );
		$orphans = (int) ( $result['orphaned_count'] ?? 0 );

		// Summary.
		if ( 'summary' === $show || 'all' === $show ) {
			if ( 'json' === $format ) {
				WP_CLI::line(
					\wp_json_encode(
						array(
							'total_scanned'  => $total,
							'total_links'    => $links,
							'orphaned_count' => $orphans,
							'avg_outbound'   => $result['avg_outbound'] ?? 0,
							'avg_inbound'    => $result['avg_inbound'] ?? 0,
							'cached'         => $result['cached'] ?? false,
						),
						JSON_PRETTY_PRINT
					)
				);
			} else {
				$items = array(
					array(
						'metric' => 'Posts scanned',
						'value'  => $total,
					),
					array(
						'metric' => 'Internal links found',
						'value'  => $links,
					),
					array(
						'metric' => 'Avg outbound per post',
						'value'  => $result['avg_outbound'] ?? 0,
					),
					array(
						'metric' => 'Avg inbound per post',
						'value'  => $result['avg_inbound'] ?? 0,
					),
					array(
						'metric' => 'Orphaned posts (0 inbound)',
						'value'  => $orphans,
					),
				);

				$this->format_items( $items, array( 'metric', 'value' ), $assoc_args );
			}
		}

		// Orphaned posts.
		if ( ( 'orphans' === $show || 'all' === $show ) && ! empty( $result['orphaned_posts'] ) ) {
			if ( 'all' === $show && 'table' === $format ) {
				WP_CLI::log( '' );
				WP_CLI::log( '--- Orphaned Posts (no inbound internal links) ---' );
			}

			$this->format_items(
				$result['orphaned_posts'],
				array( 'post_id', 'title', 'outbound', 'permalink' ),
				$assoc_args
			);
		}

		// Top linked.
		if ( ( 'top' === $show || 'all' === $show ) && ! empty( $result['top_linked'] ) ) {
			if ( 'all' === $show && 'table' === $format ) {
				WP_CLI::log( '' );
				WP_CLI::log( '--- Top Linked Posts (most inbound) ---' );
			}

			$this->format_items(
				$result['top_linked'],
				array( 'post_id', 'title', 'inbound', 'outbound' ),
				$assoc_args
			);
		}

		if ( 'table' === $format ) {
			WP_CLI::success( sprintf( 'Audit complete: %d posts scanned, %d internal links found.', $total, $links ) );
		}
	}

	/**
	 * List orphaned posts with zero inbound internal links.
	 *
	 * Reads from the cached link graph. Runs a full audit automatically
	 * if no cache exists.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to check.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum orphaned posts to return.
	 * ---
	 * default: 50
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
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List orphaned posts
	 *     wp datamachine links orphans
	 *
	 *     # Limit to 10 results
	 *     wp datamachine links orphans --limit=10
	 *
	 *     # JSON output
	 *     wp datamachine links orphans --format=json
	 *
	 * @subcommand orphans
	 */
	public function orphans( array $args, array $assoc_args ): void {
		$format    = $assoc_args['format'] ?? 'table';
		$post_type = $assoc_args['post_type'] ?? 'post';
		$limit     = absint( $assoc_args['limit'] ?? 50 );

		$result = InternalLinkingAbilities::getOrphanedPosts(
			array(
				'post_type' => $post_type,
				'limit'     => $limit,
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get orphaned posts.' );
			return;
		}

		$orphaned_count = (int) ( $result['orphaned_count'] ?? 0 );
		$total_scanned  = (int) ( $result['total_scanned'] ?? 0 );
		$from_cache     = $result['from_cache'] ?? false;

		if ( $from_cache ) {
			WP_CLI::log( 'Reading from cached link graph.' );
		} else {
			WP_CLI::log( 'No cache found — ran full audit.' );
		}

		if ( 'json' === $format ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( empty( $result['orphaned_posts'] ) ) {
			WP_CLI::success( sprintf( 'No orphaned posts found out of %d scanned.', $total_scanned ) );
			return;
		}

		$this->format_items(
			$result['orphaned_posts'],
			array( 'post_id', 'title', 'outbound', 'permalink' ),
			$assoc_args
		);

		WP_CLI::success( sprintf( '%d orphaned post(s) out of %d scanned.', $orphaned_count, $total_scanned ) );
	}

	/**
	 * Check for broken internal links via HTTP HEAD requests.
	 *
	 * Reads link URLs from the cached graph and performs HTTP HEAD
	 * requests to detect broken links. Expensive operation.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type scope.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum unique URLs to check.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--timeout=<seconds>]
	 * : HTTP timeout per request in seconds.
	 * ---
	 * default: 5
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
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Check for broken links
	 *     wp datamachine links broken
	 *
	 *     # Limit to 50 URL checks
	 *     wp datamachine links broken --limit=50
	 *
	 *     # JSON output
	 *     wp datamachine links broken --format=json
	 *
	 * @subcommand broken
	 */
	public function broken( array $args, array $assoc_args ): void {
		$format    = $assoc_args['format'] ?? 'table';
		$post_type = $assoc_args['post_type'] ?? 'post';
		$limit     = absint( $assoc_args['limit'] ?? 200 );
		$timeout   = absint( $assoc_args['timeout'] ?? 5 );

		WP_CLI::log( sprintf( 'Checking up to %d unique URLs for broken links...', $limit ) );

		$result = InternalLinkingAbilities::checkBrokenLinks(
			array(
				'post_type' => $post_type,
				'limit'     => $limit,
				'timeout'   => $timeout,
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to check broken links.' );
			return;
		}

		$urls_checked = (int) ( $result['urls_checked'] ?? 0 );
		$broken_count = (int) ( $result['broken_count'] ?? 0 );
		$from_cache   = $result['from_cache'] ?? false;

		if ( $from_cache ) {
			WP_CLI::log( 'Link graph loaded from cache.' );
		} else {
			WP_CLI::log( 'No cache found — ran full audit first.' );
		}

		if ( 'json' === $format ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( empty( $result['broken_links'] ) ) {
			WP_CLI::success( sprintf( 'No broken links found (%d URLs checked).', $urls_checked ) );
			return;
		}

		$this->format_items(
			$result['broken_links'],
			array( 'source_id', 'source_title', 'broken_url', 'status_code' ),
			$assoc_args
		);

		WP_CLI::warning( sprintf( '%d broken link(s) found across %d URLs checked.', $broken_count, $urls_checked ) );
	}
}
