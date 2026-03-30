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
					'type' => 'array',
				),
				'caption'      => array(
					'required' => true,
					'type'     => 'string',
				),
				'aspect_ratio' => array(
					'type'    => 'string',
					'default' => '4:5',
				),
				'media_kind'   => array(
					'type'    => 'string',
					'default' => 'image',
					'enum'    => array( 'image', 'carousel', 'reel', 'story' ),
				),
				'video_url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'cover_url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'share_to_feed' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
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

		// =====================================================================
		// Generic Comments Endpoint (normalized shape across all platforms)
		// =====================================================================

		register_rest_route( self::NAMESPACE, '/comments/(?P<platform>[a-z]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_comments' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'platform' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Platform slug (instagram, facebook, etc.)',
				),
				'media_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Platform-specific post/media ID.',
				),
				'all' => array(
					'type'              => 'boolean',
					'default'           => true,
					'description'       => 'Fetch all comments (auto-paginate). Set false for single page.',
				),
				'limit' => array(
					'type'              => 'integer',
					'default'           => 50,
					'sanitize_callback' => 'absint',
					'description'       => 'Page size when all=false.',
				),
				'after' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Pagination cursor when all=false.',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/comments/(?P<platform>[a-z]+)/reply', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'post_comment_reply' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'platform'   => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'comment_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		) );

		// =====================================================================
		// Platform Read Endpoints
		// =====================================================================

		register_rest_route( self::NAMESPACE, '/instagram/media', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => self::read_endpoint_args( array( 'list', 'get', 'comments' ), 'media_id' ),
		) );

		register_rest_route( self::NAMESPACE, '/threads/posts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => self::read_endpoint_args( array( 'list', 'get', 'replies' ), 'thread_id' ),
		) );

		register_rest_route( self::NAMESPACE, '/facebook/posts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => self::read_endpoint_args( array( 'list', 'get', 'comments' ), 'post_id' ),
		) );

		register_rest_route( self::NAMESPACE, '/facebook/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'  => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'edit', 'hide', 'unhide', 'delete' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/twitter/tweets', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'           => array(
					'type'              => 'string',
					'default'           => 'list',
					'enum'              => array( 'list', 'get', 'mentions' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tweet_id'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'            => array(
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
				),
				'pagination_token' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/twitter/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'delete', 'retweet', 'unretweet', 'like', 'unlike' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tweet_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/bluesky/posts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'default'           => 'list',
					'enum'              => array( 'list', 'get', 'profile' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_uri' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'    => array(
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
				),
				'cursor'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/pinterest/read', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'default'           => 'pins',
					'enum'              => array( 'pins', 'pin', 'boards', 'board_pins' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'pin_id'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'board_id' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'    => array(
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
				),
				'bookmark' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/threads/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'    => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'delete' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'thread_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/bluesky/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'delete', 'like', 'unlike' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_uri' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/pinterest/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action' => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'delete' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'pin_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// =====================================================================
		// Platform Update Endpoints
		// =====================================================================

		register_rest_route( self::NAMESPACE, '/instagram/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'edit', 'delete', 'archive' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'media_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'caption'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/instagram/comments/reply', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'instagram_comment_reply' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'comment_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		) );

		// Instagram Reel publish.
		register_rest_route( self::NAMESPACE, '/instagram/reel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'instagram_publish_reel' ),
			'permission_callback' => array( __CLASS__, 'check_publish_permission' ),
			'args'                => array(
				'caption'       => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'video_url'     => array(
					'type'              => 'string',
					'required'          => true,
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
				'cover_url'     => array(
					'type'              => 'string',
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
				'share_to_feed' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'source_url'    => array(
					'type'              => 'string',
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
			),
		) );

		// Instagram Story publish.
		register_rest_route( self::NAMESPACE, '/instagram/story', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'instagram_publish_story' ),
			'permission_callback' => array( __CLASS__, 'check_publish_permission' ),
			'args'                => array(
				'image_url' => array(
					'type'              => 'string',
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
				'video_url' => array(
					'type'              => 'string',
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
			),
		) );
	}

	/**
	 * Standard args for read endpoints with cursor-based pagination.
	 */
	private static function read_endpoint_args( array $actions, string $id_param ): array {
		return array(
			'action'  => array(
				'type'              => 'string',
				'default'           => $actions[0],
				'enum'              => $actions,
				'sanitize_callback' => 'sanitize_text_field',
			),
			$id_param => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'   => array(
				'type'              => 'integer',
				'default'           => 25,
				'sanitize_callback' => 'absint',
			),
			'after'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Generic platform read handler — routes to the correct ability by URL.
	 */
	public static function platform_read( \WP_REST_Request $request ) {
		$route  = $request->get_route();
		$params = $request->get_params();

		$slug_map = array(
			'instagram' => 'datamachine/instagram-read',
			'threads'   => 'datamachine/threads-read',
			'facebook'  => 'datamachine/facebook-read',
			'twitter'   => 'datamachine/twitter-read',
			'bluesky'   => 'datamachine/bluesky-read',
			'pinterest' => 'datamachine/pinterest-read',
		);

		$platform = null;
		foreach ( $slug_map as $key => $slug ) {
			if ( strpos( $route, "/{$key}/" ) !== false ) {
				$platform = $key;
				break;
			}
		}

		if ( ! $platform || ! isset( $slug_map[ $platform ] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'Unknown platform',
			), 400 );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug_map[ $platform ] ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => $slug_map[ $platform ] . ' ability not registered',
			), 500 );
		}
		$input   = array_filter( $params, function ( $v ) { return '' !== $v && null !== $v;
		} );
		$result  = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Generic platform update handler — routes to the correct ability by URL.
	 */
	public static function platform_update( \WP_REST_Request $request ) {
		$route  = $request->get_route();
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_body_params();

		$slug_map = array(
			'instagram' => 'datamachine/instagram-update',
			'twitter'   => 'datamachine/twitter-update',
			'facebook'  => 'datamachine/facebook-update',
			'threads'   => 'datamachine/threads-update',
			'bluesky'   => 'datamachine/bluesky-update',
			'pinterest' => 'datamachine/pinterest-update',
		);

		$platform = null;
		foreach ( $slug_map as $key => $slug ) {
			if ( strpos( $route, "/{$key}/" ) !== false ) {
				$platform = $key;
				break;
			}
		}

		if ( ! $platform || ! isset( $slug_map[ $platform ] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'Unknown platform or update not supported',
			), 400 );
		}

		// Validate required params.
		if ( empty( $params['action'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'action is required',
			), 400 );
		}

		// Build input based on platform - different platforms use different ID fields.
		$id_field = 'media_id'; // Default for Instagram.
		if ( 'twitter' === $platform ) {
			$id_field = 'tweet_id';
		} elseif ( 'facebook' === $platform ) {
			$id_field = 'post_id';
		} elseif ( 'threads' === $platform ) {
			$id_field = 'thread_id';
		} elseif ( 'bluesky' === $platform ) {
			$id_field = 'post_uri';
		} elseif ( 'pinterest' === $platform ) {
			$id_field = 'pin_id';
		}

		if ( empty( $params[ $id_field ] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => "{$id_field} is required",
			), 400 );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug_map[ $platform ] ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => $slug_map[ $platform ] . ' ability not registered',
			), 500 );
		}
		$input = array(
			'action'  => sanitize_text_field( $params['action'] ),
			$id_field => sanitize_text_field( $params[ $id_field ] ),
		);

		if ( ! empty( $params['caption'] ) ) {
			$input['caption'] = sanitize_text_field( $params['caption'] );
		}

		// Handle message field for Facebook.
		if ( ! empty( $params['message'] ) && 'facebook' === $platform ) {
			$input['message'] = sanitize_text_field( $params['message'] );
		}

		$result = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Reply to an Instagram comment.
	 */
	public static function instagram_comment_reply( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_body_params();

		if ( empty( $params['comment_id'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'comment_id is required',
			), 400 );
		}

		if ( empty( $params['message'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'message is required',
			), 400 );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/instagram-comment-reply' ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Ability not registered' ), 500 );
		}
		$result = $ability->execute(
			array(
				'comment_id' => sanitize_text_field( $params['comment_id'] ),
				'message'    => sanitize_textarea_field( $params['message'] ),
			)
		);

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Generic comments endpoint — returns normalized SocialComment shape.
	 *
	 * Routes to the platform's read ability with comments_all or comments action.
	 * Provides a single, predictable endpoint for all platforms:
	 *   GET /datamachine-socials/v1/comments/{platform}?media_id=...
	 */
	public static function get_comments( \WP_REST_Request $request ) {
		$platform = $request->get_param( 'platform' );
		$media_id = $request->get_param( 'media_id' );
		$all      = $request->get_param( 'all' );

		$slug_map = array(
			'instagram' => 'datamachine/instagram-read',
			'facebook'  => 'datamachine/facebook-read',
			// Future: 'tiktok' => 'datamachine/tiktok-read', etc.
		);

		if ( ! isset( $slug_map[ $platform ] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => "Comments not supported for platform: {$platform}",
			), 400 );
		}

		if ( empty( $media_id ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'media_id is required',
			), 400 );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug_map[ $platform ] ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => $slug_map[ $platform ] . ' ability not registered',
			), 500 );
		}

		$input = array(
			'action'   => $all ? 'comments_all' : 'comments',
			'media_id' => $media_id,
		);

		if ( ! $all ) {
			$input['limit'] = $request->get_param( 'limit' ) ?: 50;
			$after          = $request->get_param( 'after' );
			if ( $after ) {
				$input['after'] = $after;
			}
		}

		$result = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Generic comment reply endpoint.
	 *
	 * Routes to the platform's comment reply ability:
	 *   POST /datamachine-socials/v1/comments/{platform}/reply
	 */
	public static function post_comment_reply( \WP_REST_Request $request ) {
		$platform   = $request->get_param( 'platform' );
		$params     = $request->get_json_params() ?: $request->get_body_params();
		$comment_id = $params['comment_id'] ?? '';
		$message    = $params['message'] ?? '';

		$slug_map = array(
			'instagram' => 'datamachine/instagram-comment-reply',
			// Future: 'facebook' => 'datamachine/facebook-comment-reply', etc.
		);

		if ( ! isset( $slug_map[ $platform ] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => "Comment reply not supported for platform: {$platform}",
			), 400 );
		}

		if ( empty( $comment_id ) || empty( $message ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'comment_id and message are required',
			), 400 );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug_map[ $platform ] ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => $slug_map[ $platform ] . ' ability not registered',
			), 500 );
		}

		$result = $ability->execute( array(
			'comment_id' => sanitize_text_field( $comment_id ),
			'message'    => sanitize_textarea_field( $message ),
		) );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Publish an Instagram Reel.
	 */
	public static function instagram_publish_reel( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_body_params();

		if ( empty( $params['caption'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'caption is required',
			), 400 );
		}

		if ( empty( $params['video_url'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'video_url is required',
			), 400 );
		}

		$input = array(
			'content'       => sanitize_textarea_field( $params['caption'] ),
			'media_kind'    => 'reel',
			'video_url'     => sanitize_url( $params['video_url'] ),
			'share_to_feed' => $params['share_to_feed'] ?? true,
		);

		if ( ! empty( $params['cover_url'] ) ) {
			$input['cover_url'] = sanitize_url( $params['cover_url'] );
		}

		if ( ! empty( $params['source_url'] ) ) {
			$input['source_url'] = sanitize_url( $params['source_url'] );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/instagram-publish' ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Ability not registered' ), 500 );
		}
		$result = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Publish an Instagram Story.
	 */
	public static function instagram_publish_story( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_body_params();

		$image_url = $params['image_url'] ?? '';
		$video_url = $params['video_url'] ?? '';

		if ( empty( $image_url ) && empty( $video_url ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'image_url or video_url is required',
			), 400 );
		}

		$input = array(
			'content'    => 'Story',
			'media_kind' => 'story',
		);

		if ( ! empty( $video_url ) ) {
			$input['video_url'] = sanitize_url( $video_url );
		} else {
			$input['story_image_url'] = sanitize_url( $image_url );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/instagram-publish' ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Ability not registered' ), 500 );
		}
		$result = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Get authentication status for all social platforms.
	 *
	 * Kept for backward compatibility. Prefer GET /platforms which
	 * returns auth status alongside platform config in one response.
	 */
	public static function get_auth_status() {
		$auth_abilities = new AuthAbilities();
		$providers      = $auth_abilities->getAllProviders();

		$statuses = array();

		foreach ( $providers as $key => $provider ) {
			// Only include social auth providers (from this plugin's namespace).
			if ( strpos( get_class( $provider ), 'DataMachineSocials\\' ) === false ) {
				continue;
			}

			$statuses[] = array(
				'platform'      => $key,
				'authenticated' => $provider->is_authenticated(),
				'username'      => $provider->get_username(),
			);
		}

		return new \WP_REST_Response( $statuses );
	}

	/**
	 * Get platform configurations with auth status.
	 *
	 * Assembles from DM core's handler registry — each social handler
	 * self-declares its constraints via the $meta parameter in registerHandler().
	 * Auth status is folded in from the auth providers filter.
	 */
	public static function get_platforms() {
		$handler_abilities = new \DataMachine\Abilities\HandlerAbilities();
		$auth_abilities    = new AuthAbilities();
		$providers         = $auth_abilities->getAllProviders();

		// Get all handlers that registered via HandlerRegistrationTrait.
		$all_handlers = $handler_abilities->getAllHandlers();

		$platforms = array();

		foreach ( $all_handlers as $slug => $handler ) {
			$auth_key = $handler['auth_provider_key'] ?? $slug;
			$provider = $providers[ $auth_key ] ?? null;

			// Skip handlers without an auth provider.
			if ( ! $provider ) {
				continue;
			}

			// Only include handlers whose auth provider is from this plugin.
			$provider_class = get_class( $provider );
			if ( strpos( $provider_class, 'DataMachineSocials\\' ) === false ) {
				continue;
			}

			$meta = $handler['meta'] ?? array();

			$platforms[ $auth_key ] = array_merge(
				array(
					'label' => $handler['label'] ?? $auth_key,
					'type'  => $handler['type'] ?? 'publish',
				),
				$meta,
				array(
					'authenticated' => $provider->is_authenticated(),
					'username'      => $provider->get_username(),
				)
			);
		}

		return new \WP_REST_Response( $platforms );
	}

	/**
	 * Check if user can publish posts
	 */
	public static function check_publish_permission() {
		return current_user_can( 'publish_posts' );
	}

	/**
	 * Check if user can edit posts (used for read/update endpoints)
	 */
	public static function check_edit_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check if user can upload files
	 */
	public static function check_upload_permission() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Cross-platform post
	 */
	public static function cross_post( \WP_REST_Request $request ) {
		$params        = $request->get_json_params();
		$platforms     = $params['platforms'] ?? array();
		$images        = $params['images'] ?? array();
		$caption       = sanitize_textarea_field( $params['caption'] ?? '' );
		$post_id       = intval( $params['post_id'] ?? 0 );
		$aspect_ratio  = sanitize_text_field( $params['aspect_ratio'] ?? '4:5' );
		$media_kind    = sanitize_text_field( $params['media_kind'] ?? 'image' );
		$video_url     = sanitize_url( $params['video_url'] ?? '' );
		$cover_url     = sanitize_url( $params['cover_url'] ?? '' );
		$share_to_feed = $params['share_to_feed'] ?? true;

		if ( empty( $platforms ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'No platforms selected',
				),
				400
			);
		}

		// Validate media: reels need video_url, stories need image or video, others need images.
		if ( 'reel' === $media_kind ) {
			if ( empty( $video_url ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'video_url is required for Reel publishing',
					),
					400
				);
			}
		} elseif ( 'story' === $media_kind ) {
			if ( empty( $video_url ) && empty( $images ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'image or video_url is required for Story publishing',
					),
					400
				);
			}
		} elseif ( empty( $images ) ) {
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

		// Get source URL.
		$source_url = $post_id ? get_permalink( $post_id ) : '';

		// Build extra params for reel publishing.
		$extra = array(
			'media_kind'    => $media_kind,
			'video_url'     => $video_url,
			'cover_url'     => $cover_url,
			'share_to_feed' => $share_to_feed,
		);

		// Post to each platform.
		foreach ( $platforms as $platform ) {
			$result    = self::post_to_platform( $platform, $images, $caption, $source_url, $extra );
			$results[] = $result;

			if ( ! $result['success'] ) {
				$errors[] = $platform . ': ' . $result['error'];
			}

			// Track successful shares via SocialShareTracker.
			if ( $post_id && ! empty( $result['success'] ) ) {
				\DataMachineSocials\Tracking\SocialShareTracker::record_from_result(
					$post_id,
					$platform,
					$result,
					array( 'media_kind' => $media_kind )
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'success' => empty( $errors ),
				'results' => $results,
				'errors'  => $errors ? $errors : null,
			)
		);
	}

	/**
	 * Post to individual platform.
	 *
	 * @param string $platform   Platform slug.
	 * @param array  $images     Array of image objects with 'url' key.
	 * @param string $caption    Post caption.
	 * @param string $source_url Source URL to attribute.
	 * @param array  $extra      Extra params (media_kind, video_url, cover_url, share_to_feed).
	 * @return array Result.
	 */
	private static function post_to_platform( string $platform, array $images, string $caption, string $source_url, array $extra = array() ): array {
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

		$input = array(
			'content'    => $caption,
			'image_urls' => $image_urls,
			'source_url' => $source_url,
		);

		// Pass through media-kind-specific params when applicable.
		$media_kind = $extra['media_kind'] ?? 'image';
		if ( 'reel' === $media_kind ) {
			$input['media_kind']    = 'reel';
			$input['video_url']     = $extra['video_url'] ?? '';
			$input['cover_url']     = $extra['cover_url'] ?? '';
			$input['share_to_feed'] = $extra['share_to_feed'] ?? true;
		} elseif ( 'story' === $media_kind ) {
			$input['media_kind'] = 'story';
			$input['video_url']  = $extra['video_url'] ?? '';
			// For stories, image comes from story_image_url or first image_url.
			if ( ! empty( $image_urls[0] ) && empty( $extra['video_url'] ) ) {
				$input['story_image_url'] = $image_urls[0];
			}
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return array(
				'platform' => $platform,
				'success'  => false,
				'error'    => $result->get_error_message(),
			);
		}

		if ( ! empty( $result['success'] ) ) {
			return array(
				'platform'         => $platform,
				'success'          => true,
				'platform_post_id' => \DataMachineSocials\Tracking\SocialShareTracker::extract_platform_post_id( $platform, $result ),
				'platform_url'     => \DataMachineSocials\Tracking\SocialShareTracker::extract_platform_url( $platform, $result ),
				'media_kind'       => $result['media_kind'] ?? null,
			);
		}

		return array(
			'platform' => $platform,
			'success'  => false,
			'error'    => $result['error'] ?? 'Unknown error',
		);
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
			Cleanup::secure_temp_dir();
		}

		// Move file to temp location
		$filename  = sanitize_file_name( $file['name'] );
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
	 * Get post sharing status and history.
	 */
	public static function get_post_status( \WP_REST_Request $request ) {
		$post_id  = intval( $request->get_param( 'post_id' ) );
		$platform = $request->get_param( 'platform' );

		$tracker = \DataMachineSocials\Tracking\SocialShareTracker::class;

		$shares    = $tracker::get_shares( $post_id, $platform ?: null );
		$platforms = $tracker::get_shared_platforms( $post_id );

		return new \WP_REST_Response( array(
			'post_id'   => $post_id,
			'platforms' => $platforms,
			'shares'    => $shares,
			'count'     => count( $shares ),
		) );
	}
}
