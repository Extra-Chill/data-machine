<?php
/**
 * Threads Publish Ability
 *
 * Primitive ability for publishing content to Meta Threads.
 *
 * @package DataMachineSocials\Abilities\Threads
 * @since 0.1.0
 */

namespace DataMachineSocials\Abilities\Threads;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Threads Publish Ability
 */
class ThreadsPublishAbility {

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
	 * Register Threads publish ability.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/threads-publish',
				array(
					'label' => __( 'Publish to Threads', 'data-machine-socials' ),
					'description' => __( 'Post content to Meta Threads with optional media', 'data-machine-socials' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'content' ),
						'properties' => array(
							'content' => array(
								'type' => 'string',
								'description' => 'Post content text (max 500 characters)',
								'maxLength' => 500,
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
						),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'post_id' => array( 'type' => 'string' ),
							'post_url' => array( 'type' => 'string', 'format' => 'uri' ),
							'error' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( self::class, 'execute_publish' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta' => array( 'show_in_rest' => true ),
				)
			);

			// Get account ability
			wp_register_ability(
				'datamachine/threads-account',
				array(
					'label' => __( 'Threads Account Info', 'data-machine-socials' ),
					'description' => __( 'Get authenticated Threads account details', 'data-machine-socials' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'user_id' => array( 'type' => 'string' ),
							'username' => array( 'type' => 'string' ),
							'error' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( self::class, 'get_account' ),
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
	 * Execute Threads publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with post details or error.
	 */
	public static function execute_publish( array $input ): array {
		$content = $input['content'] ?? '';
		$image_url = $input['image_url'] ?? '';
		$source_url = $input['source_url'] ?? '';

		if ( empty( $content ) ) {
			return array(
				'success' => false,
				'error' => 'Content is required',
			);
		}

		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'threads' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Threads not authenticated',
			);
		}

		$user_id = $provider->get_user_id();
		$access_token = $provider->get_valid_access_token();

		if ( empty( $user_id ) || empty( $access_token ) ) {
			return array(
				'success' => false,
				'error' => 'Threads credentials not available',
			);
		}

		// Format content with source URL
		$post_text = $content;
		if ( ! empty( $source_url ) ) {
			$post_text .= "\n\n" . $source_url;
		}

		// Truncate to 500 chars
		if ( mb_strlen( $post_text ) > 500 ) {
			$post_text = mb_substr( $post_text, 0, 497 ) . '...';
		}

		// Step 1: Create media container (if image provided)
		$media_id = null;
		if ( ! empty( $image_url ) && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			$media_id = self::create_media_container( $access_token, $user_id, $image_url );
			if ( is_wp_error( $media_id ) ) {
				return array(
					'success' => false,
					'error' => $media_id->get_error_message(),
				);
			}

			// Wait for media processing
			$media_ready = self::wait_for_media( $access_token, $media_id );
			if ( ! $media_ready ) {
				return array(
					'success' => false,
					'error' => 'Media processing failed',
				);
			}
		}

		// Step 2: Create post
		$post_data = array(
			'text' => $post_text,
			'access_token' => $access_token,
		);

		if ( $media_id ) {
			$post_data['media_type'] = 'IMAGE';
			$post_data['media_url'] = $image_url;
		}

		$url = "https://graph.threads.net/v1.0/{$user_id}/threads";

		$response = wp_remote_post(
			$url,
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
			$creation_id = $data['id'];

			// Step 3: Publish the thread
			$publish_result = self::publish_thread( $access_token, $creation_id );
			if ( is_wp_error( $publish_result ) ) {
				return array(
					'success' => false,
					'error' => $publish_result->get_error_message(),
				);
			}

			$post_id = $publish_result;
			$post_url = "https://www.threads.net/@{$provider->get_username()}/post/{$post_id}";

			return array(
				'success' => true,
				'post_id' => $post_id,
				'post_url' => $post_url,
			);
		}

		$error_msg = 'Threads API error';
		if ( isset( $data['error']['message'] ) ) {
			$error_msg = $data['error']['message'];
		}

		return array(
			'success' => false,
			'error' => $error_msg,
		);
	}

	/**
	 * Get Threads account details.
	 *
	 * @param array $input Ability input.
	 * @return array Account details or error.
	 */
	public static function get_account( array $input ): array {
		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'threads' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Threads not authenticated',
			);
		}

		return array(
			'success' => true,
			'user_id' => $provider->get_user_id() ?? '',
			'username' => $provider->get_username() ?? '',
		);
	}

	/**
	 * Create media container for image upload.
	 *
	 * @param string $access_token Access token.
	 * @param string $user_id User ID.
	 * @param string $image_url Image URL.
	 * @return string|\WP_Error Media ID or error.
	 */
	private static function create_media_container( string $access_token, string $user_id, string $image_url ) {
		$url = "https://graph.threads.net/v1.0/{$user_id}/media";

		$response = wp_remote_post(
			$url,
			array(
				'body' => array(
					'media_type' => 'IMAGE',
					'image_url' => $image_url,
					'access_token' => $access_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status_code >= 200 && $status_code < 300 && isset( $data['id'] ) ) {
			return $data['id'];
		}

		return new \WP_Error(
			'threads_media_failed',
			$data['error']['message'] ?? 'Failed to create media container'
		);
	}

	/**
	 * Wait for media to be processed.
	 *
	 * @param string $access_token Access token.
	 * @param string $media_id Media ID.
	 * @return bool True if ready.
	 */
	private static function wait_for_media( string $access_token, string $media_id ): bool {
		$url = "https://graph.threads.net/v1.0/{$media_id}?access_token={$access_token}";

		for ( $i = 0; $i < 10; $i++ ) {
			$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( isset( $data['status'] ) && 'FINISHED' === $data['status'] ) {
				return true;
			}

			if ( isset( $data['status'] ) && 'ERROR' === $data['status'] ) {
				return false;
			}

			sleep( 1 );
		}

		return false;
	}

	/**
	 * Publish a thread.
	 *
	 * @param string $access_token Access token.
	 * @param string $creation_id Creation ID.
	 * @return string|\WP_Error Post ID or error.
	 */
	private static function publish_thread( string $access_token, string $creation_id ) {
		$url = "https://graph.threads.net/v1.0/me/threads_publish";

		$response = wp_remote_post(
			$url,
			array(
				'body' => array(
					'creation_id' => $creation_id,
					'access_token' => $access_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status_code >= 200 && $status_code < 300 && isset( $data['id'] ) ) {
			return $data['id'];
		}

		return new \WP_Error(
			'threads_publish_failed',
			$data['error']['message'] ?? 'Failed to publish thread'
		);
	}
}
