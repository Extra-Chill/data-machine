<?php
/**
 * WP-CLI Links Command
 *
 * Provides CLI access to internal linking diagnostics and cross-linking.
 * Wraps InternalLinkingAbilities API primitives.
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
			$all_posts = get_posts( array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
				'numberposts' => -1,
			) );
			$post_ids  = array_merge( $post_ids, $all_posts );
		}

		if ( 0 === $post_id && empty( $category ) && ! $all ) {
			WP_CLI::error( 'Required: --post_id=<id>, --category=<slug>, or --all' );
			return;
		}

		$result = InternalLinkingAbilities::queueInternalLinking( array(
			'post_ids'       => $post_ids,
			'category'       => $category,
			'links_per_post' => $links_per_post,
			'dry_run'        => $dry_run,
			'force'          => $force,
		) );

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
	 * a link graph, identifies orphaned posts, and optionally detects
	 * broken links.
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
	 * [--detect-broken]
	 * : Check if linked URLs return 404.
	 *
	 * [--show=<section>]
	 * : Which section to display: summary, orphans, broken, top.
	 * ---
	 * default: summary
	 * options:
	 *   - summary
	 *   - orphans
	 *   - broken
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
	 *     # Detect broken internal links
	 *     wp datamachine links audit --detect-broken --show=broken
	 *
	 *     # Show top linked posts
	 *     wp datamachine links audit --show=top
	 *
	 *     # Full JSON output
	 *     wp datamachine links audit --show=all --format=json
	 *
	 * @subcommand audit
	 */
	public function audit( array $args, array $assoc_args ): void {
		$format        = $assoc_args['format'] ?? 'table';
		$show          = $assoc_args['show'] ?? 'summary';
		$detect_broken = isset( $assoc_args['detect-broken'] );
		$post_type     = $assoc_args['post_type'] ?? 'post';
		$category      = $assoc_args['category'] ?? '';

		$post_ids = array();
		if ( isset( $assoc_args['post_id'] ) ) {
			$post_ids = array_map( 'absint', explode( ',', $assoc_args['post_id'] ) );
		}

		WP_CLI::log( 'Scanning post content for internal links...' );

		$result = InternalLinkingAbilities::auditInternalLinks( array(
			'post_type'     => $post_type,
			'category'      => $category,
			'post_ids'      => $post_ids,
			'detect_broken' => $detect_broken,
		) );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Audit failed.' );
			return;
		}

		if ( 'json' === $format && 'all' === $show ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$total   = (int) ( $result['total_scanned'] ?? 0 );
		$links   = (int) ( $result['total_links'] ?? 0 );
		$orphans = (int) ( $result['orphaned_count'] ?? 0 );
		$broken  = (int) ( $result['broken_count'] ?? 0 );

		// Summary.
		if ( 'summary' === $show || 'all' === $show ) {
			if ( 'json' === $format ) {
				WP_CLI::line(
					\wp_json_encode(
						array(
							'total_scanned'  => $total,
							'total_links'    => $links,
							'orphaned_count' => $orphans,
							'broken_count'   => $broken,
							'avg_outbound'   => $result['avg_outbound'] ?? 0,
							'avg_inbound'    => $result['avg_inbound'] ?? 0,
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
					array(
						'metric' => 'Broken links',
						'value'  => $detect_broken ? $broken : 'skipped (use --detect-broken)',
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

		// Broken links.
		if ( ( 'broken' === $show || 'all' === $show ) && ! empty( $result['broken_links'] ) ) {
			if ( 'all' === $show && 'table' === $format ) {
				WP_CLI::log( '' );
				WP_CLI::log( '--- Broken Internal Links ---' );
			}

			$this->format_items(
				$result['broken_links'],
				array( 'source_id', 'source_title', 'broken_url', 'status_code' ),
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
}
