<?php
/**
 * Internal Linking Abilities
 *
 * Ability endpoints for AI-powered internal link insertion and diagnostics.
 * Delegates async execution to the System Agent infrastructure.
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
		};

			wp_register_ability(
				'datamachine/audit-internal-links',
				array(
					'label'               => 'Audit Internal Links',
					'description'         => 'Scan post content for existing internal links to build a link graph, find orphaned posts, and detect broken links.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type'     => array(
								'type'        => 'string',
								'description' => 'Post type to audit. Default: post.',
								'default'     => 'post',
							),
							'category'      => array(
								'type'        => 'string',
								'description' => 'Category slug to limit audit scope.',
							),
							'post_ids'      => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Specific post IDs to audit.',
							),
							'detect_broken' => array(
								'type'        => 'boolean',
								'description' => 'Check if linked URLs return 404.',
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
							'broken_count'   => array( 'type' => 'integer' ),
							'avg_outbound'   => array( 'type' => 'number' ),
							'avg_inbound'    => array( 'type' => 'number' ),
							'orphaned_posts' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'broken_links'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'top_linked'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'execute_callback'    => array( self::class, 'auditInternalLinks' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
	}

	if ( did_action( 'wp_abilities_api_init' ) ) {
		$register_callback();
	} else {
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

		$cat_posts = get_posts( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'category'    => $term->term_id,
			'fields'      => 'ids',
			'numberposts' => -1,
		) );

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

	$systemAgent = SystemAgent::getInstance();
	$queued      = array();

	foreach ( $post_ids as $pid ) {
		$post = get_post( $pid );
		if ( ! $post || 'publish' !== $post->post_status ) {
			continue;
		}

		$jobId = $systemAgent->scheduleTask(
			'internal_linking',
			array(
				'post_id'        => $pid,
				'links_per_post' => $links_per_post,
				'force'          => $force,
				'source'         => 'ability',
			)
		);

		if ( $jobId ) {
			$queued[] = $pid;
		}
	}

	return array(
		'success'      => true,
		'queued_count' => count( $queued ),
		'post_ids'     => $queued,
		'message'      => ! empty( $queued )
			? sprintf( 'Internal linking queued for %d post(s) via System Agent.', count( $queued ) )
			: 'No posts queued (already processed or ineligible).',
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

	$total_posts = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
			'post',
			'publish'
		)
	);

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
	$categories  = get_terms( array(
		'taxonomy'   => 'category',
		'hide_empty' => true,
	) );
	$by_category = array();

	if ( is_array( $categories ) ) {
		foreach ( $categories as $cat ) {
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
	 * builds an outbound/inbound link graph, identifies orphaned posts
	 * (zero inbound internal links), and optionally detects broken links.
	 *
	 * @since 0.32.0
	 *
	 * @param array $input Ability input.
	 * @return array Ability response with link graph data.
	 */
public static function auditInternalLinks( array $input = array() ): array {
	global $wpdb;

	$post_type     = sanitize_text_field( $input['post_type'] ?? 'post' );
	$category      = sanitize_text_field( $input['category'] ?? '' );
	$specific_ids  = array_map( 'absint', $input['post_ids'] ?? array() );
	$detect_broken = ! empty( $input['detect_broken'] );

	$home_url  = home_url();
	$home_host = wp_parse_url( $home_url, PHP_URL_HOST );

	// Build the query for posts to scan.
	if ( ! empty( $specific_ids ) ) {
		$id_placeholders = implode( ',', array_fill( 0, count( $specific_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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
			'total_scanned'  => 0,
			'total_links'    => 0,
			'orphaned_count' => 0,
			'broken_count'   => 0,
			'avg_outbound'   => 0,
			'avg_inbound'    => 0,
			'orphaned_posts' => array(),
			'broken_links'   => array(),
			'top_linked'     => array(),
		);
	}

	// Build a lookup of all scanned post URLs → IDs.
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
	$outbound    = array(); // post_id => array of target post_ids
	$inbound     = array(); // post_id => count of inbound links
	$all_links   = array(); // all discovered internal link URLs
	$broken      = array(); // broken link entries
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

	// Detect broken links (optional, expensive).
	$broken_count = 0;
	if ( $detect_broken ) {
		$checked_urls = array();
		foreach ( $all_links as $link ) {
			$url = $link['target_url'];
			if ( isset( $checked_urls[ $url ] ) ) {
				if ( ! $checked_urls[ $url ] ) {
					++$broken_count;
					$broken[] = array(
						'source_id'    => $link['source_id'],
						'source_title' => $id_to_title[ $link['source_id'] ] ?? '',
						'broken_url'   => $url,
					);
				}
				continue;
			}

			$response = wp_remote_head( $url, array(
				'timeout'     => 5,
				'redirection' => 3,
			) );
			$status   = wp_remote_retrieve_response_code( $response );
			$is_ok    = $status >= 200 && $status < 400;

			$checked_urls[ $url ] = $is_ok;

			if ( ! $is_ok ) {
				++$broken_count;
				$broken[] = array(
					'source_id'    => $link['source_id'],
					'source_title' => $id_to_title[ $link['source_id'] ] ?? '',
					'broken_url'   => $url,
					'status_code'  => $status ? $status : 0,
				);
			}
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
		'total_scanned'  => $total_scanned,
		'total_links'    => $total_links,
		'orphaned_count' => count( $orphaned ),
		'broken_count'   => $broken_count,
		'avg_outbound'   => $total_scanned > 0 ? round( $outbound_total / $total_scanned, 2 ) : 0,
		'avg_inbound'    => $total_scanned > 0 ? round( $inbound_total / $total_scanned, 2 ) : 0,
		'orphaned_posts' => $orphaned,
		'broken_links'   => $broken,
		'top_linked'     => $top_linked,
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
