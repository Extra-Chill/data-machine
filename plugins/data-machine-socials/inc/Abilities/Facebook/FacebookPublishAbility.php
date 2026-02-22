<?php
/**
 * Facebook Publish Ability
 *
 * Primitive ability for publishing content to Facebook Pages.
 *
 * @package DataMachineSocials\Abilities\Facebook
 * @since 0.1.0
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Facebook Publish Ability
 */
class FacebookPublishAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	/**
	 * Register Facebook publish ability.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/facebook-publish',
				array(
					'label' => __( 'Publish to Facebook', 'data-machine-socials' ),
					'description' => __( 'Post content to Facebook Pages with optional media', 'data-machine-socials' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'content' ),
						'properties' => array(
							'title' => array(
								'type' => 'string',
								'description' => 'Post title (optional)',
							),
							'content' => array(
								'type' => 'string',
								'description' => 'Post content text',
							),
							'image_url' => array(
								'type' => 'string',
								'description' => 'URL to image for the post',
								'format' => 'uri',
							),
							'source_url' => array(
								'type' => 'string',
								'description' => 'Source URL to include',
								'format' => 'uri',
							),
							'link_handling' => array(
								'type' => 'string',
								'enum' => array( 'none', 'append', 'comment' ),
								'description' => 'How to handle source URL',
								'default' => 'append',
							),
						),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'post_id' => array( 'type' => 'string' ),
							'post_url' => array( 'type' => 'string', 'format' => 'uri' ),
							'comment_id' => array( 'type' => 'string' ),
							'comment_url' => array( 'type' => 'string', 'format' => 'uri' ),
							'error' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( self::class, 'execute_publish' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta' => array( 'show_in_rest' => true ),
				)
			);

			// Get pages ability
			wp_register_ability(
				'datamachine/facebook-pages',
				array(
					'label' => __( 'Facebook Pages', 'data-machine-socials' ),
					'description' => __( 'Get connected Facebook pages', 'data-machine-socials' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'pages' => array( 'type' => 'array' ),
							'error' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( self::class, 'get_pages' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
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
	 * Execute Facebook publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with post details or error.
	 */
	public static function execute_publish( array $input ): array {
		$title = $input['title'] ?? '';
		$content = $input['content'] ?? '';
		$image_url = $input['image_url'] ?? '';
		$source_url = $input['source_url'] ?? '';
		$link_handling = $input['link_handling'] ?? 'append';

		if ( empty( $content ) ) {
			return array(
				'success' => false,
				'error' => 'Content is required',
			);
		}

		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'facebook' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Facebook not authenticated',
			);
		}

		$page_id = $provider->get_page_id();
		$page_access_token = $provider->get_page_access_token();

		if ( empty( $page_id ) ) {
			return array(
				'success' => false,
				'error' => 'Facebook page not found',
			);
		}

		if ( empty( $page_access_token ) ) {
			return array(
				'success' => false,
				'error' => 'Facebook page access token not available',
			);
		}

		// Format post content
		$post_text = $title ? $title . "\n\n" . $content : $content;

		// Handle source URL
		if ( 'append' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL ) ) {
			$post_text .= "\n\n" . $source_url;
		}

		$post_data = array(
			'message' => $post_text,
			'access_token' => $page_access_token,
		);

		// Handle image upload
		if ( ! empty( $image_url ) && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			$image_id = self::upload_photo( $page_id, $page_access_token, $image_url );
			if ( $image_id ) {
				$post_data['attached_media'] = wp_json_encode( array( array( 'media_fbid' => $image_id ) ) );
			}
		}

		// Make API request
		$api_url = self::build_graph_url( "{$page_id}/feed" );

		$response = wp_remote_post(
			$api_url,
			array(
				'body' => $post_data,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error' => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status_code >= 200 && $status_code < 300 && isset( $data['id'] ) ) {
			$post_id = $data['id'];
			$post_url = "https://www.facebook.com/{$page_id}/posts/{$post_id}";

			$result = array(
				'success' => true,
				'post_id' => $post_id,
				'post_url' => $post_url,
			);

			// Handle comment for source URL
			if ( 'comment' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL ) ) {
				$comment = self::post_comment( $post_id, $source_url, $page_access_token );
				if ( $comment['success'] ) {
					$result['comment_id'] = $comment['comment_id'];
					$result['comment_url'] = $comment['comment_url'];
				}
			}

			return $result;
		}

		$error_msg = 'Facebook API error';
		if ( isset( $data['error']['message'] ) ) {
			$error_msg = $data['error']['message'];
		}

		return array(
			'success' => false,
			'error' => $error_msg,
		);
	}

	/**
	 * Get Facebook pages.
	 *
	 * @param array $input Ability input.
	 * @return array Pages or error.
	 */
	public static function get_pages( array $input ): array {
		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'facebook' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Facebook not authenticated',
			);
		}

		// Get pages from provider or make API call
		$pages = $provider->get_pages() ?? array();

		return array(
			'success' => true,
			'pages' => $pages,
		);
	}

	/**
	 * Upload photo to Facebook.
	 *
	 * @param string $page_id Page ID.
	 * @param string $access_token Access token.
	 * @param string $image_url Image URL.
	 * @return string|null Photo ID or null.
	 */
	private static function upload_photo( string $page_id, string $access_token, string $image_url ): ?string {
		$url = self::build_graph_url( "{$page_id}/photos" );

		$response = wp_remote_post(
			$url,
			array(
				'body' => array(
					'url' => $image_url,
					'published' => 'false',
					'access_token' => $access_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status_code >= 200 && $status_code < 300 && isset( $data['id'] ) ) {
			return $data['id'];
		}

		return null;
	}

	/**
	 * Post comment on Facebook post.
	 *
	 * @param string $post_id Post ID.
	 * @param string $message Comment message.
	 * @param string $access_token Access token.
	 * @return array Comment details.
	 */
	private static function post_comment( string $post_id, string $message, string $access_token ): array {
		$url = self::build_graph_url( "{$post_id}/comments" );

		$response = wp_remote_post(
			$url,
			array(
				'body' => array(
					'message' => $message,
					'access_token' => $access_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error' => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status_code >= 200 && $status_code < 300 && isset( $data['id'] ) ) {
			$comment_id = $data['id'];
			$comment_url = "https://www.facebook.com/{$post_id}/?comment_id={$comment_id}";

			return array(
				'success' => true,
				'comment_id' => $comment_id,
				'comment_url' => $comment_url,
			);
		}

		return array(
			'success' => false,
			'error' => $data['error']['message'] ?? 'Failed to post comment',
		);
	}

	/**
	 * Build Graph API URL.
	 *
	 * @param string $path API path.
	 * @return string Full URL.
	 */
	private static function build_graph_url( string $path ): string {
		return "https://graph.facebook.com/v23.0/" . ltrim( $path, '/' );
	}
}
