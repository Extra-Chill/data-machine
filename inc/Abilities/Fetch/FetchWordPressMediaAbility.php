<?php
/**
 * Fetch WordPress Media Ability
 *
 * Abilities API primitive for fetching media from WordPress media library.
 * Used by WordPress Media Fetch handler for pipeline data fetching with deduplication.
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchWordPressMediaAbility {

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
				'datamachine/fetch-wordpress-media',
				array(
					'label'               => __( 'Fetch WordPress Media', 'data-machine' ),
					'description'         => __( 'Fetch media attachments from WordPress media library with filtering', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'file_types'             => array(
								'type'        => 'array',
								'default'     => array( 'image' ),
								'description' => __( 'File types to fetch (image, video, audio, document)', 'data-machine' ),
							),
							'timeframe_limit'        => array(
								'type'        => 'string',
								'default'     => 'all_time',
								'description' => __( 'Timeframe filter (all_time, 24_hours, 7_days, 30_days, 90_days, 6_months, 1_year)', 'data-machine' ),
							),
							'search'                 => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Search term to filter media', 'data-machine' ),
							),
							'randomize'              => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Randomize media selection order', 'data-machine' ),
							),
							'include_parent_content' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Include parent post content for attached media', 'data-machine' ),
							),
							'processed_items'        => array(
								'type'        => 'array',
								'default'     => array(),
								'description' => __( 'Array of already processed media IDs to skip', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
							'logs'    => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
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
	 * Permission callback for ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute fetch WordPress media ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with media data or error.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		$file_types             = $config['file_types'];
		$timeframe_limit        = $config['timeframe_limit'];
		$search                 = $config['search'];
		$randomize              = $config['randomize'];
		$include_parent_content = $config['include_parent_content'];
		$processed_items        = $config['processed_items'];

		$orderby = $randomize ? 'rand' : 'modified';
		$order   = $randomize ? 'ASC' : 'DESC';

		$date_query       = array();
		$cutoff_timestamp = apply_filters( 'datamachine_timeframe_limit', null, $timeframe_limit );
		if ( null !== $cutoff_timestamp ) {
			$date_query = array(
				array(
					'after'     => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp ),
					'inclusive' => true,
				),
			);
		}

		$mime_types = $this->buildMimeTypeQuery( $file_types );

		$query_args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_parent__not_in'    => array( 0 ),
			'posts_per_page'         => 10,
			'orderby'                => $orderby,
			'order'                  => $order,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( ! empty( $mime_types ) ) {
			$query_args['post_mime_type'] = $mime_types;
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
		$posts    = $wp_query->posts;

		if ( empty( $posts ) ) {
			$logs[] = array(
				'level'   => 'debug',
				'message' => 'No media found matching query criteria',
				'data'    => array( 'query_args' => $query_args ),
			);
			return array(
				'success' => true,
				'data'    => array(),
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Query returned media items',
			'data'    => array( 'media_found' => count( $posts ) ),
		);

		foreach ( $posts as $post ) {
			$post_id = (string) $post->ID;

			if ( in_array( $post_id, $processed_items, true ) ) {
				continue;
			}

			if ( $use_client_side_search && ! empty( $search ) ) {
				$search_text = $post->post_title . ' ' . wp_strip_all_tags( $post->post_content . ' ' . $post->post_excerpt );
				if ( ! $this->applyKeywordSearch( $search_text, $search ) ) {
					continue;
				}
			}

			$title       = ! empty( $post->post_title ) ? $post->post_title : 'N/A';
			$caption     = $post->post_excerpt;
			$description = $post->post_content;
			$alt_text    = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
			$alt_text    = is_string( $alt_text ) ? $alt_text : '';
			$file_type   = get_post_mime_type( $post->ID );
			$file_type   = ! empty( $file_type ) ? $file_type : 'unknown';
			$file_path   = get_attached_file( $post->ID );
			$file_size   = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;
			$site_name   = get_bloginfo( 'name' );
			$site_name   = ! empty( $site_name ) ? $site_name : 'Local WordPress';

			$content_data = array();
			$parent_post  = null;
			if ( $include_parent_content && $post->post_parent > 0 ) {
				$parent_post = get_post( $post->post_parent );
				if ( $parent_post && $parent_post->post_status === 'publish' ) {
					$parent_title = ! empty( $parent_post->post_title ) ? $parent_post->post_title : 'Untitled';
					$content_data = array(
						'title'   => $parent_title,
						'content' => $parent_post->post_content,
					);
				}
			}

			$file_info = array(
				'file_path'           => $file_path,
				'file_name'           => basename( $file_path ),
				'title'               => $title,
				'alt_text'            => $alt_text,
				'caption'             => $caption,
				'description'         => $description,
				'file_type'           => $file_type,
				'mime_type'           => $file_type,
				'file_size'           => $file_size,
				'file_size_formatted' => $file_size > 0 ? size_format( $file_size ) : null,
			);

			$metadata = array(
				'source_type'            => 'wordpress_media',
				'item_identifier_to_log' => $post->ID,
				'original_id'            => $post->ID,
				'parent_post_id'         => $post->post_parent,
				'original_title'         => $title,
				'original_date_gmt'      => $post->post_date_gmt,
				'mime_type'              => $file_type,
				'file_size'              => $file_size,
				'site_name'              => $site_name,
			);

			$data = array(
				'media_id'  => $post->ID,
				'title'     => $content_data['title'] ?? '',
				'content'   => $content_data['content'] ?? '',
				'metadata'  => $metadata,
				'file_info' => $file_info,
			);

			$source_url = '';
			if ( $include_parent_content && $post->post_parent > 0 ) {
				$source_url = get_permalink( $post->post_parent ) ?? '';
			}

			$logs[] = array(
				'level'   => 'debug',
				'message' => 'Retrieved unprocessed media item',
				'data'    => array(
					'media_id'           => $post->ID,
					'title'              => $title,
					'has_parent_content' => ! empty( $content_data ),
				),
			);

			return array(
				'success'         => true,
				'data'            => $data,
				'source_url'      => $source_url,
				'image_file_path' => $file_path,
				'logs'            => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'All media items already processed or filtered out',
		);

		return array(
			'success' => true,
			'data'    => array(),
			'logs'    => $logs,
		);
	}

	/**
	 * Normalize input configuration with defaults.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'file_types'             => array( 'image' ),
			'timeframe_limit'        => 'all_time',
			'search'                 => '',
			'randomize'              => false,
			'include_parent_content' => false,
			'processed_items'        => array(),
		);

		return array_merge( $defaults, $input );
	}

	/**
	 * Build mime type query array from file type selections.
	 *
	 * @param array $file_types Selected file types.
	 * @return array Mime type patterns.
	 */
	private function buildMimeTypeQuery( array $file_types ): array {
		$mime_patterns = array();

		foreach ( $file_types as $file_type ) {
			switch ( $file_type ) {
				case 'image':
					$mime_patterns[] = 'image/*';
					break;
				case 'video':
					$mime_patterns[] = 'video/*';
					break;
				case 'audio':
					$mime_patterns[] = 'audio/*';
					break;
				case 'document':
					$mime_patterns = array_merge(
						$mime_patterns,
						array(
							'application/pdf',
							'application/msword',
							'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
							'text/plain',
						)
					);
					break;
			}
		}

		return $mime_patterns;
	}

	/**
	 * Apply keyword search filter.
	 */
	private function applyKeywordSearch( string $text, string $search_term ): bool {
		if ( empty( $search_term ) ) {
			return true;
		}

		$terms      = array_map( 'trim', explode( ',', $search_term ) );
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
