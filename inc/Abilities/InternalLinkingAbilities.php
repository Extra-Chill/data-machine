<?php
/**
 * Internal Linking Abilities
 *
 * Ability endpoints for AI-powered internal link insertion and diagnostics.
 * Delegates async execution to the System Agent infrastructure.
 *
 * Seven abilities:
 * - datamachine/internal-linking        — Queue system agent link insertion.
 * - datamachine/diagnose-internal-links — Meta-based coverage report.
 * - datamachine/audit-internal-links    — Scan content, build + cache link graph.
 * - datamachine/get-orphaned-posts      — Read orphaned posts from cached graph.
 * - datamachine/get-backlinks           — Get all posts linking to a given post.
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
	 *
	 * Bumped to v2 in 0.72.0 when edge_type was added to graph storage and
	 * `datamachine_link_extractors` / `datamachine_link_resolvers` filters
	 * were introduced. Existing installs rebuild the graph on first read.
	 */
	const GRAPH_TRANSIENT_KEY = 'datamachine_link_graph_v2';

	/**
	 * Cache TTL: 24 hours.
	 */
	const GRAPH_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Built-in edge type for HTML anchor links.
	 *
	 * Consumers can register additional edge types (e.g. `wikilink`, `hashtag`,
	 * `mention`) via the `datamachine_link_extractors` and
	 * `datamachine_link_resolvers` filters.
	 */
	const EDGE_TYPE_HTML_ANCHOR = 'html_anchor';

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		$this->registerBuiltInLinkGraphParticipants();
		self::$registered = true;
	}

	/**
	 * Register DM's built-in HTML anchor extractor + resolver against the
	 * link graph filters so core behavior is a participant, not a hardcoded
	 * fallback.
	 *
	 * Intentionally uses anonymous closures so these registrations cannot
	 * be removed by consumers via `remove_filter`. The built-in edge type
	 * is a guarantee of the core primitive, not a default that third-party
	 * code should be able to silently drop.
	 *
	 * @since 0.72.0
	 */
	private function registerBuiltInLinkGraphParticipants(): void {
		add_filter(
			'datamachine_link_extractors',
			function ( $extractors ) {
				if ( ! is_array( $extractors ) ) {
					$extractors = array();
				}
				if ( ! isset( $extractors[ self::EDGE_TYPE_HTML_ANCHOR ] ) ) {
					$extractors[ self::EDGE_TYPE_HTML_ANCHOR ] = array(
						'label'       => __( 'HTML anchor', 'data-machine' ),
						'description' => __( 'Internal links via <a href> pointing to the site host.', 'data-machine' ),
						'callback'    => array( self::class, 'extractHtmlAnchorEdges' ),
					);
				}
				return $extractors;
			}
		);

		add_filter(
			'datamachine_link_resolvers',
			function ( $resolvers ) {
				if ( ! is_array( $resolvers ) ) {
					$resolvers = array();
				}
				if ( ! isset( $resolvers[ self::EDGE_TYPE_HTML_ANCHOR ] ) ) {
					$resolvers[ self::EDGE_TYPE_HTML_ANCHOR ] = array(
						'callback' => array( self::class, 'resolveHtmlAnchor' ),
					);
				}
				return $resolvers;
			}
		);
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/internal-linking',
				array(
					'label'               => 'Internal Linking',
					'description'         => 'Queue system agent insertion of semantic internal links into posts',
					'category'            => 'datamachine-seo',
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
					'category'            => 'datamachine-seo',
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
					'category'            => 'datamachine-seo',
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
							'types'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Optional edge types to include in aggregates (e.g. ["html_anchor"]). Omit for all types.',
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
					'category'            => 'datamachine-seo',
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
							'types'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Optional edge types to include (e.g. ["html_anchor"]). Omit for all types.',
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
				'datamachine/get-backlinks',
				array(
					'label'               => 'Get Backlinks',
					'description'         => 'Get all posts that link to a given post. Returns source posts with titles and permalinks from the cached link graph. Runs audit automatically if no cache exists.',
					'category'            => 'datamachine-seo',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id' ),
						'properties' => array(
							'post_id'   => array(
								'type'        => 'integer',
								'description' => 'The post ID to get backlinks for.',
							),
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type scope for the link graph. Default: post.',
								'default'     => 'post',
							),
							'types'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Optional edge types to include (e.g. ["html_anchor"]). Omit for all types.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'post_id'        => array( 'type' => 'integer' ),
							'backlink_count' => array( 'type' => 'integer' ),
							'backlinks'      => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'source_id'  => array( 'type' => 'integer' ),
										'title'      => array( 'type' => 'string' ),
										'permalink'  => array( 'type' => 'string' ),
										'link_count' => array( 'type' => 'integer' ),
									),
								),
							),
							'from_cache'     => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'getBacklinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/check-broken-links',
				array(
					'label'               => 'Check Broken Links',
					'description'         => 'HTTP HEAD check links from the cached link graph to find broken URLs. Supports internal, external, or all links via scope. External checks include per-domain rate limiting and HEAD→GET fallback.',
					'category'            => 'datamachine-seo',
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
							'types'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Optional edge types to include for internal-link checks (e.g. ["html_anchor"]). Omit for all types.',
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
				'datamachine/link-opportunities',
				array(
					'label'               => 'Link Opportunities',
					'description'         => 'Rank internal linking opportunities by combining GSC traffic data with the link graph. High-traffic pages with few inbound links score highest.',
					'category'            => 'datamachine-seo',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'limit'      => array(
								'type'        => 'integer',
								'description' => 'Number of results to return. Default: 20.',
								'default'     => 20,
							),
							'category'   => array(
								'type'        => 'string',
								'description' => 'Category slug to filter by.',
							),
							'min_clicks' => array(
								'type'        => 'integer',
								'description' => 'Minimum GSC clicks to include a page. Default: 5.',
								'default'     => 5,
							),
							'days'       => array(
								'type'        => 'integer',
								'description' => 'GSC lookback period in days. Default: 28.',
								'default'     => 28,
							),
							'types'      => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Optional edge types to include in link counts (e.g. ["html_anchor"]). Omit for all types.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'            => array( 'type' => 'boolean' ),
							'pages_with_traffic' => array( 'type' => 'integer' ),
							'opportunities'      => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'score'          => array( 'type' => 'number' ),
										'clicks'         => array( 'type' => 'number' ),
										'impressions'    => array( 'type' => 'number' ),
										'position'       => array( 'type' => 'number' ),
										'inbound_links'  => array( 'type' => 'integer' ),
										'outbound_links' => array( 'type' => 'integer' ),
										'post_id'        => array( 'type' => 'integer' ),
										'slug'           => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'getLinkOpportunities' ),
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

		$user_id         = get_current_user_id();
		$agent_id        = function_exists( 'datamachine_resolve_or_create_agent_id' ) && $user_id > 0 ? datamachine_resolve_or_create_agent_id( $user_id ) : 0;
		$system_defaults = PluginSettings::resolveModelForAgentMode( $agent_id, 'system' );
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
		$types        = self::normalizeTypesInput( $input['types'] ?? null );

		// Check cache unless forced or scoped to specific posts/category.
		$is_scoped = ! empty( $specific_ids ) || ! empty( $category );
		if ( ! $force && ! $is_scoped ) {
			$cached = get_transient( self::GRAPH_TRANSIENT_KEY );
			if ( false !== $cached && is_array( $cached ) && ( $cached['post_type'] ?? '' ) === $post_type ) {
				$graph            = self::applyTypesFilterToGraph( $cached, $types );
				$graph['cached']  = true;
				return $graph;
			}
		}

		$graph = self::buildLinkGraph( $post_type, $category, $specific_ids );

		if ( isset( $graph['error'] ) ) {
			return $graph;
		}

		// Cache the full, unfiltered graph if this was an unscoped audit.
		if ( ! $is_scoped ) {
			set_transient( self::GRAPH_TRANSIENT_KEY, $graph, self::GRAPH_CACHE_TTL );
		}

		$graph           = self::applyTypesFilterToGraph( $graph, $types );
		$graph['cached'] = false;
		return $graph;
	}

	/**
	 * Apply a `types` filter to a pre-built graph, re-computing aggregates
	 * (outbound/orphaned/top_linked) from `_all_links` scoped to the given
	 * edge types.
	 *
	 * @since 0.72.0
	 *
	 * @param array      $graph Full graph (returned by buildLinkGraph or from cache).
	 * @param array|null $types Edge types to scope to. null/empty = all types unioned.
	 * @return array Graph with aggregates recomputed and total_links updated.
	 */
	private static function applyTypesFilterToGraph( array $graph, ?array $types ): array {
		if ( empty( $types ) ) {
			return $graph;
		}

		$all_links   = $graph['_all_links'] ?? array();
		$post_ids    = $graph['_post_ids'] ?? array();
		$id_to_title = $graph['_id_to_title'] ?? array();
		$id_to_url   = $graph['_id_to_url'] ?? array();

		$types_set      = array_flip( array_map( 'strval', $types ) );
		$filtered_links = array();
		foreach ( $all_links as $edge ) {
			$edge_type = (string) ( $edge['edge_type'] ?? '' );
			// Drop edges lacking a type when a filter is active — dispatchExtractors
			// stamps edge_type from the registration key, so missing types only
			// appear for malformed data and shouldn't leak into scoped results.
			if ( '' !== $edge_type && isset( $types_set[ $edge_type ] ) ) {
				$filtered_links[] = $edge;
			}
		}

		$aggregates = self::computeGraphAggregates( $filtered_links, $post_ids, $id_to_title, $id_to_url, $types );

		$graph['orphaned_posts'] = $aggregates['orphaned_posts'];
		$graph['top_linked']     = $aggregates['top_linked'];
		$graph['outbound']       = $aggregates['outbound'];
		$graph['orphaned_count'] = $aggregates['orphaned_count'];
		$graph['avg_outbound']   = $aggregates['avg_outbound'];
		$graph['avg_inbound']    = $aggregates['avg_inbound'];
		$graph['total_links']    = count( $filtered_links );
		$graph['types']          = array_values( $types );

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
		$types      = self::normalizeTypesInput( $input['types'] ?? null );
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

		$graph = self::applyTypesFilterToGraph( $graph, $types );

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
	 * Get all posts that link to a given post (backlinks).
	 *
	 * Reads from the cached link graph. If no cache exists, runs an audit
	 * automatically. Groups multiple links from the same source into a
	 * single entry with a link_count.
	 *
	 * @since 0.68.0
	 *
	 * @param array $input Ability input with required 'post_id'.
	 * @return array Ability response.
	 */
	public static function getBacklinks( array $input = array() ): array {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$post_type  = sanitize_text_field( $input['post_type'] ?? 'post' );
		$types      = self::normalizeTypesInput( $input['types'] ?? null );
		$from_cache = true;

		if ( 0 === $post_id ) {
			return array(
				'success' => false,
				'error'   => 'post_id is required.',
			);
		}

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

		$all_links   = $graph['_all_links'] ?? array();
		$id_to_title = $graph['_id_to_title'] ?? array();
		$types_set   = null === $types ? null : array_flip( array_map( 'strval', $types ) );

		// Filter links where target_id matches, group by source_id.
		$sources = array();
		foreach ( $all_links as $link ) {
			if ( ( $link['target_id'] ?? null ) !== $post_id ) {
				continue;
			}
			if ( null !== $types_set ) {
				$edge_type = (string) ( $link['edge_type'] ?? '' );
				if ( '' === $edge_type || ! isset( $types_set[ $edge_type ] ) ) {
					continue;
				}
			}
			$source_id = $link['source_id'];
			if ( ! isset( $sources[ $source_id ] ) ) {
				$sources[ $source_id ] = 0;
			}
			++$sources[ $source_id ];
		}

		// Build the response array with titles and permalinks.
		$backlinks = array();
		foreach ( $sources as $source_id => $link_count ) {
			$backlinks[] = array(
				'source_id'  => $source_id,
				'title'      => $id_to_title[ $source_id ] ?? '',
				'permalink'  => get_permalink( $source_id ) ?: '',
				'link_count' => $link_count,
			);
		}

		// Sort by link count descending (most links first).
		usort( $backlinks, fn( $a, $b ) => $b['link_count'] <=> $a['link_count'] );

		return array(
			'success'        => true,
			'post_id'        => $post_id,
			'backlink_count' => count( $backlinks ),
			'backlinks'      => $backlinks,
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
		$types      = self::normalizeTypesInput( $input['types'] ?? null );
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
		$types_set   = null === $types ? null : array_flip( array_map( 'strval', $types ) );

		if ( 'internal' === $scope || 'all' === $scope ) {
			$all_links = $graph['_all_links'] ?? array();
			foreach ( $all_links as $link ) {
				$url = $link['target_url'] ?? '';
				if ( empty( $url ) ) {
					continue;
				}
				if ( null !== $types_set ) {
					$edge_type = (string) ( $link['edge_type'] ?? '' );
					if ( '' === $edge_type || ! isset( $types_set[ $edge_type ] ) ) {
						continue;
					}
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
		$types      = self::normalizeTypesInput( $input['types'] ?? null );

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
		$types_set       = null === $types ? null : array_flip( array_map( 'strval', $types ) );

		// Initialize from orphaned_posts (0 inbound) — only reliable for unfiltered reads.
		if ( null === $types_set ) {
			foreach ( $graph['orphaned_posts'] ?? array() as $orphan ) {
				$pid = $orphan['post_id'] ?? 0;
				if ( $pid > 0 ) {
					$inbound_counts[ $pid ] = 0;
				}
			}

			foreach ( $graph['top_linked'] ?? array() as $top ) {
				$pid = $top['post_id'] ?? 0;
				if ( $pid > 0 ) {
					$inbound_counts[ $pid ] = (int) ( $top['inbound'] ?? 0 );
				}
			}
		}

		// Rebuild full counts from _all_links for accuracy / types filtering.
		$all_links = $graph['_all_links'] ?? array();
		foreach ( $all_links as $link ) {
			if ( null !== $types_set ) {
				$edge_type = (string) ( $link['edge_type'] ?? '' );
				if ( '' === $edge_type || ! isset( $types_set[ $edge_type ] ) ) {
					continue;
				}
			}

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
		$gsc_ability = wp_get_ability( 'datamachine/google-search-console' );

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

	/**
	 * Get the registered link edge extractors.
	 *
	 * Applies the `datamachine_link_extractors` filter. Consumers register
	 * extractors as a keyed map: `[ edge_type => [ 'label', 'description',
	 * 'callback' ] ]` where callback receives `(string $content, array
	 * $context)` and returns an array of edges with `target_hint`,
	 * `edge_type`, and optional `display` / `location`.
	 *
	 * @since 0.72.0
	 *
	 * @return array<string, array{label?: string, description?: string, callback: callable}>
	 */
	public static function getExtractors(): array {
		$extractors = apply_filters( 'datamachine_link_extractors', array() );
		return is_array( $extractors ) ? $extractors : array();
	}

	/**
	 * Get the registered link target resolvers, keyed by edge_type.
	 *
	 * Applies the `datamachine_link_resolvers` filter. Consumers register
	 * resolvers as `[ edge_type => [ 'callback' ] ]` where callback
	 * receives `(string $target_hint, array $context)` and returns an
	 * `int` post ID or `null`.
	 *
	 * @since 0.72.0
	 *
	 * @return array<string, array{callback: callable}>
	 */
	public static function getResolvers(): array {
		$resolvers = apply_filters( 'datamachine_link_resolvers', array() );
		return is_array( $resolvers ) ? $resolvers : array();
	}

	/**
	 * Dispatch all registered extractors against a single post's content.
	 *
	 * @since 0.72.0
	 *
	 * @param string $content Post content.
	 * @param array  $context Context passed to each extractor (post_id, post_type, home_host, ...).
	 * @return array List of edges; each edge carries at minimum `target_hint` and `edge_type`.
	 */
	public static function dispatchExtractors( string $content, array $context ): array {
		$edges = array();
		foreach ( self::getExtractors() as $edge_type => $extractor ) {
			$callback = $extractor['callback'] ?? null;
			if ( ! is_callable( $callback ) ) {
				continue;
			}
			$result = call_user_func( $callback, $content, $context );
			if ( ! is_array( $result ) ) {
				continue;
			}
			foreach ( $result as $edge ) {
				if ( ! is_array( $edge ) || empty( $edge['target_hint'] ) ) {
					continue;
				}
				// Stamp edge_type from registration key when extractor omits or conflicts.
				$edge['edge_type'] = isset( $edge['edge_type'] ) && is_string( $edge['edge_type'] ) && '' !== $edge['edge_type']
					? $edge['edge_type']
					: (string) $edge_type;
				$edges[]           = $edge;
			}
		}
		return $edges;
	}

	/**
	 * Resolve a single edge's `target_hint` to a post ID using the registered
	 * resolver for its `edge_type`.
	 *
	 * @since 0.72.0
	 *
	 * @param string $target_hint Target hint produced by an extractor.
	 * @param string $edge_type   Edge type (resolver lookup key).
	 * @param array  $context     Context passed to the resolver.
	 * @return int|null Post ID or null if unresolvable.
	 */
	public static function dispatchResolver( string $target_hint, string $edge_type, array $context ): ?int {
		$resolvers = self::getResolvers();
		$entry     = $resolvers[ $edge_type ] ?? null;
		if ( null === $entry ) {
			return null;
		}
		$callback = $entry['callback'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return null;
		}
		$result = call_user_func( $callback, $target_hint, $context );
		if ( is_int( $result ) && $result > 0 ) {
			return $result;
		}
		return null;
	}

	/**
	 * Built-in extractor for HTML anchor edges.
	 *
	 * Parses `<a href>` tags in post content and emits edges pointing to the
	 * site's own host. Replaces the legacy `extractInternalLinks()` helper as
	 * a first-class participant in the extractor filter.
	 *
	 * @since 0.72.0
	 *
	 * @param string $content Post content.
	 * @param array  $context Context; reads `home_host` for host comparison.
	 * @return array List of edges with target_hint = normalized URL.
	 */
	public static function extractHtmlAnchorEdges( string $content, array $context ): array {
		$home_host = $context['home_host'] ?? wp_parse_url( home_url(), PHP_URL_HOST );
		$edges     = array();

		if ( empty( $content ) || ! is_string( $home_host ) || '' === $home_host ) {
			return $edges;
		}

		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>/i', $content, $matches ) ) {
			return $edges;
		}

		$seen = array();

		foreach ( $matches[1] as $url ) {
			// Skip non-http URLs.
			if ( preg_match( '/^(mailto:|tel:|javascript:|data:)/i', $url ) ) {
				continue;
			}

			// Handle relative URLs.
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				$url = home_url( $url );
			}

			$parsed = wp_parse_url( $url );
			$host   = $parsed['host'] ?? '';

			if ( empty( $host ) || strcasecmp( $host, $home_host ) !== 0 ) {
				continue;
			}

			$clean_url = ( $parsed['scheme'] ?? 'http' ) . '://' . $parsed['host'];
			if ( ! empty( $parsed['path'] ) ) {
				$clean_url .= $parsed['path'];
			}

			if ( isset( $seen[ $clean_url ] ) ) {
				continue;
			}
			$seen[ $clean_url ] = true;

			$edges[] = array(
				'target_hint' => $clean_url,
				'edge_type'   => self::EDGE_TYPE_HTML_ANCHOR,
				'location'    => 'body',
			);
		}

		return $edges;
	}

	/**
	 * Built-in resolver for `html_anchor` edges.
	 *
	 * Resolves a URL `target_hint` to a post ID by consulting a provided
	 * URL→ID lookup first, then falling back to `url_to_postid()`.
	 *
	 * @since 0.72.0
	 *
	 * @param string $target_hint URL produced by the html_anchor extractor.
	 * @param array  $context     Context; reads `url_to_id` lookup map.
	 * @return int|null Post ID or null.
	 */
	public static function resolveHtmlAnchor( string $target_hint, array $context ): ?int {
		$url       = $target_hint;
		$url_to_id = $context['url_to_id'] ?? array();

		if ( is_array( $url_to_id ) ) {
			$normalized = untrailingslashit( $url );
			if ( isset( $url_to_id[ $normalized ] ) ) {
				return (int) $url_to_id[ $normalized ];
			}
			$trailing = trailingslashit( $url );
			if ( isset( $url_to_id[ $trailing ] ) ) {
				return (int) $url_to_id[ $trailing ];
			}
		}

		$fallback = url_to_postid( $url );
		if ( $fallback > 0 ) {
			return (int) $fallback;
		}
		return null;
	}

	/**
	 * Build the internal link graph by scanning post content.
	 *
	 * Shared logic used by audit, get-orphaned-posts, and check-broken-links.
	 * Returns the full graph data structure suitable for caching.
	 *
	 * @since 0.32.0
	 * @since 0.72.0 Graph edges carry `edge_type`; extractors and resolvers
	 *               are pluggable via `datamachine_link_extractors` and
	 *               `datamachine_link_resolvers` filters.
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
				'success'             => true,
				'post_type'           => $post_type,
				'total_scanned'       => 0,
				'total_links'         => 0,
				'orphaned_count'      => 0,
				'avg_outbound'        => 0,
				'avg_inbound'         => 0,
				'orphaned_posts'      => array(),
				'top_linked'          => array(),
				'outbound'            => array(),
				'_all_links'          => array(),
				'_all_external_links' => array(),
				'_id_to_title'        => array(),
				'_id_to_url'          => array(),
				'_post_ids'           => array(),
			);
		}

		// Build a lookup of all scanned post URLs -> IDs.
		$url_to_id   = array();
		$id_to_url   = array();
		$id_to_title = array();
		$post_ids    = array();

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post->ID );
			if ( $permalink ) {
				$url_to_id[ untrailingslashit( $permalink ) ] = $post->ID;
				$url_to_id[ trailingslashit( $permalink ) ]   = $post->ID;
				$id_to_url[ $post->ID ]                       = $permalink;
			}
			$id_to_title[ $post->ID ] = $post->post_title;
			$post_ids[]               = (int) $post->ID;
		}

		// Scan each post's content using the registered extractors + resolvers.
		$all_links   = array(); // all discovered internal edge entries (with edge_type).
		$total_links = 0;

		$all_external_links = array();

		foreach ( $posts as $post ) {
			$content = $post->post_content;
			if ( empty( $content ) ) {
				continue;
			}

			$extract_context = array(
				'post_id'   => (int) $post->ID,
				'post_type' => $post_type,
				'home_host' => $home_host,
				'home_url'  => $home_url,
			);

			$edges = self::dispatchExtractors( $content, $extract_context );

			foreach ( $edges as $edge ) {
				$target_hint = $edge['target_hint'] ?? '';
				$edge_type   = $edge['edge_type'] ?? '';
				if ( '' === $target_hint || '' === $edge_type ) {
					continue;
				}

				++$total_links;

				$resolve_context = $extract_context;
				if ( self::EDGE_TYPE_HTML_ANCHOR === $edge_type ) {
					$resolve_context['url_to_id'] = $url_to_id;
				}
				$resolve_context['edge']    = $edge;
				$resolve_context['post_ids'] = $post_ids;

				$target_id = self::dispatchResolver( $target_hint, $edge_type, $resolve_context );

				// Don't self-loop.
				if ( null !== $target_id && $target_id === (int) $post->ID ) {
					$target_id = null;
				}

				$all_links[] = array(
					'source_id'  => (int) $post->ID,
					'target_url' => $target_hint,
					'target_id'  => $target_id,
					'edge_type'  => $edge_type,
					'resolved'   => null !== $target_id,
					'display'    => $edge['display'] ?? '',
					'location'   => $edge['location'] ?? '',
				);
			}

			// External links — html_anchor-specific auxiliary data for broken-link checks.
			$external = self::extractExternalLinks( $content, $home_host );
			foreach ( $external as $ext_link ) {
				$all_external_links[] = array(
					'source_id'   => (int) $post->ID,
					'target_url'  => $ext_link['url'],
					'anchor_text' => $ext_link['anchor_text'],
					'domain'      => $ext_link['domain'],
				);
			}
		}

		// Derive the default all-types-unioned aggregates.
		$aggregates = self::computeGraphAggregates( $all_links, $post_ids, $id_to_title, $id_to_url, null );

		return array(
			'success'             => true,
			'post_type'           => $post_type,
			'total_scanned'       => count( $posts ),
			'total_links'         => $total_links,
			'orphaned_count'      => $aggregates['orphaned_count'],
			'avg_outbound'        => $aggregates['avg_outbound'],
			'avg_inbound'         => $aggregates['avg_inbound'],
			'orphaned_posts'      => $aggregates['orphaned_posts'],
			'top_linked'          => $aggregates['top_linked'],
			'outbound'            => $aggregates['outbound'],
			// Internal data kept for typed queries and broken-link checker (not exposed in REST).
			'_all_links'          => $all_links,
			'_all_external_links' => $all_external_links,
			'_id_to_title'        => $id_to_title,
			'_id_to_url'          => $id_to_url,
			'_post_ids'           => $post_ids,
		);
	}

	/**
	 * Compute graph aggregates (outbound map, inbound counts, orphans, top-linked)
	 * from a raw edge list, optionally filtered by edge_type.
	 *
	 * @since 0.72.0
	 *
	 * @param array      $all_links   Raw `_all_links` entries with `edge_type`.
	 * @param array      $post_ids    Post IDs included in the scan scope.
	 * @param array      $id_to_title post_id => title lookup.
	 * @param array      $id_to_url   post_id => permalink lookup.
	 * @param array|null $types       Edge types to include. null / empty = all types.
	 * @return array{
	 *     outbound: array<int, array<int, array{count: int, types: array<string, int>}>>,
	 *     orphaned_posts: array<int, array{post_id: int, title: string, permalink: string, outbound: int}>,
	 *     top_linked: array<int, array{post_id: int, title: string, permalink: string, inbound: int, outbound: int}>,
	 *     orphaned_count: int,
	 *     avg_outbound: float,
	 *     avg_inbound: float
	 * }
	 */
	private static function computeGraphAggregates(
		array $all_links,
		array $post_ids,
		array $id_to_title,
		array $id_to_url,
		?array $types
	): array {
		$types_set = null;
		if ( is_array( $types ) && ! empty( $types ) ) {
			$types_set = array_flip( array_map( 'strval', $types ) );
		}

		$outbound        = array();
		$outbound_counts = array();
		$inbound         = array();

		foreach ( $post_ids as $pid ) {
			$pid             = (int) $pid;
			$inbound[ $pid ] = 0;
		}

		foreach ( $all_links as $edge ) {
			$source_id = (int) ( $edge['source_id'] ?? 0 );
			$target_id = $edge['target_id'] ?? null;
			$edge_type = (string) ( $edge['edge_type'] ?? '' );

			if ( $source_id <= 0 ) {
				continue;
			}
			// Drop edges lacking a type when a filter is active — same rationale
			// as applyTypesFilterToGraph(): dispatch stamps edge_type from the
			// registration key, so missing types only appear for malformed data
			// and shouldn't leak into scoped aggregates.
			if ( null !== $types_set && ( '' === $edge_type || ! isset( $types_set[ $edge_type ] ) ) ) {
				continue;
			}
			if ( null === $target_id ) {
				continue;
			}
			$target_id = (int) $target_id;
			if ( $target_id <= 0 || $target_id === $source_id ) {
				continue;
			}

			if ( ! isset( $outbound[ $source_id ] ) ) {
				$outbound[ $source_id ] = array();
			}
			if ( ! isset( $outbound[ $source_id ][ $target_id ] ) ) {
				$outbound[ $source_id ][ $target_id ] = array(
					'count' => 0,
					'types' => array(),
				);
			}
			++$outbound[ $source_id ][ $target_id ]['count'];
			if ( '' !== $edge_type ) {
				if ( ! isset( $outbound[ $source_id ][ $target_id ]['types'][ $edge_type ] ) ) {
					$outbound[ $source_id ][ $target_id ]['types'][ $edge_type ] = 0;
				}
				++$outbound[ $source_id ][ $target_id ]['types'][ $edge_type ];
			}

			if ( ! isset( $outbound_counts[ $source_id ] ) ) {
				$outbound_counts[ $source_id ] = 0;
			}
			++$outbound_counts[ $source_id ];

			if ( isset( $inbound[ $target_id ] ) ) {
				++$inbound[ $target_id ];
			}
		}

		// Orphans: posts in scope with zero inbound links.
		$orphaned = array();
		foreach ( $inbound as $post_id => $count ) {
			if ( 0 === $count ) {
				$orphaned[] = array(
					'post_id'   => (int) $post_id,
					'title'     => $id_to_title[ $post_id ] ?? '',
					'permalink' => $id_to_url[ $post_id ] ?? '',
					'outbound'  => $outbound_counts[ $post_id ] ?? 0,
				);
			}
		}

		// Top linked: descending inbound, cap 20.
		$inbound_sorted = $inbound;
		arsort( $inbound_sorted );
		$top_linked = array();
		$top_count  = 0;
		foreach ( $inbound_sorted as $post_id => $count ) {
			if ( 0 === $count || $top_count >= 20 ) {
				break;
			}
			$top_linked[] = array(
				'post_id'   => (int) $post_id,
				'title'     => $id_to_title[ $post_id ] ?? '',
				'permalink' => $id_to_url[ $post_id ] ?? '',
				'inbound'   => (int) $count,
				'outbound'  => $outbound_counts[ $post_id ] ?? 0,
			);
			++$top_count;
		}

		$total_scanned  = count( $post_ids );
		$outbound_total = array_sum( $outbound_counts );
		$inbound_total  = array_sum( $inbound );

		return array(
			'outbound'       => $outbound,
			'orphaned_posts' => $orphaned,
			'top_linked'     => $top_linked,
			'orphaned_count' => count( $orphaned ),
			'avg_outbound'   => $total_scanned > 0 ? round( $outbound_total / $total_scanned, 2 ) : 0,
			'avg_inbound'    => $total_scanned > 0 ? round( $inbound_total / $total_scanned, 2 ) : 0,
		);
	}

	/**
	 * Sanitize a `types` input parameter into a normalized array of edge_type
	 * strings. Accepts arrays, comma-separated strings, or empty/null values.
	 *
	 * @since 0.72.0
	 *
	 * @param mixed $raw Raw input.
	 * @return array|null Non-empty array of edge types, or null for "all types".
	 */
	private static function normalizeTypesInput( $raw ): ?array {
		if ( empty( $raw ) ) {
			return null;
		}
		if ( is_string( $raw ) ) {
			$raw = array_map( 'trim', explode( ',', $raw ) );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$normalized = array();
		foreach ( $raw as $type ) {
			if ( ! is_scalar( $type ) ) {
				continue;
			}
			$type = sanitize_key( (string) $type );
			if ( '' === $type ) {
				continue;
			}
			$normalized[ $type ] = true;
		}
		if ( empty( $normalized ) ) {
			return null;
		}
		return array_keys( $normalized );
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

	// Removed: injectCategoryLinks, extractTitleKeywords, scoreRelatedPosts,
	// buildRelatedReadingBlock — replaced by InternalLinkingTask (v0.42.0).
	// Use `datamachine links crosslink` for AI-powered natural link insertion.
}
