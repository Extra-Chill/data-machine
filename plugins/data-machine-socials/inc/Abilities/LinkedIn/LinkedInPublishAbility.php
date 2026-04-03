<?php
/**
 * LinkedIn Publish Ability
 *
 * Primitive ability for publishing content to LinkedIn.
 * Supports text posts, image posts, and article sharing.
 *
 * @package DataMachineSocials\Abilities\LinkedIn
 * @since 0.5.0
 */

namespace DataMachineSocials\Abilities\LinkedIn;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\LinkedIn\LinkedInAuth;

defined( 'ABSPATH' ) || exit;

/**
 * LinkedIn Publish Ability
 *
 * Self-contained ability class for LinkedIn publishing.
 */
class LinkedInPublishAbility {

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
	 * Register LinkedIn publish ability.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/linkedin-publish',
				array(
					'label'               => __( 'Publish to LinkedIn', 'data-machine-socials' ),
					'description'         => __( 'Post content to LinkedIn with optional media and article sharing', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'content' ),
						'properties' => array(
							'content'       => array(
								'type'        => 'string',
								'description' => 'Post commentary text (up to 3000 characters)',
								'maxLength'   => 3000,
							),
							'image_path'    => array(
								'type'        => 'string',
								'description' => 'Path to image file for upload',
							),
							'visibility'    => array(
								'type'        => 'string',
								'enum'        => array( 'PUBLIC', 'CONNECTIONS' ),
								'description' => 'Post visibility',
								'default'     => 'PUBLIC',
							),
							'article_url'   => array(
								'type'        => 'string',
								'description' => 'Article URL for article-type posts',
								'format'      => 'uri',
							),
							'article_title' => array(
								'type'        => 'string',
								'description' => 'Article title (used with article_url)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'post_id'  => array( 'type' => 'string' ),
							'post_url' => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute_publish' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Get account details ability.
			wp_register_ability(
				'datamachine/linkedin-account',
				array(
					'label'               => __( 'LinkedIn Account Info', 'data-machine-socials' ),
					'description'         => __( 'Get authenticated LinkedIn account details', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'person_id' => array( 'type' => 'string' ),
							'name'      => array( 'type' => 'string' ),
							'email'     => array( 'type' => 'string' ),
							'error'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'get_account' ),
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
	 * Execute LinkedIn publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with post details or error.
	 */
	public static function execute_publish( array $input ): array|\WP_Error {
		$content       = $input['content'] ?? '';
		$image_path    = $input['image_path'] ?? '';
		$visibility    = $input['visibility'] ?? 'PUBLIC';
		$article_url   = $input['article_url'] ?? '';
		$article_title = $input['article_title'] ?? '';

		if ( empty( $content ) ) {
			return new \WP_Error( 'missing_param', 'Content is required', array( 'status' => 400 ) );
		}

		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'linkedin' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn not authenticated', array( 'status' => 401 ) );
		}

		$person_urn = $provider->get_person_urn();
		if ( empty( $person_urn ) ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn person URN not available. Try re-authenticating.', array( 'status' => 401 ) );
		}

		// Build post payload.
		$payload = array(
			'author'                    => $person_urn,
			'commentary'                => $content,
			'visibility'                => $visibility,
			'distribution'              => array(
				'feedDistribution'               => 'MAIN_FEED',
				'targetEntities'                 => array(),
				'thirdPartyDistributionChannels' => array(),
			),
			'lifecycleState'            => 'PUBLISHED',
			'isReshareDisabledByAuthor' => false,
		);

		try {
			// Handle image upload if provided.
			if ( ! empty( $image_path ) && file_exists( $image_path ) ) {
				$image_urn = self::upload_image( $provider, $person_urn, $image_path );
				if ( ! $image_urn ) {
					return new \WP_Error( 'api_error', 'Failed to upload image to LinkedIn', array( 'status' => 500 ) );
				}
				$payload['content'] = array(
					'media' => array(
						'id' => $image_urn,
					),
				);
			} elseif ( ! empty( $article_url ) ) {
				// Article post.
				$article = array( 'source' => $article_url );
				if ( ! empty( $article_title ) ) {
					$article['title'] = $article_title;
				}
				$payload['content'] = array( 'article' => $article );
			}

			// Create the post.
			$result = $provider->api_request(
				'POST',
				LinkedInAuth::API_BASE . '/rest/posts',
				array(
					'body'    => wp_json_encode( $payload ),
					'context' => 'LinkedIn Publish',
				)
			);

			if ( $result['success'] ) {
				// Post ID comes from the x-restli-id response header.
				$post_id  = '';
				$headers  = $result['headers'] ?? array();
				$post_id  = self::extract_header( $headers, 'x-restli-id' );
				$post_url = self::build_post_url( $post_id );

				return array(
					'success'  => true,
					'post_id'  => $post_id,
					'post_url' => $post_url,
				);
			}

			return new \WP_Error( 'api_error', $result['error'] ?? 'LinkedIn publish failed', array( 'status' => 500 ) );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'api_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get LinkedIn account details.
	 *
	 * @param array $input Ability input.
	 * @return array Account details or error.
	 */
	public static function get_account( array $input ): array|\WP_Error {
		$input;
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'linkedin' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn not authenticated', array( 'status' => 401 ) );
		}

		$account = $provider->get_account_details();

		return array(
			'success'   => true,
			'person_id' => $account['person_id'] ?? '',
			'username'  => $provider->get_username() ?? '',
			'email'     => $account['email'] ?? '',
		);
	}

	/**
	 * Upload an image to LinkedIn for use in a post.
	 *
	 * Two-step process:
	 * 1. Initialize upload to get an upload URL and image URN.
	 * 2. Upload the binary image data to the provided URL.
	 *
	 * @param LinkedInAuth $provider Auth provider with API access.
	 * @param string       $owner_urn Owner URN (person or organization).
	 * @param string       $image_path Local path to the image file.
	 * @return string|null Image URN on success, null on failure.
	 */
	private static function upload_image( LinkedInAuth $provider, string $owner_urn, string $image_path ): ?string {
		global $wp_filesystem;
		if ( ! file_exists( $image_path ) ) {
			return null;
		}

		// Step 1: Initialize the upload.
		$init_payload = array(
			'initializeUploadRequest' => array(
				'owner' => $owner_urn,
			),
		);

		$init_result = $provider->api_request(
			'POST',
			LinkedInAuth::API_BASE . '/rest/images?action=initializeUpload',
			array(
				'body'    => wp_json_encode( $init_payload ),
				'context' => 'LinkedIn Image Upload Init',
			)
		);

		if ( ! $init_result['success'] ) {
			do_action(
				'datamachine_log',
				'error',
				'LinkedIn: Image upload initialization failed',
				array( 'error' => $init_result['error'] ?? 'Unknown error' )
			);
			return null;
		}

		$init_data  = json_decode( $init_result['data'], true );
		$upload_url = $init_data['value']['uploadUrl'] ?? null;
		$image_urn  = $init_data['value']['image'] ?? null;

		if ( empty( $upload_url ) || empty( $image_urn ) ) {
			do_action(
				'datamachine_log',
				'error',
				'LinkedIn: Image upload init response missing uploadUrl or image URN',
				array( 'response' => $init_data )
			);
			return null;
		}

		// Step 2: Upload the binary image.
		$file_contents = $wp_filesystem->get_contents( $image_path );
		if ( false === $file_contents ) {
			return null;
		}

		$access_token = $provider->get_valid_access_token();
		if ( null === $access_token ) {
			return null;
		}

		$upload_result = \DataMachine\Core\HttpClient::put(
			$upload_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/octet-stream',
				),
				'body'    => $file_contents,
				'context' => 'LinkedIn Image Upload Binary',
			)
		);

		if ( ! $upload_result['success'] ) {
			do_action(
				'datamachine_log',
				'error',
				'LinkedIn: Image binary upload failed',
				array( 'error' => $upload_result['error'] ?? 'Unknown error' )
			);
			return null;
		}

		do_action(
			'datamachine_log',
			'debug',
			'LinkedIn: Image uploaded successfully',
			array( 'image_urn' => $image_urn )
		);

		return $image_urn;
	}

	/**
	 * Extract a header value from response headers.
	 *
	 * @param mixed  $headers Response headers (Requests_Utility_CaseInsensitiveDictionary or array).
	 * @param string $key     Header key.
	 * @return string Header value or empty string.
	 */
	private static function extract_header( $headers, string $key ): string {
		if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
			return (string) ( $headers[ $key ] ?? '' );
		}

		if ( is_array( $headers ) ) {
			// Case-insensitive search.
			foreach ( $headers as $header_key => $header_value ) {
				if ( strtolower( $header_key ) === strtolower( $key ) ) {
					return (string) $header_value;
				}
			}
		}

		return '';
	}

	/**
	 * Build a LinkedIn post URL from a post URN.
	 *
	 * @param string $post_id Post URN (e.g., urn:li:share:12345 or urn:li:ugcPost:12345).
	 * @return string LinkedIn post URL.
	 */
	private static function build_post_url( string $post_id ): string {
		if ( empty( $post_id ) ) {
			return '';
		}
		// LinkedIn post URLs follow: https://www.linkedin.com/feed/update/{urn}
		return 'https://www.linkedin.com/feed/update/' . $post_id;
	}

	public function execute( array $input ): array|\WP_Error {
		$post_id = $input['post_id'] ?? '';

		if ( empty( $post_id ) ) {
			return new \WP_Error( 'missing_param', 'post_id is required', array( 'status' => 400 ) );
		}

		$provider = $this->getAuthProvider();
		if ( ! $provider ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn auth provider not available', array( 'status' => 401 ) );
		}

		if ( ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn not authenticated', array( 'status' => 401 ) );
		}

		$encoded_id = rawurlencode( $post_id );
		$url        = LinkedInAuth::API_BASE . "/rest/posts/{$encoded_id}";

		$result = $provider->api_request(
			'DELETE',
			$url,
			array(
				'headers' => array( 'X-RestLi-Method' => 'DELETE' ),
				'context' => 'LinkedIn Delete Post',
			)
		);

		// LinkedIn returns 204 on successful delete (idempotent).
		if ( $result['success'] ) {
			return array(
				'success' => true,
				'post_id' => $post_id,
			);
		}

		return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to delete LinkedIn post', array( 'status' => 500 ) );
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	private function getAuthProvider(): ?LinkedInAuth {
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'linkedin' );

		if ( $provider instanceof LinkedInAuth ) {
			return $provider;
		}

		return null;
	}
}
