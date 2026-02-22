<?php
/**
 * Query WordPress Posts Ability
 *
 * Abilities API primitive for querying WordPress posts with filters.
 * Used by WordPress Fetch handler for pipeline data fetching with deduplication.
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class QueryWordPressPostsAbility {

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
				'datamachine/query-wordpress-posts',
				array(
					'label' => __( 'Query WordPress Posts', 'data-machine' ),
					'description' => __( 'Query WordPress posts with filtering for pipeline data fetching', 'data-machine' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'post_type' => array(
								'type' => 'string',
								'default' => 'post',
								'description' => __( 'WordPress post type to query', 'data-machine' ),
							),
							'post_status' => array(
								'type' => 'string',
								'default' => 'publish',
								'description' => __( 'Post status filter', 'data-machine' ),
							),
							'timeframe_limit' => array(
								'type' => 'string',
								'default' => 'all_time',
								'description' => __( 'Timeframe filter (all_time, 24_hours, 7_days, 30_days, 90_days, 6_months, 1_year)', 'data-machine' ),
							),
							'search' => array(
								'type' => 'string',
								'default' => '',
								'description' => __( 'Search term to filter posts', 'data-machine' ),
							),
							'randomize' => array(
								'type' => 'boolean',
								'default' => false,
								'description' => __( 'Randomize post selection order', 'data-machine' ),
							),
							'posts_per_page' => array(
								'type' => 'integer',
								'default' => 10,
								'description' => __( 'Number of posts to query', 'data-machine' ),
							),
							'tax_query' => array(
								'type' => 'array',
								'default' => array(),
								'description' => __( 'Taxonomy query array', 'data-machine' ),
							),
							'processed_items' => array(
								'type' => 'array',
								'default' => array(),
								'description' => __( 'Array of already processed post IDs to skip', 'data-machine' ),
							),
							'include_file_info' => array(
								'type' => 'boolean',
								'default' => true,
								'description' => __( 'Include featured image file_info for AI processing', 'data-machine' ),
							),
						),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data' => array( 'type' => 'object' ),
							'error' => array( 'type' => 'string' ),
							'logs' => array( 'type' => 'array' ),
							'has_more' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback' => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta' => array( 'show_in_rest' => true ),
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
	 * Permission callback for ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute query WordPress posts ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with post data or error.
	 */
	public function execute( array $input ): array {
		$logs = array();
		$config = $this->normalizeConfig( $input );

		$post_type = $config['post_type'];
		$post_status = $config['post_status'];
		$timeframe_limit = $config['timeframe_limit'];
		$search = $config['search'];
		$randomize = $config['randomize'];
		$posts_per_page = $config['posts_per_page'];
		$tax_query = $config['tax_query'];
		$processed_items = $config['processed_items'];
		$include_file_info = $config['include_file_info'];

		$orderby = $randomize ? 'rand' : 'modified';
		$order = $randomize ? 'ASC' : 'DESC';

		$date_query = array();
		$cutoff_timestamp = apply_filters( 'datamachine_timeframe_limit', null, $timeframe_limit );
		if ( null !== $cutoff_timestamp ) {
			$date_query = array(
				array(
					'after' => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp ),
					'inclusive' => true,
				),
			);
		}

		$query_args = array(
			'post_type' => $post_type,
			'post_status' => $post_status,
			'posts_per_page' => $posts_per_page,
			'orderby' => $orderby,
			'order' => $order,
			'no_found_rows' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query;
		}

		$use_client_side_search = false;
		if ( ! empty( $search ) ) {
			if ( strpos( $search, ',' ) !== false ) {
				$use_client_side_search = true;
			} else {
				$query_args['s'] = $search;
			}
		}

		if ( ! empty( $date_query ) ) {
			$query_args['date_query'] = $date_query;
		}

		$wp_query = new \WP_Query( $query_args );
		$posts = $wp_query->posts;

		if ( empty( $posts ) ) {
			$logs[] = array(
				'level' => 'debug',
				'message' => 'No posts found matching query criteria',
				'data' => array( 'query_args' => $query_args ),
			);
			return array(
				'success' => true,
				'data' => array(),
				'has_more' => false,
				'logs' => $logs,
			);
		}

		$logs[] = array(
			'level' => 'debug',
			'message' => 'Query returned posts',
			'data' => array(
				'posts_found' => count( $posts ),
				'posts_per_page' => $posts_per_page,
			),
		);

		// Process posts
		$unprocessed_posts = array();
		foreach ( $posts as $post ) {
			$post_id = (string) $post->ID;

			// Skip already processed items
			if ( in_array( $post_id, $processed_items, true ) ) {
				continue;
			}

			// Client-side search if needed
			if ( $use_client_side_search && ! empty( $search ) ) {
				$search_text = $post->post_title . ' ' . wp_strip_all_tags( $post->post_content . ' ' . $post->post_excerpt );
				if ( ! $this->applyKeywordSearch( $search_text, $search ) ) {
					continue;
				}
			}

			$unprocessed_posts[] = $post;
		}

		if ( empty( $unprocessed_posts ) ) {
			$logs[] = array(
				'level' => 'debug',
				'message' => 'All posts already processed or filtered out',
			);
			return array(
				'success' => true,
				'data' => array(),
				'has_more' => false,
				'logs' => $logs,
			);
		}

		// Return first unprocessed post
		$post = $unprocessed_posts[0];
		$post_id = $post->ID;

		$title = ! empty( $post->post_title ) ? $post->post_title : 'N/A';
		$content = $post->post_content;
		$site_name = get_bloginfo( 'name' ) ?: 'Local WordPress';

		// Get featured image
		$file_info = null;
		$featured_image_id = get_post_thumbnail_id( $post_id );
		
		if ( $featured_image_id && $include_file_info ) {
			$file_path = get_attached_file( $featured_image_id );
			if ( $file_path && file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );
				$mime_type = get_post_mime_type( $featured_image_id ) ?: 'image/jpeg';

				$file_info = array(
					'file_path' => $file_path,
					'mime_type' => $mime_type,
					'file_size' => $file_size,
				);

				$logs[] = array(
					'level' => 'debug',
					'message' => 'Including featured image file_info for AI processing',
					'data' => array(
						'post_id' => $post_id,
						'featured_image_id' => $featured_image_id,
						'file_path' => $file_path,
						'file_size' => $file_size,
					),
				);
			}
		}

		// Prepare response data
		$data = array(
			'post_id' => $post_id,
			'title' => $title,
			'content' => $content,
			'excerpt' => $post->post_excerpt,
			'permalink' => get_permalink( $post_id ) ?? '',
			'post_type' => $post->post_type,
			'post_status' => $post->post_status,
			'publish_date' => $post->post_date_gmt,
			'author' => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'site_name' => $site_name,
			'featured_image_id' => $featured_image_id,
			'original_id' => $post_id,
			'original_title' => $title,
			'original_date_gmt' => $post->post_date_gmt,
		);

		if ( $file_info ) {
			$data['file_info'] = $file_info;
		}

		$has_more = count( $unprocessed_posts ) > 1;

		$logs[] = array(
			'level' => 'debug',
			'message' => 'Retrieved unprocessed post',
			'data' => array(
				'post_id' => $post_id,
				'title' => $title,
				'has_featured_image' => ! empty( $featured_image_id ),
				'has_more' => $has_more,
			),
		);

		return array(
			'success' => true,
			'data' => $data,
			'has_more' => $has_more,
			'logs' => $logs,
		);
	}

	/**
	 * Normalize input configuration with defaults.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'timeframe_limit' => 'all_time',
			'search' => '',
			'randomize' => false,
			'posts_per_page' => 10,
			'tax_query' => array(),
			'processed_items' => array(),
			'include_file_info' => true,
		);

		return array_merge( $defaults, $input );
	}

	/**
	 * Apply keyword search filter.
	 */
	private function applyKeywordSearch( string $text, string $search_term ): bool {
		if ( empty( $search_term ) ) {
			return true;
		}

		$terms = array_map( 'trim', explode( ',', $search_term ) );
		$text_lower = strtolower( $text );

		foreach ( $terms as $term ) {
			if ( empty( $term ) ) {
				continue;
			}
			if ( strpos( $text_lower, strtolower( $term ) ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
