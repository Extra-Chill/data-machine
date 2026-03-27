<?php
/**
 * Internal Linking Abilities
 *
 * Ability endpoints for AI-powered internal link insertion and diagnostics.
 * Delegates async execution to the System Agent infrastructure.
 *
 * Six abilities:
 * - datamachine/internal-linking        — Queue system agent link insertion.
 * - datamachine/diagnose-internal-links — Meta-based coverage report.
 * - datamachine/audit-internal-links    — Scan content, build + cache link graph.
 * - datamachine/get-orphaned-posts      — Read orphaned posts from cached graph.
 * - datamachine/check-broken-links      — HTTP HEAD checks on cached graph links.
 * - datamachine/link-opportunities      — Ranked linking opportunities from GSC + link graph.
 *
 * @package DataMachine\Abilities
 * @since 0.24.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\Tasks\TaskScheduler;

defined( 'ABSPATH' ) || exit;

class InternalLinkingAbilities {

	/**
	 * Transient key for the cached link graph.
	 */
	const GRAPH_TRANSIENT_KEY = 'datamachine_link_graph';

	/**
	 * Cache TTL: 24 hours.
	 */
	const GRAPH_CACHE_TTL = DAY_IN_SECONDS;

	private static bool $registered = false;

	/**
	 * Diagnose internal link coverage across published posts.
	 *
	 * @param array $input Ability input (unused).
	 * @return array Ability response.
	 */
	public static function diagnoseInternalLinks( array $input = array() ): array {
		$input;
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'post',
				'publish'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts_with_links = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m
					ON p.ID = m.post_id AND m.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status = %s
				AND m.meta_value != ''
				AND m.meta_value IS NOT NULL",
				'_datamachine_internal_links',
				'post',
				'publish'
			)
		);

		$posts_without_links = $total_posts - $posts_with_links;

		// Calculate average links per post from tracked meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_meta = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.meta_value
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m
					ON p.ID = m.post_id AND m.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status = %s
				AND m.meta_value != ''",
				'_datamachine_internal_links',
				'post',
				'publish'
			)
		);

		$total_links = 0;
		foreach ( $all_meta as $meta_value ) {
			$data = maybe_unserialize( $meta_value );
			if ( is_array( $data ) && isset( $data['links'] ) && is_array( $data['links'] ) ) {
				$total_links += count( $data['links'] );
			}
		}

		$avg_links = $posts_with_links > 0 ? round( $total_links / $posts_with_links, 2 ) : 0;

		// Breakdown by category.
		$categories  = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
			)
		);
		$by_category = array();

		if ( is_array( $categories ) ) {
			foreach ( $categories as $cat ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$cat_total = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT p.ID)
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
						INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
						WHERE p.post_type = %s
						AND p.post_status = %s
						AND tt.taxonomy = %s
						AND tt.term_id = %d",
						'post',
						'publish',
						'category',
						$cat->term_id
					)
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$cat_with = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT p.ID)
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
						INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
						INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = %s
						WHERE p.post_type = %s
						AND p.post_status = %s
						AND tt.taxonomy = %s
						AND tt.term_id = %d
						AND m.meta_value != ''
						AND m.meta_value IS NOT NULL",
						'_datamachine_internal_links',
						'post',
						'publish',
						'category',
						$cat->term_id
					)
				);

				$by_category[] = array(
					'category'      => $cat->name,
					'slug'          => $cat->slug,
					'total_posts'   => $cat_total,
					'with_links'    => $cat_with,
					'without_links' => $cat_total - $cat_with,
				);
			}
		}

		return array(
			'success'             => true,
			'total_posts'         => $total_posts,
			'posts_with_links'    => $posts_with_links,
			'posts_without_links' => $posts_without_links,
			'avg_links_per_post'  => $avg_links,
			'by_category'         => $by_category,
		);
	}

	/**
	 * Audit internal links by scanning actual post content.
	 *
	 * Parses rendered post HTML for <a> tags pointing to internal URLs,
	 * builds an outbound/inbound link graph, identifies orphaned posts,
	 * and caches the result as a transient (24hr TTL).
	 *
	 * @since 0.32.0
	 *
	 * @param array $input Ability input.
	 * @return array Ability response with link graph data.
	 */
	public static function auditInternalLinks( array $input = array() ): array {
		$post_type    = sanitize_text_field( $input['post_type'] ?? 'post' );
		$category     = sanitize_text_field( $input['category'] ?? '' );
		$specific_ids = array_map( 'absint', $input['post_ids'] ?? array() );
		$force        = ! empty( $input['force'] );

		// Check cache unless forced or scoped to specific posts/category.
		$is_scoped = ! empty( $specific_ids ) || ! empty( $category );
		if ( ! $force && ! $is_scoped ) {
			$cached = get_transient( self::GRAPH_TRANSIENT_KEY );
			if ( false !== $cached && is_array( $cached ) && ( $cached['post_type'] ?? '' ) === $post_type ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$graph = self::buildLinkGraph( $post_type, $category, $specific_ids );

		if ( isset( $graph['error'] ) ) {
			return $graph;
		}

		// Cache the full graph if this was an unscoped audit.
		if ( ! $is_scoped ) {
			set_transient( self::GRAPH_TRANSIENT_KEY, $graph, self::GRAPH_CACHE_TTL );
		}

		$graph['cached'] = false;
		return $graph;
	}

	/**
	 * Get orphaned posts from cached link graph.
	 *
	 * Reads from the transient cache. If no cache exists, runs a full audit first.
	 *
	 * @since 0.32.0
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function getOrphanedPosts( array $input = array() ): array {
		$post_type  = sanitize_text_field( $input['post_type'] ?? 'post' );
		$limit      = absint( $input['limit'] ?? 50 );
		$from_cache = true;

		$graph = get_transient( self::GRAPH_TRANSIENT_KEY );
		if ( false === $graph || ! is_array( $graph ) || ( $graph['post_type'] ?? '' ) !== $post_type ) {
			// No cache — run audit.
			$graph = self::buildLinkGraph( $post_type, '', array() );
			if ( isset( $graph['error'] ) ) {
				return $graph;
			}
			set_transient( self::GRAPH_TRANSIENT_KEY, $graph, self::GRAPH_CACHE_TTL );
			$from_cache = false;
		}

		$orphaned = $graph['orphaned_posts'] ?? array();
		if ( $limit > 0 && count( $orphaned ) > $limit ) {
			$orphaned = array_slice( $orphaned, 0, $limit );
		}

		return array(
			'success'        => true,
			'orphaned_count' => count( $graph['orphaned_posts'] ?? array() ),
			'total_scanned'  => $graph['total_scanned'] ?? 0,
			'orphaned_posts' => $orphaned,
			'from_cache'     => $from_cache,
		);
	}

	/**
	 * Check for broken links via HTTP HEAD requests.
	 *
	 * Supports internal links (default), external links, or both via scope param.
	 * External checks include per-domain rate limiting, HEAD→GET fallback for
	 * sites that block HEAD, and anchor text in results.
	 *
	 * @since 0.32.0
	 * @since 0.42.0 Added scope parameter for external link checking.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function checkBrokenLinks( array $input = array() ): array {
		$post_type  = sanitize_text_field( $input['post_type'] ?? 'post' );
		$limit      = absint( $input['limit'] ?? 200 );
		$timeout    = absint( $input['timeout'] ?? 5 );
		$scope      = sanitize_text_field( $input['scope'] ?? 'internal' );
		$from_cache = true;

		if ( ! in_array( $scope, array( 'internal', 'external', 'all' ), true ) ) {
			$scope = 'internal';
		}

		$graph = get_transient( self::GRAPH_TRANSIENT_KEY );
		if ( false === $graph || ! is_array( $graph ) || ( $graph['post_type'] ?? '' ) !== $post_type ) {
			$graph = self::buildLinkGraph( $post_type, '', array() );
			if ( isset( $graph['error'] ) ) {
				return $graph;
			}
			set_transient( self::GRAPH_TRANSIENT_KEY, $graph, self::GRAPH_CACHE_TTL );
			$from_cache = false;
		}

		// Build URL → source mapping based on scope.
		$url_sources = array(); // url => array of {source_id, anchor_text}.
		$id_to_title = $graph['_id_to_title'] ?? array();

		if ( 'internal' === $scope || 'all' === $scope ) {
			$all_links = $graph['_all_links'] ?? array();
			foreach ( $all_links as $link ) {
				$url = $link['target_url'] ?? '';
				if ( empty( $url ) ) {
					continue;
				}
				if ( ! isset( $url_sources[ $url ] ) ) {
					$url_sources[ $url ] = array();
				}
				$url_sources[ $url ][] = array(
					'source_id'   => $link['source_id'] ?? 0,
					'anchor_text' => '',
				);
			}
		}

		if ( 'external' === $scope || 'all' === $scope ) {
			$all_external = $graph['_all_external_links'] ?? array();
			foreach ( $all_external as $link ) {
				$url = $link['target_url'] ?? '';
				if ( empty( $url ) ) {
					continue;
				}
				if ( ! isset( $url_sources[ $url ] ) ) {
					$url_sources[ $url ] = array();
				}
				$url_sources[ $url ][] = array(
					'source_id'   => $link['source_id'] ?? 0,
					'anchor_text' => $link['anchor_text'] ?? '',
				);
			}
		}

		$broken        = array();
		$broken_count  = 0;
		$checked_count = 0;
		$is_external   = 'external' === $scope || 'all' === $scope;

		// Per-domain rate limiting for external checks (tracks last request time).
		$domain_last_request = array();
		$rate_limit_delay    = 1; // Minimum seconds between requests to same domain.

		foreach ( $url_sources as $url => $sources ) {
			if ( $limit > 0 && $checked_count >= $limit ) {
				break;
			}

			// Per-domain rate limiting for external URLs.
			if ( $is_external ) {
				$domain = wp_parse_url( $url, PHP_URL_HOST );
				if ( $domain && isset( $domain_last_request[ $domain ] ) ) {
					$elapsed = microtime( true ) - $domain_last_request[ $domain ];
					if ( $elapsed < $rate_limit_delay ) {
						usleep( (int) ( ( $rate_limit_delay - $elapsed ) * 1000000 ) );
					}
				}
			}

			$status = self::checkUrlStatus( $url, $timeout, $is_external );
			++$checked_count;

			if ( $is_external ) {
				$domain = wp_parse_url( $url, PHP_URL_HOST );
				if ( $domain ) {
					$domain_last_request[ $domain ] = microtime( true );
				}
			}

			$is_ok = $status >= 200 && $status < 400;

			if ( ! $is_ok ) {
				// Deduplicate source IDs for this URL.
				$seen_sources = array();
				foreach ( $sources as $source ) {
					$source_id = $source['source_id'] ?? 0;
					if ( isset( $seen_sources[ $source_id ] ) ) {
						continue;
					}
					$seen_sources[ $source_id ] = true;

					++$broken_count;
					$broken[] = array(
						'source_id'    => $source_id,
						'source_title' => $id_to_title[ $source_id ] ?? get_the_title( $source_id ),
						'broken_url'   => $url,
						'status_code'  => $status,
						'anchor_text'  => $source['anchor_text'] ?? '',
					);
				}
			}
		}

		return array(
			'success'      => true,
			'scope'        => $scope,
			'urls_checked' => $checked_count,
			'broken_count' => $broken_count,
			'broken_links' => $broken,
			'from_cache'   => $from_cache,
		);
	}

	/**
	 * Get ranked internal linking opportunities by combining GSC traffic with link graph.
	 *
	 * Pages with high search traffic but few inbound internal links are the best
	 * candidates for internal linking. Score = clicks * (1 / (inbound_links + 1)).
	 *
	 * @since 0.43.0
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function getLinkOpportunities( array $input = array() ): array {
		$limit      = absint( $input['limit'] ?? 20 );
		$category   = sanitize_text_field( $input['category'] ?? '' );
		$min_clicks = absint( $input['min_clicks'] ?? 5 );
		$days       = absint( $input['days'] ?? 28 );

		// 1. Load the link graph from transient (run audit if not cached).
		$graph = get_transient( self::GRAPH_TRANSIENT_KEY );
		if ( false === $graph || ! is_array( $graph ) ) {
			$graph = self::buildLinkGraph( 'post', '', array() );
			if ( isset( $graph['error'] ) ) {
				return array(
					'success' => false,
					'error'   => 'Failed to build link graph: ' . $graph['error'],
				);
			}
			set_transient( self::GRAPH_TRANSIENT_KEY, $graph, self::GRAPH_CACHE_TTL );
		}

		// Build inbound/outbound counts from the link graph's _all_links data.
		$inbound_counts  = array();
		$outbound_counts = array();

		// Initialize from orphaned_posts (0 inbound).
		foreach ( $graph['orphaned_posts'] ?? array() as $orphan ) {
			$pid = $orphan['post_id'] ?? 0;
			if ( $pid > 0 ) {
				$inbound_counts[ $pid ] = 0;
			}
		}

		// Initialize from top_linked (has inbound count).
		foreach ( $graph['top_linked'] ?? array() as $top ) {
			$pid = $top['post_id'] ?? 0;
			if ( $pid > 0 ) {
				$inbound_counts[ $pid ] = (int) ( $top['inbound'] ?? 0 );
			}
		}

		// Rebuild full counts from _all_links for accuracy.
		$all_links = $graph['_all_links'] ?? array();
		foreach ( $all_links as $link ) {
			$source_id = $link['source_id'] ?? 0;
			$target_id = $link['target_id'] ?? null;

			if ( $source_id > 0 ) {
				if ( ! isset( $outbound_counts[ $source_id ] ) ) {
					$outbound_counts[ $source_id ] = 0;
				}
				++$outbound_counts[ $source_id ];
			}

			if ( null !== $target_id && $target_id > 0 && $target_id !== $source_id ) {
				if ( ! isset( $inbound_counts[ $target_id ] ) ) {
					$inbound_counts[ $target_id ] = 0;
				}
				++$inbound_counts[ $target_id ];
			}
		}

		// 2. Fetch GSC page stats via the ability.
		$gsc_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/google-search-console' ) : null;

		if ( ! $gsc_ability ) {
			return array(
				'success' => false,
				'error'   => 'Google Search Console ability not available. Ensure Data Machine analytics is configured.',
			);
		}

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = gmdate( 'Y-m-d', strtotime( '-3 days' ) );

		$gsc_result = $gsc_ability->execute(
			array(
				'action'     => 'page_stats',
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'limit'      => 5000,
			)
		);

		if ( empty( $gsc_result['success'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to fetch GSC data: ' . ( $gsc_result['error'] ?? 'Unknown error' ),
			);
		}

		$gsc_rows = $gsc_result['results'] ?? array();

		// 3. For each page with GSC traffic, look up post ID and count inbound links.
		$category_post_ids = null;
		if ( ! empty( $category ) ) {
			$term = get_term_by( 'slug', $category, 'category' );
			if ( ! $term ) {
				return array(
					'success' => false,
					'error'   => "Category '{$category}' not found.",
				);
			}
			$cat_posts         = get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'category'    => $term->term_id,
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			);
			$category_post_ids = array_flip( $cat_posts );
		}

		// Aggregate GSC rows by post ID (GSC may return multiple URL variants per page).
		$traffic_by_post = array();

		foreach ( $gsc_rows as $row ) {
			$keys   = $row['keys'] ?? array();
			$page   = $keys[0] ?? '';
			$clicks = (float) ( $row['clicks'] ?? 0 );

			if ( empty( $page ) ) {
				continue;
			}

			// Resolve URL to post ID.
			$post_id = url_to_postid( $page );
			if ( 0 === $post_id ) {
				continue;
			}

			// Aggregate traffic by post ID.
			if ( ! isset( $traffic_by_post[ $post_id ] ) ) {
				$traffic_by_post[ $post_id ] = array(
					'clicks'      => 0,
					'impressions' => 0,
					'position'    => 0,
					'row_count'   => 0,
				);
			}

			$traffic_by_post[ $post_id ]['clicks']      += (int) $clicks;
			$traffic_by_post[ $post_id ]['impressions'] += (int) ( $row['impressions'] ?? 0 );
			$traffic_by_post[ $post_id ]['position']    += (float) ( $row['position'] ?? 0 );
			++$traffic_by_post[ $post_id ]['row_count'];
		}

		$opportunities = array();

		foreach ( $traffic_by_post as $post_id => $traffic ) {
			if ( $traffic['clicks'] < $min_clicks ) {
				continue;
			}

			// Verify the post exists and is published.
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
				continue;
			}

			// Category filter.
			if ( null !== $category_post_ids && ! isset( $category_post_ids[ $post_id ] ) ) {
				continue;
			}

			$inbound  = $inbound_counts[ $post_id ] ?? 0;
			$outbound = $outbound_counts[ $post_id ] ?? 0;

			// Average position across URL variants.
			$avg_position = $traffic['row_count'] > 0 ? $traffic['position'] / $traffic['row_count'] : 0;

			// 4. Score: clicks * (1 / (inbound_links + 1)).
			$score = $traffic['clicks'] * ( 1 / ( $inbound + 1 ) );

			$opportunities[] = array(
				'score'          => round( $score, 2 ),
				'clicks'         => $traffic['clicks'],
				'impressions'    => $traffic['impressions'],
				'position'       => round( $avg_position, 1 ),
				'inbound_links'  => $inbound,
				'outbound_links' => $outbound,
				'post_id'        => $post_id,
				'slug'           => $post->post_name,
			);
		}

		// 5. Sort by opportunity_score descending.
		usort(
			$opportunities,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// 6. Limit results.
		if ( $limit > 0 && count( $opportunities ) > $limit ) {
			$opportunities = array_slice( $opportunities, 0, $limit );
		}

		return array(
			'success'            => true,
			'pages_with_traffic' => count( $gsc_rows ),
			'opportunities'      => $opportunities,
		);
	}

	// Removed: injectCategoryLinks, extractTitleKeywords, scoreRelatedPosts,
	// buildRelatedReadingBlock — replaced by InternalLinkingTask (v0.42.0).
	// Use `datamachine links crosslink` for AI-powered natural link insertion.
}
