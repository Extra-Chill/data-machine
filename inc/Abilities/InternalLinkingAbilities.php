<?php
/**
 * Internal Linking Abilities
 *
 * Ability endpoints for AI-powered internal link insertion and diagnostics.
 * Delegates async execution to the System Agent infrastructure.
 *
 * Five abilities:
 * - datamachine/internal-linking      — Queue system agent link insertion.
 * - datamachine/diagnose-internal-links — Meta-based coverage report.
 * - datamachine/audit-internal-links   — Scan content, build + cache link graph.
 * - datamachine/get-orphaned-posts     — Read orphaned posts from cached graph.
 * - datamachine/check-broken-links     — HTTP HEAD checks on cached graph links.
 *
 * @package DataMachine\Abilities
 * @since 0.24.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\SystemAgent;

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

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/internal-linking',
				array(
					'label'               => 'Internal Linking',
					'description'         => 'Queue system agent insertion of semantic internal links into posts',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_ids'       => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Post IDs to process',
							),
							'category'       => array(
								'type'        => 'string',
								'description' => 'Category slug to process all posts from',
							),
							'links_per_post' => array(
								'type'        => 'integer',
								'description' => 'Maximum internal links to insert per post',
								'default'     => 3,
							),
							'dry_run'        => array(
								'type'        => 'boolean',
								'description' => 'Preview which posts would be queued without processing',
								'default'     => false,
							),
							'force'          => array(
								'type'        => 'boolean',
								'description' => 'Force re-processing even if already linked',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'queued_count' => array( 'type' => 'integer' ),
							'post_ids'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'message'      => array( 'type' => 'string' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'queueInternalLinking' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/diagnose-internal-links',
				array(
					'label'               => 'Diagnose Internal Links',
					'description'         => 'Report internal link coverage across published posts',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'             => array( 'type' => 'boolean' ),
							'total_posts'         => array( 'type' => 'integer' ),
							'posts_with_links'    => array( 'type' => 'integer' ),
							'posts_without_links' => array( 'type' => 'integer' ),
							'avg_links_per_post'  => array( 'type' => 'number' ),
							'by_category'         => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'execute_callback'    => array( self::class, 'diagnoseInternalLinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/audit-internal-links',
				array(
					'label'               => 'Audit Internal Links',
					'description'         => 'Scan post content for internal links, build a link graph, and cache results. Does NOT check for broken links — use datamachine/check-broken-links for that.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type to audit. Default: post.',
								'default'     => 'post',
							),
							'category'  => array(
								'type'        => 'string',
								'description' => 'Category slug to limit audit scope.',
							),
							'post_ids'  => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Specific post IDs to audit.',
							),
							'force'     => array(
								'type'        => 'boolean',
								'description' => 'Force rebuild even if cached graph exists.',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'total_scanned'  => array( 'type' => 'integer' ),
							'total_links'    => array( 'type' => 'integer' ),
							'orphaned_count' => array( 'type' => 'integer' ),
							'avg_outbound'   => array( 'type' => 'number' ),
							'avg_inbound'    => array( 'type' => 'number' ),
							'orphaned_posts' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'top_linked'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'cached'         => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'auditInternalLinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/get-orphaned-posts',
				array(
					'label'               => 'Get Orphaned Posts',
					'description'         => 'Return posts with zero inbound internal links from the cached link graph. Runs audit automatically if no cache exists.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type to check. Default: post.',
								'default'     => 'post',
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => 'Maximum orphaned posts to return. Default: 50.',
								'default'     => 50,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'orphaned_count' => array( 'type' => 'integer' ),
							'total_scanned'  => array( 'type' => 'integer' ),
							'orphaned_posts' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'from_cache'     => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'getOrphanedPosts' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/check-broken-links',
				array(
					'label'               => 'Check Broken Links',
					'description'         => 'HTTP HEAD check internal links from the cached link graph to find broken URLs. Expensive — runs audit first if no cache.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type scope. Default: post.',
								'default'     => 'post',
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => 'Maximum URLs to check. Default: 200.',
								'default'     => 200,
							),
							'timeout'   => array(
								'type'        => 'integer',
								'description' => 'HTTP timeout per request in seconds. Default: 5.',
								'default'     => 5,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'urls_checked' => array( 'type' => 'integer' ),
							'broken_count' => array( 'type' => 'integer' ),
							'broken_links' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'from_cache'   => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'checkBrokenLinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Queue internal linking for posts.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function queueInternalLinking( array $input ): array {
		$post_ids       = array_map( 'absint', $input['post_ids'] ?? array() );
		$category       = sanitize_text_field( $input['category'] ?? '' );
		$links_per_post = absint( $input['links_per_post'] ?? 3 );
		$dry_run        = ! empty( $input['dry_run'] );
		$force          = ! empty( $input['force'] );

		$system_defaults = PluginSettings::getAgentModel( 'system' );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'No default AI provider/model configured.',
				'error'        => 'Configure default_provider and default_model in Data Machine settings.',
			);
		}

		// Resolve category to post IDs.
		if ( ! empty( $category ) ) {
			$term = get_term_by( 'slug', $category, 'category' );
			if ( ! $term ) {
				return array(
					'success'      => false,
					'queued_count' => 0,
					'post_ids'     => array(),
					'message'      => "Category '{$category}' not found.",
					'error'        => 'Invalid category slug',
				);
			}

			$cat_posts = get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'category'    => $term->term_id,
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			);

			$post_ids = array_merge( $post_ids, $cat_posts );
		}

		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'No post IDs provided or resolved.',
				'error'        => 'Missing required parameter: post_ids or category',
			);
		}

		if ( $dry_run ) {
			return array(
				'success'      => true,
				'queued_count' => count( $post_ids ),
				'post_ids'     => $post_ids,
				'message'      => sprintf( 'Dry run: %d post(s) would be queued for internal linking.', count( $post_ids ) ),
			);
		}

		// Filter to eligible posts.
		$eligible = array();
		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( $post && 'publish' === $post->post_status ) {
				$eligible[] = $pid;
			}
		}

		if ( empty( $eligible ) ) {
			return array(
				'success'      => true,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'No eligible published posts found.',
			);
		}

		// Build per-item params for batch scheduling.
		$item_params = array();
		foreach ( $eligible as $pid ) {
			$item_params[] = array(
				'post_id'        => $pid,
				'links_per_post' => $links_per_post,
				'force'          => $force,
				'source'         => 'ability',
			);
		}

		$systemAgent = SystemAgent::getInstance();
		$batch       = $systemAgent->scheduleBatch( 'internal_linking', $item_params );

		if ( false === $batch ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'Failed to schedule batch.',
				'error'        => 'System Agent batch scheduling failed.',
			);
		}

		return array(
			'success'      => true,
			'queued_count' => count( $eligible ),
			'post_ids'     => $eligible,
			'batch_id'     => $batch['batch_id'] ?? null,
			'message'      => sprintf(
				'Internal linking batch scheduled for %d post(s) (chunks of %d).',
				count( $eligible ),
				$batch['chunk_size'] ?? SystemAgent::BATCH_CHUNK_SIZE
			),
		);
	}

	/**
	 * Diagnose internal link coverage across published posts.
	 *
	 * @param array $input Ability input (unused).
	 * @return array Ability response.
	 */
	public static function diagnoseInternalLinks( array $input = array() ): array {
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
	 * Check for broken internal links via HTTP HEAD requests.
	 *
	 * Reads all internal link URLs from the cached graph and performs
	 * HEAD requests to find broken ones. Expensive — isolated from audit.
	 *
	 * @since 0.32.0
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function checkBrokenLinks( array $input = array() ): array {
		$post_type  = sanitize_text_field( $input['post_type'] ?? 'post' );
		$limit      = absint( $input['limit'] ?? 200 );
		$timeout    = absint( $input['timeout'] ?? 5 );
		$from_cache = true;

		$graph = get_transient( self::GRAPH_TRANSIENT_KEY );
		if ( false === $graph || ! is_array( $graph ) || ( $graph['post_type'] ?? '' ) !== $post_type ) {
			$graph = self::buildLinkGraph( $post_type, '', array() );
			if ( isset( $graph['error'] ) ) {
				return $graph;
			}
			set_transient( self::GRAPH_TRANSIENT_KEY, $graph, self::GRAPH_CACHE_TTL );
			$from_cache = false;
		}

		$all_links = $graph['_all_links'] ?? array();

		// Deduplicate URLs to check.
		$url_sources   = array(); // url => array of source post IDs.
		$checked_count = 0;

		foreach ( $all_links as $link ) {
			$url = $link['target_url'] ?? '';
			if ( empty( $url ) ) {
				continue;
			}
			if ( ! isset( $url_sources[ $url ] ) ) {
				$url_sources[ $url ] = array();
			}
			$url_sources[ $url ][] = $link['source_id'] ?? 0;
		}

		$broken       = array();
		$broken_count = 0;
		$id_to_title  = $graph['_id_to_title'] ?? array();

		foreach ( $url_sources as $url => $source_ids ) {
			if ( $limit > 0 && $checked_count >= $limit ) {
				break;
			}

			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => $timeout,
					'redirection' => 3,
				)
			);
			++$checked_count;

			$status = wp_remote_retrieve_response_code( $response );
			$is_ok  = $status >= 200 && $status < 400;

			if ( ! $is_ok ) {
				foreach ( array_unique( $source_ids ) as $source_id ) {
					++$broken_count;
					$broken[] = array(
						'source_id'    => $source_id,
						'source_title' => $id_to_title[ $source_id ] ?? '',
						'broken_url'   => $url,
						'status_code'  => $status ? $status : 0,
					);
				}
			}
		}

		return array(
			'success'      => true,
			'urls_checked' => $checked_count,
			'broken_count' => $broken_count,
			'broken_links' => $broken,
			'from_cache'   => $from_cache,
		);
	}

	/**
	 * Build the internal link graph by scanning post content.
	 *
	 * Shared logic used by audit, get-orphaned-posts, and check-broken-links.
	 * Returns the full graph data structure suitable for caching.
	 *
	 * @since 0.32.0
	 *
	 * @param string $post_type    Post type to scan.
	 * @param string $category     Category slug to filter by.
	 * @param array  $specific_ids Specific post IDs to scan.
	 * @return array Graph data structure.
	 */
	private static function buildLinkGraph( string $post_type, string $category, array $specific_ids ): array {
		global $wpdb;

		$home_url  = home_url();
		$home_host = wp_parse_url( $home_url, PHP_URL_HOST );

		// Build the query for posts to scan.
		if ( ! empty( $specific_ids ) ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $specific_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					WHERE ID IN ($id_placeholders) AND post_status = %s",
					array_merge( $specific_ids, array( 'publish' ) )
				)
			);
		} elseif ( ! empty( $category ) ) {
			$term = get_term_by( 'slug', $category, 'category' );
			if ( ! $term ) {
				return array(
					'success' => false,
					'error'   => "Category '{$category}' not found.",
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_content
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE p.post_type = %s AND p.post_status = %s
					AND tt.taxonomy = %s AND tt.term_id = %d",
					$post_type,
					'publish',
					'category',
					$term->term_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					WHERE post_type = %s AND post_status = %s",
					$post_type,
					'publish'
				)
			);
		}

		if ( empty( $posts ) ) {
			return array(
				'success'        => true,
				'post_type'      => $post_type,
				'total_scanned'  => 0,
				'total_links'    => 0,
				'orphaned_count' => 0,
				'avg_outbound'   => 0,
				'avg_inbound'    => 0,
				'orphaned_posts' => array(),
				'top_linked'     => array(),
				'_all_links'     => array(),
				'_id_to_title'   => array(),
			);
		}

		// Build a lookup of all scanned post URLs -> IDs.
		$url_to_id   = array();
		$id_to_url   = array();
		$id_to_title = array();

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post->ID );
			if ( $permalink ) {
				$url_to_id[ untrailingslashit( $permalink ) ] = $post->ID;
				$url_to_id[ trailingslashit( $permalink ) ]   = $post->ID;
				$id_to_url[ $post->ID ]                       = $permalink;
			}
			$id_to_title[ $post->ID ] = $post->post_title;
		}

		// Scan each post's content for internal links.
		$outbound    = array(); // post_id => array of target post_ids.
		$inbound     = array(); // post_id => count of inbound links.
		$all_links   = array(); // all discovered internal link entries.
		$total_links = 0;

		// Initialize inbound counts.
		foreach ( $posts as $post ) {
			$inbound[ $post->ID ]  = 0;
			$outbound[ $post->ID ] = array();
		}

		foreach ( $posts as $post ) {
			$content = $post->post_content;
			if ( empty( $content ) ) {
				continue;
			}

			$links = self::extractInternalLinks( $content, $home_host );

			foreach ( $links as $link_url ) {
				++$total_links;
				$normalized = untrailingslashit( $link_url );

				// Resolve to a post ID if possible.
				$target_id = $url_to_id[ $normalized ] ?? $url_to_id[ trailingslashit( $link_url ) ] ?? null;

				if ( null === $target_id ) {
					// Try url_to_postid as fallback for non-standard URLs.
					$target_id = url_to_postid( $link_url );
					if ( 0 === $target_id ) {
						$target_id = null;
					}
				}

				if ( null !== $target_id && $target_id !== $post->ID ) {
					$outbound[ $post->ID ][] = $target_id;

					if ( isset( $inbound[ $target_id ] ) ) {
						++$inbound[ $target_id ];
					}
				}

				$all_links[] = array(
					'source_id'  => $post->ID,
					'target_url' => $link_url,
					'target_id'  => $target_id,
					'resolved'   => null !== $target_id,
				);
			}
		}

		// Identify orphaned posts (zero inbound links from other scanned posts).
		$orphaned = array();
		foreach ( $inbound as $post_id => $count ) {
			if ( 0 === $count ) {
				$orphaned[] = array(
					'post_id'   => $post_id,
					'title'     => $id_to_title[ $post_id ] ?? '',
					'permalink' => $id_to_url[ $post_id ] ?? '',
					'outbound'  => count( $outbound[ $post_id ] ?? array() ),
				);
			}
		}

		// Top linked posts (most inbound).
		arsort( $inbound );
		$top_linked = array();
		$top_count  = 0;
		foreach ( $inbound as $post_id => $count ) {
			if ( 0 === $count || $top_count >= 20 ) {
				break;
			}
			$top_linked[] = array(
				'post_id'   => $post_id,
				'title'     => $id_to_title[ $post_id ] ?? '',
				'permalink' => $id_to_url[ $post_id ] ?? '',
				'inbound'   => $count,
				'outbound'  => count( $outbound[ $post_id ] ?? array() ),
			);
			++$top_count;
		}

		$total_scanned  = count( $posts );
		$outbound_total = array_sum( array_map( 'count', $outbound ) );
		$inbound_total  = array_sum( $inbound );

		return array(
			'success'        => true,
			'post_type'      => $post_type,
			'total_scanned'  => $total_scanned,
			'total_links'    => $total_links,
			'orphaned_count' => count( $orphaned ),
			'avg_outbound'   => $total_scanned > 0 ? round( $outbound_total / $total_scanned, 2 ) : 0,
			'avg_inbound'    => $total_scanned > 0 ? round( $inbound_total / $total_scanned, 2 ) : 0,
			'orphaned_posts' => $orphaned,
			'top_linked'     => $top_linked,
			// Internal data for broken link checker (not exposed in REST).
			'_all_links'     => $all_links,
			'_id_to_title'   => $id_to_title,
		);
	}

	/**
	 * Extract internal link URLs from HTML content.
	 *
	 * Uses regex to find all <a href="..."> tags where the href points to
	 * the same host as the site. Ignores anchors, mailto, tel, and external links.
	 *
	 * @since 0.32.0
	 *
	 * @param string $html      HTML content to parse.
	 * @param string $home_host Site hostname for comparison.
	 * @return array Array of internal link URLs.
	 */
	private static function extractInternalLinks( string $html, string $home_host ): array {
		$links = array();

		// Match all href attributes in anchor tags.
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>/i', $html, $matches ) ) {
			return $links;
		}

		foreach ( $matches[1] as $url ) {
			// Skip non-http URLs.
			if ( preg_match( '/^(mailto:|tel:|javascript:|data:)/i', $url ) ) {
				continue;
			}

			// Handle relative URLs.
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				$url = home_url( $url );
			}

			// Parse and check host.
			$parsed = wp_parse_url( $url );
			$host   = $parsed['host'] ?? '';

			if ( empty( $host ) || strcasecmp( $host, $home_host ) !== 0 ) {
				continue;
			}

			// Strip query string and fragment for normalization.
			$clean_url = $parsed['scheme'] . '://' . $parsed['host'];
			if ( ! empty( $parsed['path'] ) ) {
				$clean_url .= $parsed['path'];
			}

			$links[] = $clean_url;
		}

		return array_unique( $links );
	}
}
