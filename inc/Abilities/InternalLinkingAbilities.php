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
							'success'            => array( 'type' => 'boolean' ),
							'total_posts'        => array( 'type' => 'integer' ),
							'posts_with_links'   => array( 'type' => 'integer' ),
							'posts_without_links' => array( 'type' => 'integer' ),
							'avg_links_per_post' => array( 'type' => 'number' ),
							'by_category'        => array(
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
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'category'       => $term->term_id,
				'fields'         => 'ids',
				'numberposts'    => -1,
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
				'_dm_internal_links',
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
				'_dm_internal_links',
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
						'_dm_internal_links',
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
}
