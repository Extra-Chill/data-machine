<?php
/**
 * Publish WordPress Ability
 *
 * Abilities API primitive for publishing WordPress posts.
 * Centralizes post creation, taxonomy assignment, and featured image handling.
 *
 * @package DataMachine\Abilities\Publish
 */

namespace DataMachine\Abilities\Publish;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class PublishWordPressAbility {

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
				'datamachine/publish-wordpress',
				array(
					'label'               => __( 'Publish WordPress Post', 'data-machine' ),
					'description'         => __( 'Create WordPress posts with taxonomy assignment and featured images', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'title', 'content', 'post_type' ),
						'properties' => array(
							'title'                  => array(
								'type'        => 'string',
								'description' => __( 'Post title', 'data-machine' ),
							),
							'content'                => array(
								'type'        => 'string',
								'description' => __( 'Post content in HTML format', 'data-machine' ),
							),
							'post_type'              => array(
								'type'        => 'string',
								'description' => __( 'WordPress post type', 'data-machine' ),
							),
							'post_status'            => array(
								'type'        => 'string',
								'default'     => 'draft',
								'description' => __( 'Post status (draft, publish, pending, private)', 'data-machine' ),
							),
							'post_author'            => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Post author user ID (0 for current user)', 'data-machine' ),
							),
							'taxonomies'             => array(
								'type'        => 'object',
								'default'     => array(),
								'description' => __( 'Taxonomy terms to assign (taxonomy => array of term IDs or names)', 'data-machine' ),
							),
							'featured_image_path'    => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Path to featured image file', 'data-machine' ),
							),
							'source_url'             => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Source URL for attribution', 'data-machine' ),
							),
							'add_source_attribution' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Whether to append source attribution to content', 'data-machine' ),
							),
							'job_id'                 => array(
								'type'        => 'integer',
								'default'     => null,
								'description' => __( 'Job ID for tracking', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'               => array( 'type' => 'boolean' ),
							'post_id'               => array( 'type' => 'integer' ),
							'post_title'            => array( 'type' => 'string' ),
							'post_url'              => array( 'type' => 'string' ),
							'taxonomy_results'      => array( 'type' => 'object' ),
							'featured_image_result' => array( 'type' => 'object' ),
							'error'                 => array( 'type' => 'string' ),
							'logs'                  => array( 'type' => 'array' ),
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
	 * Execute WordPress publish ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with post data or error.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		$title                  = $config['title'];
		$content                = $config['content'];
		$post_type              = $config['post_type'];
		$post_status            = $config['post_status'];
		$post_author            = $config['post_author'];
		$taxonomies             = $config['taxonomies'];
		$featured_image_path    = $config['featured_image_path'];
		$source_url             = $config['source_url'];
		$add_source_attribution = $config['add_source_attribution'];
		$job_id                 = $config['job_id'];

		if ( empty( $title ) || empty( $content ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPress: Missing required parameters',
				'data'    => array(
					'provided_parameters' => array_keys( $input ),
					'required_parameters' => array( 'title', 'content' ),
				),
			);
			return array(
				'success' => false,
				'error'   => 'Missing required parameters: title and content',
				'logs'    => $logs,
			);
		}

		if ( empty( $post_type ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPress: Post type is required',
			);
			return array(
				'success' => false,
				'error'   => 'Post type is required',
				'logs'    => $logs,
			);
		}

		$content = wp_unslash( $content );
		$content = wp_filter_post_kses( $content );

		if ( empty( trim( wp_strip_all_tags( $content ) ) ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPress: Content was empty after sanitization',
				'data'    => array(
					'original_content_length'  => strlen( $config['content'] ),
					'sanitized_content_length' => strlen( $content ),
				),
			);
			return array(
				'success' => false,
				'error'   => 'Content was empty after sanitization',
				'logs'    => $logs,
			);
		}

		if ( $add_source_attribution && ! empty( $source_url ) ) {
			$content = $this->applySourceAttribution( $content, $source_url );
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( wp_unslash( $title ) ),
			'post_content' => $content,
			'post_status'  => $post_status,
			'post_type'    => $post_type,
			'post_author'  => $post_author > 0 ? $post_author : get_current_user_id(),
		);

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'WordPress: Creating post',
			'data'    => array(
				'post_author'    => $post_data['post_author'],
				'post_status'    => $post_data['post_status'],
				'post_type'      => $post_data['post_type'],
				'title_length'   => strlen( $post_data['post_title'] ),
				'content_length' => strlen( $post_data['post_content'] ),
			),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPress: Post creation failed',
				'data'    => array(
					'error'     => $post_id->get_error_message(),
					'post_data' => $post_data,
				),
			);
			return array(
				'success' => false,
				'error'   => 'Post creation failed: ' . $post_id->get_error_message(),
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'WordPress: Post created',
			'data'    => array( 'post_id' => $post_id ),
		);

		$taxonomy_results = array();
		if ( ! empty( $taxonomies ) && is_array( $taxonomies ) ) {
			foreach ( $taxonomies as $taxonomy => $terms ) {
				if ( ! is_array( $terms ) ) {
					$terms = array( $terms );
				}

				$term_ids = array();
				foreach ( $terms as $term ) {
					if ( is_numeric( $term ) ) {
						$term_ids[] = intval( $term );
					} else {
						$term_obj = get_term_by( 'name', $term, $taxonomy );
						if ( ! $term_obj ) {
							$term_obj = get_term_by( 'slug', $term, $taxonomy );
						}
						if ( $term_obj ) {
							$term_ids[] = $term_obj->term_id;
						} else {
							$result = wp_insert_term( $term, $taxonomy );
							if ( ! is_wp_error( $result ) ) {
								$term_ids[] = $result['term_id'];
							}
						}
					}
				}

				if ( ! empty( $term_ids ) ) {
					$set_result                    = wp_set_object_terms( $post_id, $term_ids, $taxonomy );
					$taxonomy_results[ $taxonomy ] = array(
						'success'  => ! is_wp_error( $set_result ),
						'term_ids' => $term_ids,
					);
				}
			}
		}

		if ( ! empty( $taxonomy_results ) ) {
			$logs[] = array(
				'level'   => 'debug',
				'message' => 'WordPress: Taxonomies assigned',
				'data'    => $taxonomy_results,
			);
		}

		$featured_image_result = null;
		if ( ! empty( $featured_image_path ) && file_exists( $featured_image_path ) ) {
			$attachment_id = $this->attachImageToPost( $post_id, $featured_image_path );
			if ( $attachment_id ) {
				$featured_image_result = array(
					'success'        => true,
					'attachment_id'  => $attachment_id,
					'attachment_url' => wp_get_attachment_url( $attachment_id ),
				);
				$logs[]                = array(
					'level'   => 'debug',
					'message' => 'WordPress: Featured image attached',
					'data'    => array( 'attachment_id' => $attachment_id ),
				);
			}
		}

		if ( $job_id ) {
			do_action(
				'datamachine_merge_engine_data',
				$job_id,
				array(
					'post_id'       => $post_id,
					'published_url' => get_permalink( $post_id ),
				)
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'WordPress: Post published successfully',
			'data'    => array(
				'post_id'  => $post_id,
				'post_url' => get_permalink( $post_id ),
			),
		);

		return array(
			'success'               => true,
			'post_id'               => $post_id,
			'post_title'            => $title,
			'post_url'              => get_permalink( $post_id ),
			'taxonomy_results'      => $taxonomy_results,
			'featured_image_result' => $featured_image_result,
			'logs'                  => $logs,
		);
	}

	/**
	 * Normalize input configuration with defaults.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'title'                  => '',
			'content'                => '',
			'post_type'              => '',
			'post_status'            => 'draft',
			'post_author'            => 0,
			'taxonomies'             => array(),
			'featured_image_path'    => '',
			'source_url'             => '',
			'add_source_attribution' => true,
			'job_id'                 => null,
		);

		return array_merge( $defaults, $input );
	}

	/**
	 * Apply source attribution to content.
	 */
	private function applySourceAttribution( string $content, string $source_url ): string {
		if ( empty( $source_url ) ) {
			return $content;
		}

		$attribution = sprintf(
			'<p class="datamachine-source-attribution">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			__( 'Source:', 'data-machine' ),
			esc_url( $source_url ),
			esc_html( $source_url )
		);

		return $content . "\n\n" . $attribution;
	}

	/**
	 * Attach image to post as featured image.
	 */
	private function attachImageToPost( int $post_id, string $image_path ): ?int {
		if ( ! file_exists( $image_path ) ) {
			return null;
		}

		$file_type       = wp_check_filetype( basename( $image_path ), null );
		$attachment_args = array(
			'post_mime_type' => $file_type['type'],
			'post_title'     => sanitize_file_name( basename( $image_path ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment_args, $image_path, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $image_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}
}
