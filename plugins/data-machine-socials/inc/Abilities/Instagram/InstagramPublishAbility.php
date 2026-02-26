<?php
/**
 * Instagram Publish Ability
 *
 * Primitive ability for publishing content to Instagram with support for
 * single images and carousels (up to 10 images). Uses async container
 * creation pattern from Instagram Graph API.
 *
 * Ported from post-to-instagram plugin.
 *
 * @package DataMachineSocials\Abilities\Instagram
 * @since 0.2.0
 */

namespace DataMachineSocials\Abilities\Instagram;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instagram Publish Ability
 */
class InstagramPublishAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Instagram Graph API base URL
	 */
	const GRAPH_API_URL = 'https://graph.instagram.com';

	/**
	 * Maximum images per carousel
	 */
	const MAX_CAROUSEL_IMAGES = 10;

	/**
	 * Maximum characters for caption
	 */
	const MAX_CAPTION_LENGTH = 2200;

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
	 * Register Instagram publish abilities.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/instagram-publish',
				array(
					'label' => __( 'Publish to Instagram', 'data-machine-socials' ),
					'description' => __( 'Post content to Instagram with optional media (single image or carousel up to 10 images)', 'data-machine-socials' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'content' ),
						'properties' => array(
							'content' => array(
								'type' => 'string',
								'description' => 'Post caption text (max 2200 characters)',
								'maxLength' => 2200,
							),
							'image_urls' => array(
								'type' => 'array',
								'description' => 'Array of image URLs to post (1-10 for carousel)',
								'items' => array(
									'type' => 'string',
									'format' => 'uri',
								),
								'maxItems' => 10,
							),
							'aspect_ratio' => array(
								'type' => 'string',
								'description' => 'Aspect ratio for images: 1:1, 4:5, 3:4, or 1.91:1',
								'enum' => array( '1:1', '4:5', '3:4', '1.91:1' ),
								'default' => '4:5',
							),
							'source_url' => array(
								'type' => 'string',
								'description' => 'Source URL to include in caption',
								'format' => 'uri',
							),
						),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'media_id' => array( 'type' => 'string' ),
							'permalink' => array( 'type' => 'string', 'format' => 'uri' ),
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
				'datamachine/instagram-account',
				array(
					'label' => __( 'Instagram Account Info', 'data-machine-socials' ),
					'description' => __( 'Get authenticated Instagram account details', 'data-machine-socials' ),
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
							'authenticated' => array( 'type' => 'boolean' ),
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
	 * Execute Instagram publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with post details or error.
	 */
	public static function execute_publish( array $input ): array {
		$content = $input['content'] ?? '';
		$image_urls = $input['image_urls'] ?? array();
		$aspect_ratio = $input['aspect_ratio'] ?? '4:5';
		$source_url = $input['source_url'] ?? '';

		if ( empty( $content ) ) {
			return array(
				'success' => false,
				'error' => 'Content is required',
			);
		}

		// Validate image URLs
		if ( ! empty( $image_urls ) ) {
			if ( count( $image_urls ) > self::MAX_CAROUSEL_IMAGES ) {
				return array(
					'success' => false,
					'error' => sprintf( 'Maximum %d images allowed for Instagram carousel', self::MAX_CAROUSEL_IMAGES ),
				);
			}

			foreach ( $image_urls as $url ) {
				if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
					return array(
						'success' => false,
						'error' => 'Invalid image URL: ' . $url,
					);
				}
			}
		}

		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'instagram' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Instagram not authenticated',
			);
		}

		$user_id = $provider->get_user_id();
		$access_token = $provider->get_valid_access_token();

		if ( empty( $user_id ) || empty( $access_token ) ) {
			return array(
				'success' => false,
				'error' => 'Instagram credentials not available',
			);
		}

		// Build caption with source URL if provided
		$caption = $content;
		if ( ! empty( $source_url ) ) {
			$caption .= "\n\n" . $source_url;
		}

		// Truncate to max caption length
		if ( mb_strlen( $caption ) > self::MAX_CAPTION_LENGTH ) {
			$caption = mb_substr( $caption, 0, self::MAX_CAPTION_LENGTH - 3 ) . '...';
		}

		// Create media containers for each image
		$is_carousel = count( $image_urls ) > 1;
		$container_ids = array();

		foreach ( $image_urls as $image_url ) {
			$container_body = array(
				'image_url' => $image_url,
				'access_token' => $access_token,
			);

			// For single images, include caption
			// For carousel items, omit caption (goes on carousel container)
			if ( $is_carousel ) {
				$container_body['is_carousel_item'] = 'true';
			} else {
				$container_body['caption'] = $caption;
			}

			$response = wp_remote_post(
				self::GRAPH_API_URL . "/{$user_id}/media",
				array(
					'body' => $container_body,
					'timeout' => 40,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error' => 'Error creating media container: ' . $response->get_error_message(),
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$status_code = wp_remote_retrieve_response_code( $response );

			if ( empty( $body['id'] ) ) {
				$error_msg = 'No container ID returned for image';
				if ( isset( $body['error']['message'] ) ) {
					$error_msg .= ': ' . $body['error']['message'];
				}
				return array(
					'success' => false,
					'error' => $error_msg,
				);
			}

			$container_id = $body['id'];

			// Check initial status
			$status_resp = wp_remote_get(
				self::GRAPH_API_URL . "/{$container_id}?fields=status_code&access_token={$access_token}",
				array( 'timeout' => 40 )
			);

			$status = 'IN_PROGRESS';
			if ( ! is_wp_error( $status_resp ) ) {
				$status_body = json_decode( wp_remote_retrieve_body( $status_resp ), true );
				if ( ! empty( $status_body['status_code'] ) ) {
					$status = $status_body['status_code'];
				}
			}

			if ( $status === 'ERROR' || $status === 'EXPIRED' ) {
				return array(
					'success' => false,
					'error' => 'Container status error: ' . $status,
				);
			}

			$container_ids[] = $container_id;

			// If not finished, wait for processing
			if ( $status !== 'FINISHED' ) {
				$ready = self::wait_for_container( $access_token, $container_id );
				if ( ! $ready ) {
					return array(
						'success' => false,
						'error' => 'Media processing failed for container: ' . $container_id,
					);
				}
			}
		}

		// Create main container (carousel or single)
		$main_container_id = null;
		if ( $is_carousel && count( $container_ids ) > 1 ) {
			$children = implode( ',', $container_ids );
			$carousel_resp = wp_remote_post(
				self::GRAPH_API_URL . "/{$user_id}/media",
				array(
					'body' => array(
						'media_type' => 'CAROUSEL',
						'children' => $children,
						'caption' => $caption,
						'access_token' => $access_token,
					),
					'timeout' => 40,
				)
			);

			if ( is_wp_error( $carousel_resp ) ) {
				return array(
					'success' => false,
					'error' => 'Error creating carousel container: ' . $carousel_resp->get_error_message(),
				);
			}

			$carousel_body = json_decode( wp_remote_retrieve_body( $carousel_resp ), true );
			if ( empty( $carousel_body['id'] ) ) {
				$error_msg = 'No carousel container ID returned';
				if ( isset( $carousel_body['error']['message'] ) ) {
					$error_msg .= ': ' . $carousel_body['error']['message'];
				}
				return array(
					'success' => false,
					'error' => $error_msg,
				);
			}

			$main_container_id = $carousel_body['id'];
		} elseif ( count( $container_ids ) === 1 ) {
			$main_container_id = $container_ids[0];
		} else {
			// No images - Instagram requires at least one image
			return array(
				'success' => false,
				'error' => 'At least one image is required for Instagram posts',
			);
		}

		// Publish to Instagram
		$publish_resp = wp_remote_post(
			self::GRAPH_API_URL . "/{$user_id}/media_publish",
			array(
				'body' => array(
					'creation_id' => $main_container_id,
					'access_token' => $access_token,
				),
				'timeout' => 40,
			)
		);

		if ( is_wp_error( $publish_resp ) ) {
			return array(
				'success' => false,
				'error' => 'Error publishing to Instagram: ' . $publish_resp->get_error_message(),
			);
		}

		$publish_body = json_decode( wp_remote_retrieve_body( $publish_resp ), true );
		if ( empty( $publish_body['id'] ) ) {
			$error_msg = 'No media ID returned after publishing';
			if ( isset( $publish_body['error']['message'] ) ) {
				$error_msg .= ': ' . $publish_body['error']['message'];
			}
			return array(
				'success' => false,
				'error' => $error_msg,
			);
		}

		$media_id = $publish_body['id'];

		// Fetch permalink
		$permalink = null;
		$permalink_resp = wp_remote_get(
			self::GRAPH_API_URL . "/{$media_id}?fields=id,permalink&access_token={$access_token}",
			array( 'timeout' => 40 )
		);

		if ( ! is_wp_error( $permalink_resp ) ) {
			$permalink_body = json_decode( wp_remote_retrieve_body( $permalink_resp ), true );
			if ( isset( $permalink_body['permalink'] ) ) {
				$permalink = $permalink_body['permalink'];
			}
		}

		return array(
			'success' => true,
			'media_id' => $media_id,
			'permalink' => $permalink,
		);
	}

	/**
	 * Wait for media container to be processed.
	 *
	 * @param string $access_token Access token.
	 * @param string $container_id Container ID.
	 * @return bool True if container is ready.
	 */
	private static function wait_for_container( string $access_token, string $container_id ): bool {
		$url = self::GRAPH_API_URL . "/{$container_id}?fields=status_code&access_token={$access_token}";

		for ( $i = 0; $i < 10; $i++ ) {
			$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['status_code'] ) && 'FINISHED' === $body['status_code'] ) {
				return true;
			}

			if ( isset( $body['status_code'] ) && ( 'ERROR' === $body['status_code'] || 'EXPIRED' === $body['status_code'] ) ) {
				return false;
			}

			sleep( 1 );
		}

		return false;
	}

	/**
	 * Get Instagram account details.
	 *
	 * @param array $input Ability input.
	 * @return array Account details or error.
	 */
	public static function get_account( array $input ): array {
		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'instagram' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Instagram not authenticated',
				'authenticated' => false,
			);
		}

		return array(
			'success' => true,
			'authenticated' => true,
			'user_id' => $provider->get_user_id() ?? '',
			'username' => $provider->get_username() ?? '',
		);
	}
}
