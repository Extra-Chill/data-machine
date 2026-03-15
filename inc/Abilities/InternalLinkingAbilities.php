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
					'description'         => 'HTTP HEAD check links from the cached link graph to find broken URLs. Supports internal, external, or all links via scope. External checks include per-domain rate limiting and HEAD→GET fallback.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type scope. Default: post.',
								'default'     => 'post',
							),
							'scope'     => array(
								'type'        => 'string',
								'description' => 'Link scope: internal, external, or all. Default: internal.',
								'enum'        => array( 'internal', 'external', 'all' ),
								'default'     => 'internal',
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => 'Maximum unique URLs to check. Default: 200.',
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
							'scope'        => array( 'type' => 'string' ),
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

			wp_register_ability(
				'datamachine/inject-category-links',
				array(
					'label'               => 'Inject Category Links',
					'description'         => 'Deterministic keyword-matching internal link injection within a single category. No AI calls. Appends a "Related Reading" section to each post.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'category' ),
						'properties' => array(
							'category'       => array(
								'type'        => 'string',
								'description' => 'Category slug to process.',
							),
							'links_per_post' => array(
								'type'        => 'integer',
								'description' => 'Maximum related links per post.',
								'default'     => 3,
							),
							'min_score'      => array(
								'type'        => 'integer',
								'description' => 'Minimum keyword overlap score. Use 0 to allow category-sibling fallback.',
								'default'     => 0,
							),
							'dry_run'        => array(
								'type'        => 'boolean',
								'description' => 'Preview without writing.',
								'default'     => false,
							),
							'orphans_only'   => array(
								'type'        => 'boolean',
								'description' => 'Only process posts with zero existing internal links.',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'category' => array( 'type' => 'string' ),
							'total'    => array( 'type' => 'integer' ),
							'injected' => array( 'type' => 'integer' ),
							'skipped'  => array( 'type' => 'integer' ),
							'dry_run'  => array( 'type' => 'boolean' ),
							'results'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'message'  => array( 'type' => 'string' ),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'injectCategoryLinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
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

		$user_id         = get_current_user_id();
		$agent_id        = function_exists( 'datamachine_resolve_or_create_agent_id' ) && $user_id > 0 ? datamachine_resolve_or_create_agent_id( $user_id ) : 0;
		$system_defaults = PluginSettings::resolveModelForAgentContext( $agent_id, 'system' );
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

		$batch = TaskScheduler::scheduleBatch(
			'internal_linking',
			$item_params,
			array(
				'user_id'  => $user_id,
				'agent_id' => $agent_id,
			)
		);

		if ( false === $batch ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'post_ids'     => array(),
				'message'      => 'Failed to schedule batch.',
				'error'        => 'Task batch scheduling failed.',
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
				$batch['chunk_size'] ?? TaskScheduler::BATCH_CHUNK_SIZE
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
	 * Check a single URL's HTTP status.
	 *
	 * Uses HEAD first for efficiency. For external URLs, falls back to GET
	 * with a range header when HEAD returns 405 or 403 (some servers block HEAD).
	 *
	 * @since 0.42.0
	 *
	 * @param string $url        URL to check.
	 * @param int    $timeout    Request timeout in seconds.
	 * @param bool   $is_external Whether this is an external URL (enables GET fallback).
	 * @return int HTTP status code (0 for connection failures/timeouts).
	 */
	private static function checkUrlStatus( string $url, int $timeout, bool $is_external ): int {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 3,
				'user-agent'  => 'DataMachine/LinkChecker (WordPress; +' . home_url() . ')',
			)
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$status = wp_remote_retrieve_response_code( $response );

		// Some external servers block HEAD requests — fall back to GET.
		if ( $is_external && ( 405 === $status || 403 === $status ) ) {
			$get_response = wp_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
					'redirection' => 3,
					'headers'     => array( 'Range' => 'bytes=0-0' ),
					'user-agent'  => 'DataMachine/LinkChecker (WordPress; +' . home_url() . ')',
				)
			);

			if ( ! is_wp_error( $get_response ) ) {
				$get_status = wp_remote_retrieve_response_code( $get_response );
				// 206 (Partial Content) means the server supports Range and the URL is alive.
				if ( 206 === $get_status || ( $get_status >= 200 && $get_status < 400 ) ) {
					return $get_status;
				}
				return $get_status;
			}
		}

		return $status ? $status : 0;
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
					// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					WHERE ID IN ($id_placeholders) AND post_status = %s",
					array_merge( $specific_ids, array( 'publish' ) )
				)
			);
					// phpcs:enable WordPress.DB.PreparedSQL
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

		$all_external_links = array();

		foreach ( $posts as $post ) {
			$content = $post->post_content;
			if ( empty( $content ) ) {
				continue;
			}

			// Internal links.
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

			// External links.
			$external = self::extractExternalLinks( $content, $home_host );
			foreach ( $external as $ext_link ) {
				$all_external_links[] = array(
					'source_id'   => $post->ID,
					'target_url'  => $ext_link['url'],
					'anchor_text' => $ext_link['anchor_text'],
					'domain'      => $ext_link['domain'],
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
			'_all_links'          => $all_links,
			'_all_external_links' => $all_external_links,
			'_id_to_title'        => $id_to_title,
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

	/**
	 * Extract external link URLs and anchor text from HTML content.
	 *
	 * Inverse of extractInternalLinks — keeps links pointing to hosts
	 * other than the site. Returns both URL and anchor text for reporting.
	 *
	 * @since 0.42.0
	 *
	 * @param string $html      HTML content to parse.
	 * @param string $home_host Site hostname for comparison.
	 * @return array Array of arrays with 'url' and 'anchor_text' keys.
	 */
	private static function extractExternalLinks( string $html, string $home_host ): array {
		$links = array();

		// Match href AND capture the full tag + inner text for anchor extraction.
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return $links;
		}

		$seen = array();

		foreach ( $matches as $match ) {
			$url         = $match[1];
			$anchor_text = wp_strip_all_tags( $match[2] );

			// Skip non-http URLs.
			if ( preg_match( '/^(mailto:|tel:|javascript:|data:)/i', $url ) ) {
				continue;
			}

			// Skip relative URLs (they're internal).
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				continue;
			}

			// Parse and check host — keep only external.
			$parsed = wp_parse_url( $url );
			$host   = $parsed['host'] ?? '';

			if ( empty( $host ) || strcasecmp( $host, $home_host ) === 0 ) {
				continue;
			}

			// Normalize URL (keep query string for external — different pages).
			$clean_url = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'];
			if ( ! empty( $parsed['path'] ) ) {
				$clean_url .= $parsed['path'];
			}
			if ( ! empty( $parsed['query'] ) ) {
				$clean_url .= '?' . $parsed['query'];
			}

			// Deduplicate by URL within a single post.
			if ( isset( $seen[ $clean_url ] ) ) {
				continue;
			}
			$seen[ $clean_url ] = true;

			$links[] = array(
				'url'         => $clean_url,
				'anchor_text' => trim( $anchor_text ),
				'domain'      => $host,
			);
		}

		return $links;
	}

	/**
	 * Inject "Related Reading" internal links for posts within a category.
	 *
	 * Deterministic keyword-matching approach — no AI calls.
	 * For each post, extracts significant keywords from the title, scores
	 * all other posts in the same category by keyword overlap, and appends
	 * a "Related Reading" section with the top matches.
	 *
	 * Skips posts that already have a "Related Reading" section.
	 *
	 * @since 0.34.0
	 *
	 * @param array $input {
	 *     @type string $category       Category slug (required).
	 *     @type int    $links_per_post Max related links per post (default 3).
	 *     @type int    $min_score      Minimum keyword overlap score (default 1).
	 *     @type bool   $dry_run        Preview without writing (default false).
	 *     @type bool   $orphans_only   Only process orphan posts — those with zero
	 *                                  existing internal links in content (default false).
	 * }
	 * @return array Ability response.
	 */
	public static function injectCategoryLinks( array $input ): array {
		$category       = sanitize_text_field( $input['category'] ?? '' );
		$links_per_post = absint( $input['links_per_post'] ?? 3 );
		$min_score      = absint( $input['min_score'] ?? 0 );
		$dry_run        = ! empty( $input['dry_run'] );
		$orphans_only   = ! empty( $input['orphans_only'] );

		if ( empty( $category ) ) {
			return array(
				'success' => false,
				'error'   => 'Required parameter: category (slug).',
			);
		}

		$term = get_term_by( 'slug', $category, 'category' );
		if ( ! $term ) {
			return array(
				'success' => false,
				'error'   => "Category '{$category}' not found.",
			);
		}

		// Get all published posts in this category.
		$posts = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'category'    => $term->term_id,
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			return array(
				'success'  => true,
				'category' => $term->name,
				'total'    => 0,
				'injected' => 0,
				'skipped'  => 0,
				'results'  => array(),
				'message'  => "No published posts in category '{$term->name}'.",
			);
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		// Build keyword index for all posts.
		$post_keywords = array();
		$post_map      = array(); // id => post object.
		foreach ( $posts as $post ) {
			$post_map[ $post->ID ]      = $post;
			$post_keywords[ $post->ID ] = self::extractTitleKeywords( $post->post_title );
		}

		$results  = array();
		$injected = 0;
		$skipped  = 0;

		foreach ( $posts as $post ) {
			$post_id = $post->ID;

			// Skip if already has a Related Reading section.
			if ( false !== strpos( $post->post_content, 'Related Reading' ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'status'  => 'skipped_has_section',
					'matches' => array(),
				);
				++$skipped;
				continue;
			}

			// If orphans_only, skip posts that already have internal links.
			if ( $orphans_only ) {
				$existing_links = self::extractInternalLinks( $post->post_content, $home_host );
				if ( ! empty( $existing_links ) ) {
					$results[] = array(
						'post_id' => $post_id,
						'title'   => $post->post_title,
						'status'  => 'skipped_has_links',
						'matches' => array(),
					);
					++$skipped;
					continue;
				}
			}

			// Score all other posts in category by keyword overlap.
			$scores = self::scoreRelatedPosts( $post_id, $post_keywords );

			// Filter by min score and take top N.
			$matches = array();
			foreach ( $scores as $match_id => $score ) {
				if ( $score < $min_score ) {
					continue;
				}
				$match_post = $post_map[ $match_id ] ?? null;
				if ( ! $match_post ) {
					continue;
				}
				$matches[] = array(
					'post_id'   => $match_id,
					'title'     => $match_post->post_title,
					'permalink' => get_permalink( $match_id ),
					'score'     => $score,
				);
				if ( count( $matches ) >= $links_per_post ) {
					break;
				}
			}

			// Fallback: if fewer matches than needed, fill with random
			// category siblings. Posts in the same category share a topic.
			if ( count( $matches ) < $links_per_post && 0 === $min_score ) {
				$used_ids    = array_column( $matches, 'post_id' );
				$used_ids[]  = $post_id;
				$sibling_ids = array_diff( array_keys( $post_map ), $used_ids );

				// Deterministic shuffle based on source post ID for consistency.
				// phpcs:disable WordPress.WP.AlternativeFunctions -- Intentional deterministic seeding for reproducible output.
				$sibling_list = array_values( $sibling_ids );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
				mt_srand( $post_id );
				shuffle( $sibling_list );
				mt_srand();
				// phpcs:enable WordPress.WP.AlternativeFunctions

				foreach ( $sibling_list as $sib_id ) {
					if ( count( $matches ) >= $links_per_post ) {
						break;
					}
					$sib_post = $post_map[ $sib_id ] ?? null;
					if ( ! $sib_post ) {
						continue;
					}
					$matches[] = array(
						'post_id'   => $sib_id,
						'title'     => $sib_post->post_title,
						'permalink' => get_permalink( $sib_id ),
						'score'     => 0,
					);
				}
			}

			if ( empty( $matches ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'status'  => 'skipped_no_matches',
					'matches' => array(),
				);
				++$skipped;
				continue;
			}

			// Build the Related Reading block HTML.
			$section = self::buildRelatedReadingBlock( $matches );

			if ( ! $dry_run ) {
				$updated_content = rtrim( $post->post_content ) . "\n\n" . $section;
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $updated_content,
					)
				);
			}

			$results[] = array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
				'status'  => $dry_run ? 'would_inject' : 'injected',
				'matches' => array_map(
					fn( $m ) => $m['title'] . ' (score: ' . $m['score'] . ')',
					$matches
				),
			);
			++$injected;
		}

		$verb = $dry_run ? 'would be updated' : 'updated';

		return array(
			'success'  => true,
			'category' => $term->name,
			'total'    => count( $posts ),
			'injected' => $injected,
			'skipped'  => $skipped,
			'dry_run'  => $dry_run,
			'results'  => $results,
			'message'  => sprintf(
				'%s: %d/%d posts %s, %d skipped.',
				$term->name,
				$injected,
				count( $posts ),
				$verb,
				$skipped
			),
		);
	}

	/**
	 * Extract significant keywords from a post title.
	 *
	 * Strips common stop words, question patterns, and configurable
	 * prefixes to isolate the meaningful topic words.
	 *
	 * Use the `datamachine_title_strip_patterns` filter to add
	 * site-specific patterns (e.g. "Why Am I Craving", "Spiritual
	 * Meaning Of") without modifying this plugin.
	 *
	 * @since 0.34.0
	 *
	 * @param string $title Post title.
	 * @return array Array of lowercase keyword strings.
	 */
	private static function extractTitleKeywords( string $title ): array {
		$title = strtolower( $title );

		// Generic title patterns common across WordPress sites.
		// Order matters — longer/more specific patterns first.
		$patterns = array(
			// Listicle number prefixes.
			'/^\d+\s+(most\s+common|ways\s+to|things\s+that|reasons?\s+why)\s*/i',
			'/^\d+\s+things\s+\w+\s+know\s+about\s*/i',
			'/^\d+\s+\w+\s+facts?\s+about\s*/i',
			'/^\d+\s+(unique\s+)?uses?\s+for\s*/i',
			// Common question patterns.
			'/^what does it mean (when|to|if)\s*/i',
			'/^what (does|do|is|are|happens?\s+if|happens?\s+when)\s*/i',
			'/^how (do|does|to|can|are)\s*/i',
			'/^why (do|does|don\'t|are|is|can\'t)\s*/i',
			'/^can\s+\w+\s*/i',
			'/^do\s+\w+\s*/i',
			'/^is\s+\w+\s*/i',
			'/^are\s+/i',
			// Cleanup.
			'/\s*\([^)]*\)\s*/i', // Remove parenthetical text.
			'/\?$/',               // Remove trailing question mark.
		);

		/**
		 * Filter the regex patterns used to strip non-keyword prefixes
		 * from post titles during keyword extraction.
		 *
		 * Add site-specific patterns here. Patterns are applied in order
		 * against the lowercased title, so place longer/more specific
		 * patterns before shorter/generic ones.
		 *
		 * @since 0.34.0
		 *
		 * @param array $patterns Array of regex pattern strings.
		 */
		$patterns = apply_filters( 'datamachine_title_strip_patterns', $patterns );

		foreach ( $patterns as $pattern ) {
			$title = preg_replace( $pattern, '', $title );
		}

		// Split into words.
		$words = preg_split( '/[\s\-–—:,;!?.]+/', $title, -1, PREG_SPLIT_NO_EMPTY );

		// Remove stop words.
		$stop_words = array(
			'a',
			'an',
			'the',
			'and',
			'or',
			'but',
			'in',
			'on',
			'at',
			'to',
			'for',
			'of',
			'with',
			'by',
			'from',
			'up',
			'out',
			'if',
			'about',
			'into',
			'through',
			'during',
			'before',
			'after',
			'above',
			'below',
			'between',
			'same',
			'all',
			'each',
			'every',
			'both',
			'few',
			'more',
			'most',
			'other',
			'some',
			'such',
			'no',
			'not',
			'only',
			'own',
			'so',
			'than',
			'too',
			'very',
			'just',
			'because',
			'as',
			'until',
			'while',
			'when',
			'where',
			'how',
			'what',
			'which',
			'who',
			'whom',
			'this',
			'that',
			'these',
			'those',
			'am',
			'is',
			'are',
			'was',
			'were',
			'be',
			'been',
			'being',
			'have',
			'has',
			'had',
			'having',
			'do',
			'does',
			'did',
			'doing',
			'would',
			'should',
			'could',
			'may',
			'might',
			'must',
			'shall',
			'can',
			'will',
			'it',
			'its',
			'you',
			'your',
			'yours',
			'i',
			'me',
			'my',
			'we',
			'our',
			'they',
			'their',
			'he',
			'she',
			'him',
			'her',
			'his',
			'hers',
			'them',
		);

		$keywords = array_diff( $words, $stop_words );

		// Remove very short words (1-2 chars) unless they're meaningful.
		$keywords = array_filter(
			$keywords,
			fn( $w ) => strlen( $w ) > 2
		);

		return array_values( $keywords );
	}

	/**
	 * Score all other posts against a source post by keyword overlap.
	 *
	 * Returns an associative array of post_id => score, sorted descending.
	 * Uses three matching strategies:
	 * 1. Exact keyword match (strongest signal).
	 * 2. Stem match — keywords sharing a common stem of 5+ chars.
	 * 3. Substring match — one keyword contains the other (for compounds).
	 *
	 * @since 0.34.0
	 *
	 * @param int   $source_id     Source post ID.
	 * @param array $post_keywords Map of post_id => array of keywords.
	 * @return array Sorted array of post_id => score (descending).
	 */
	private static function scoreRelatedPosts( int $source_id, array $post_keywords ): array {
		$source_words = $post_keywords[ $source_id ] ?? array();
		if ( empty( $source_words ) ) {
			return array();
		}

		$scores = array();

		foreach ( $post_keywords as $candidate_id => $candidate_words ) {
			if ( $candidate_id === $source_id ) {
				continue;
			}
			if ( empty( $candidate_words ) ) {
				continue;
			}

			$score = 0;

			// 1. Exact keyword match (2 points each).
			$exact_overlap = array_intersect( $source_words, $candidate_words );
			$score        += count( $exact_overlap ) * 2;

			// 2. Stem/substring matching for non-exact matches (1 point each).
			$source_remaining    = array_diff( $source_words, $exact_overlap );
			$candidate_remaining = array_diff( $candidate_words, $exact_overlap );

			foreach ( $source_remaining as $s_word ) {
				foreach ( $candidate_remaining as $c_word ) {
					// Stem match: compare first 5+ characters.
					$min_stem = min( strlen( $s_word ), strlen( $c_word ), 5 );
					if ( $min_stem >= 4 && substr( $s_word, 0, $min_stem ) === substr( $c_word, 0, $min_stem ) ) {
						++$score;
						break; // One match per source word.
					}

					// Substring match: one contains the other (for compound words).
					if ( strlen( $s_word ) >= 4 && strlen( $c_word ) >= 4 ) {
						if ( false !== strpos( $s_word, $c_word ) || false !== strpos( $c_word, $s_word ) ) {
							++$score;
							break;
						}
					}
				}
			}

			if ( $score > 0 ) {
				$scores[ $candidate_id ] = $score;
			}
		}

		arsort( $scores );
		return $scores;
	}

	/**
	 * Build a WordPress block-format "Related Reading" section.
	 *
	 * Creates a heading + unordered list of internal links using
	 * Gutenberg block markup.
	 *
	 * @since 0.34.0
	 *
	 * @param array $matches Array of match arrays with 'title' and 'permalink'.
	 * @return string Block-formatted HTML.
	 */
	private static function buildRelatedReadingBlock( array $matches ): string {
		$lines   = array();
		$lines[] = '<!-- wp:heading -->';
		$lines[] = '<h2 class="wp-block-heading">Related Reading</h2>';
		$lines[] = '<!-- /wp:heading -->';
		$lines[] = '';
		$lines[] = '<!-- wp:list -->';
		$lines[] = '<ul>';

		foreach ( $matches as $match ) {
			$title   = esc_html( $match['title'] );
			$url     = esc_url( $match['permalink'] );
			$lines[] = '<!-- wp:list-item -->';
			$lines[] = '<li><a href="' . $url . '">' . $title . '</a></li>';
			$lines[] = '<!-- /wp:list-item -->';
		}

		$lines[] = '</ul>';
		$lines[] = '<!-- /wp:list -->';

		return implode( "\n", $lines );
	}
}
