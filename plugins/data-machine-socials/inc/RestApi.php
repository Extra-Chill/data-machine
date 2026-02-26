<?php
/**
 * REST API endpoints for social media posting
 *
 * @package DataMachineSocials
 * @since 0.2.0
 */

namespace DataMachineSocials;

use DataMachine\Abilities\AuthAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestApi {

	const NAMESPACE = 'datamachine-socials/v1';

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public static function register_routes() {
		// Get auth status for all platforms
		register_rest_route(
			self::NAMESPACE,
			'/auth/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_auth_status' ),
				'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			)
		);

		// Get platform configurations
		register_rest_route(
			self::NAMESPACE,
			'/platforms',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_platforms' ),
				'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			)
		);

		// Cross-platform post
		register_rest_route(
			self::NAMESPACE,
			'/post',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cross_post' ),
				'permission_callback' => array( __CLASS__, 'check_publish_permission' ),
				'args'                => array(
					'platforms'    => array(
						'required' => true,
						'type'     => 'array',
					),
					'images'       => array(
						'required' => true,
						'type'     => 'array',
					),
					'caption'      => array(
						'required' => true,
						'type'     => 'string',
					),
					'aspect_ratio' => array(
						'type'    => 'string',
						'default' => '4:5',
					),
				),
			)
		);

		// Schedule cross-platform post
		register_rest_route(
			self::NAMESPACE,
			'/schedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'schedule_post' ),
				'permission_callback' => array( __CLASS__, 'check_publish_permission' ),
			)
		);

		// Upload cropped image
		register_rest_route(
			self::NAMESPACE,
			'/media/crop',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_cropped_image' ),
				'permission_callback' => array( __CLASS__, 'check_upload_permission' ),
			)
		);

		// Get post status
		register_rest_route(
			self::NAMESPACE,
			'/status/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_post_status' ),
				'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			)
		);
	}

	/**
	 * Check if user can edit posts
	 */
	public static function check_edit_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check if user can publish posts
	 */
	public static function check_publish_permission() {
		return current_user_can( 'publish_posts' );
	}

	/**
	 * Check if user can upload files
	 */
	public static function check_upload_permission() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Get authentication status for all platforms
	 */
	public static function get_auth_status() {
		$auth_abilities = new AuthAbilities();
		$providers      = $auth_abilities->getAllProviders();

		$statuses = array();

		$platforms = array( 'instagram', 'twitter', 'facebook', 'bluesky', 'threads', 'pinterest', 'reddit' );

		foreach ( $platforms as $platform ) {
			$provider = $providers[ $platform ] ?? null;

			$statuses[] = array(
				'platform'      => $platform,
				'authenticated' => $provider ? $provider->is_authenticated() : false,
				'username'      => $provider ? $provider->get_username() : null,
			);
		}

		return new \WP_REST_Response( $statuses );
	}

	/**
	 * Get platform configurations
	 */
	public static function get_platforms() {
		// Return platform configurations from registry
		$platforms = array(
			'instagram' => array(
				'label'            => 'Instagram',
				'maxImages'        => 10,
				'aspectRatios'     => array( '1:1', '4:5', '3:4', '1.91:1' ),
				'defaultAspectRatio' => '4:5',
				'charLimit'        => 2200,
				'supportsCarousel' => true,
			),
			'twitter'   => array(
				'label'            => 'Twitter / X',
				'maxImages'        => 4,
				'aspectRatios'     => array( 'any' ),
				'defaultAspectRatio' => 'any',
				'charLimit'        => 280,
				'supportsCarousel' => false,
			),
			'facebook'  => array(
				'label'            => 'Facebook',
				'maxImages'        => 10,
				'aspectRatios'     => array( 'any' ),
				'defaultAspectRatio' => 'any',
				'charLimit'        => 63206,
				'supportsCarousel' => true,
			),
			'bluesky'   => array(
				'label'            => 'Bluesky',
				'maxImages'        => 4,
				'aspectRatios'     => array( 'any' ),
				'defaultAspectRatio' => 'any',
				'charLimit'        => 300,
				'supportsCarousel' => false,
			),
			'threads'   => array(
				'label'            => 'Threads',
				'maxImages'        => 10,
				'aspectRatios'     => array( 'any' ),
				'defaultAspectRatio' => 'any',
				'charLimit'        => 500,
				'supportsCarousel' => true,
			),
			'pinterest' => array(
				'label'            => 'Pinterest',
				'maxImages'        => 1,
				'aspectRatios'     => array( '2:3' ),
				'defaultAspectRatio' => '2:3',
				'charLimit'        => 500,
				'supportsCarousel' => false,
			),
			'reddit'    => array(
				'label'      => 'Reddit',
				'type'       => 'fetch',
				'charLimit'  => 40000,
				'scopes'     => 'identity read',
			),
		);

		return new \WP_REST_Response( $platforms );
	}

	/**
	 * Cross-platform post
	 */
	public static function cross_post( \WP_REST_Request $request ) {
		$params     = $request->get_json_params();
		$platforms  = $params['platforms'] ?? array();
		$images     = $params['images'] ?? array();
		$caption    = sanitize_textarea_field( $params['caption'] ?? '' );
		$post_id    = intval( $params['post_id'] ?? 0 );
		$aspect_ratio = sanitize_text_field( $params['aspect_ratio'] ?? '4:5' );

		if ( empty( $platforms ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'No platforms selected',
				),
				400
			);
		}

		if ( empty( $images ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'No images provided',
				),
				400
			);
		}

		$results = array();
		$errors  = array();

		// Get source URL
		$source_url = $post_id ? get_permalink( $post_id ) : '';

		// Post to each platform
		foreach ( $platforms as $platform ) {
			$result = self::post_to_platform( $platform, $images, $caption, $source_url );
			$results[] = $result;

			if ( ! $result['success'] ) {
				$errors[] = $platform . ': ' . $result['error'];
			}
		}

		// Track the post
		if ( $post_id ) {
			$shared = get_post_meta( $post_id, '_dms_shared_posts', true );
			if ( ! is_array( $shared ) ) {
				$shared = array();
			}

			$shared[] = array(
				'timestamp' => time(),
				'platforms' => $platforms,
				'images'    => count( $images ),
			);

			update_post_meta( $post_id, '_dms_shared_posts', $shared );
		}

		return new \WP_REST_Response(
			array(
				'success' => empty( $errors ),
				'results' => $results,
				'errors'  => $errors ?: null,
			)
		);
	}

	/**
	 * Post to individual platform
	 */
	private static function post_to_platform( string $platform, array $images, string $caption, string $source_url ): array {
		$ability_slug = "datamachine/{$platform}-publish";

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'platform' => $platform,
				'success'  => false,
				'error'    => 'Abilities API not available',
			);
		}

		$ability = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			return array(
				'platform' => $platform,
				'success'  => false,
				'error'    => "Ability {$ability_slug} not registered",
			);
		}

		$image_urls = array_map(
			function ( $img ) {
				return $img['url'] ?? '';
			},
			$images
		);

		$result = $ability->execute( array(
			'content'    => $caption,
			'image_urls' => $image_urls,
			'source_url' => $source_url,
		) );

		if ( is_wp_error( $result ) ) {
			return array(
				'platform' => $platform,
				'success'  => false,
				'error'    => $result->get_error_message(),
			);
		}

		if ( ! empty( $result['success'] ) ) {
			return array(
				'platform'  => $platform,
				'success'   => true,
				'media_id'  => $result['media_id'] ?? null,
				'permalink' => $result['permalink'] ?? null,
			);
		}

		return array(
			'platform' => $platform,
			'success'  => false,
			'error'    => $result['error'] ?? 'Unknown error',
		);
	}

	/**
	 * Schedule cross-platform post
	 */
	public static function schedule_post( \WP_REST_Request $request ) {
		// For now, just delegate to cross_post
		// Future: Use Action Scheduler for deferred execution
		return self::cross_post( $request );
	}

	/**
	 * Upload cropped image
	 */
	public static function upload_cropped_image( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'Upload functionality not available' ),
				500
			);
		}

		// Get uploaded file
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'No file uploaded' ),
				400
			);
		}

		$file = $files['file'];

		// Create temp directory if needed
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/dms-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		// Move file to temp location
		$filename = sanitize_file_name( $file['name'] );
		$temp_path = $temp_dir . '/' . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $temp_path ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'Failed to save uploaded file' ),
				500
			);
		}

		// Return public URL
		$url = $upload_dir['baseurl'] . '/dms-temp/' . $filename;

		return new \WP_REST_Response( array( 'url' => $url ) );
	}

	/**
	 * Get post status
	 */
	public static function get_post_status( \WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );

		$shared = get_post_meta( $post_id, '_dms_shared_posts', true );

		if ( ! is_array( $shared ) ) {
			$shared = array();
		}

		return new \WP_REST_Response( $shared );
	}
}
