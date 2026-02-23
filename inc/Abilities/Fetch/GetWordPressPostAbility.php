<?php
/**
 * Get WordPress Post Ability
 *
 * Abilities API primitive for retrieving a single WordPress post.
 * Used by WordPress Fetch handler (single mode) and WordPress Post Reader tool.
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class GetWordPressPostAbility {

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
				'datamachine/get-wordpress-post',
				array(
					'label'               => __( 'Get WordPress Post', 'data-machine' ),
					'description'         => __( 'Retrieve a single WordPress post by ID or URL with optional metadata', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'           => array(
								'type'        => 'integer',
								'description' => __( 'WordPress post ID', 'data-machine' ),
							),
							'source_url'        => array(
								'type'        => 'string',
								'description' => __( 'WordPress permalink URL (alternative to post_id)', 'data-machine' ),
							),
							'include_meta'      => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Include custom meta fields in response', 'data-machine' ),
							),
							'include_file_info' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Include featured image file_info for AI processing', 'data-machine' ),
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
	 * Execute get WordPress post ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with post data or error.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		$post_id           = $config['post_id'];
		$source_url        = $config['source_url'];
		$include_meta      = $config['include_meta'];
		$include_file_info = $config['include_file_info'];

		// Get post ID from URL if not provided
		if ( ! $post_id && ! empty( $source_url ) ) {
			$post_id = url_to_postid( $source_url );
			if ( ! $post_id ) {
				$logs[] = array(
					'level'   => 'error',
					'message' => 'Could not extract valid WordPress post ID from URL',
					'data'    => array( 'source_url' => $source_url ),
				);
				return array(
					'success' => false,
					'error'   => sprintf( 'Could not extract valid WordPress post ID from URL: %s', $source_url ),
					'logs'    => $logs,
				);
			}
		}

		if ( ! $post_id ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Either post_id or source_url is required',
			);
			return array(
				'success' => false,
				'error'   => 'Either post_id or source_url is required',
				'logs'    => $logs,
			);
		}

		$post = get_post( $post_id );

		if ( ! $post || $post->post_status === 'trash' ) {
			$logs[] = array(
				'level'   => 'warning',
				'message' => 'Post not found or trashed',
				'data'    => array( 'post_id' => $post_id ),
			);
			return array(
				'success' => false,
				'error'   => sprintf( 'Post (ID: %d) not found or is trashed', $post_id ),
				'logs'    => $logs,
			);
		}

		$title        = ! empty( $post->post_title ) ? $post->post_title : 'N/A';
		$content      = $post->post_content;
		$permalink    = get_permalink( $post_id ) ?? '';
		$post_type    = get_post_type( $post_id );
		$post_status  = $post->post_status;
		$publish_date = get_the_date( 'Y-m-d H:i:s', $post_id );
		$author_name  = get_the_author_meta( 'display_name', (int) $post->post_author );
		$site_name    = get_bloginfo( 'name' ) ?: 'Local WordPress';

		$content_length     = strlen( $content );
		$content_word_count = str_word_count( wp_strip_all_tags( $content ) );

		// Get featured image
		$file_info          = null;
		$featured_image_url = null;
		$featured_image_id  = get_post_thumbnail_id( $post_id );

		if ( $featured_image_id ) {
			$featured_image_url = wp_get_attachment_image_url( $featured_image_id, 'full' );

			if ( $include_file_info ) {
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
						'level'   => 'debug',
						'message' => 'Including featured image file_info for AI processing',
						'data'    => array(
							'post_id'           => $post_id,
							'featured_image_id' => $featured_image_id,
							'file_path'         => $file_path,
							'file_size'         => $file_size,
						),
					);
				}
			}
		}

		// Prepare response data
		$data = array(
			'post_id'            => $post_id,
			'title'              => $title,
			'content'            => $content,
			'excerpt'            => $post->post_excerpt,
			'content_length'     => $content_length,
			'content_word_count' => $content_word_count,
			'permalink'          => $permalink,
			'post_type'          => $post_type,
			'post_status'        => $post_status,
			'publish_date'       => $publish_date,
			'author'             => $author_name,
			'site_name'          => $site_name,
			'featured_image'     => $featured_image_url,
			'featured_image_id'  => $featured_image_id,
		);

		if ( $file_info ) {
			$data['file_info'] = $file_info;
		}

		// Include meta fields if requested
		if ( $include_meta ) {
			$meta_fields = get_post_meta( $post_id );
			$clean_meta  = array();
			foreach ( $meta_fields as $key => $values ) {
				if ( strpos( $key, '_' ) === 0 ) {
					continue;
				}
				$clean_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			}
			$data['meta_fields'] = $clean_meta;
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Retrieved WordPress post successfully',
			'data'    => array(
				'post_id'            => $post_id,
				'title'              => $title,
				'has_featured_image' => ! empty( $featured_image_id ),
				'content_length'     => $content_length,
			),
		);

		return array(
			'success' => true,
			'data'    => $data,
			'logs'    => $logs,
		);
	}

	/**
	 * Normalize input configuration with defaults.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'post_id'           => 0,
			'source_url'        => '',
			'include_meta'      => false,
			'include_file_info' => true,
		);

		return array_merge( $defaults, $input );
	}
}
