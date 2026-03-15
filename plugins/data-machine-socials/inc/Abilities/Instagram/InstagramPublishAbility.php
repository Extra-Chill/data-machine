<?php
/**
 * Instagram Publish Ability
 *
 * Primitive ability for publishing content to Instagram with support for
 * single images, carousels (up to 10 images), and Reels (video).
 * Uses async container creation pattern from Instagram Graph API.
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
	 * Maximum retries for video container processing.
	 * Videos take longer than images to process.
	 */
	const VIDEO_POLL_MAX_RETRIES = 30;

	/**
	 * Seconds to sleep between video container status polls.
	 */
	const VIDEO_POLL_INTERVAL = 2;

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
					'label'               => __( 'Publish to Instagram', 'data-machine-socials' ),
				'description'         => __( 'Post content to Instagram — supports single image, carousel (up to 10 images), Reel (video), and Story (image or video)', 'data-machine-socials' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'content' ),
					'properties' => array(
						'content'        => array(
							'type'        => 'string',
							'description' => 'Post caption text (max 2200 characters)',
							'maxLength'   => 2200,
						),
						'media_kind'     => array(
							'type'        => 'string',
							'description' => 'Type of media to publish: image (default), carousel, reel, or story',
							'enum'        => array( 'image', 'carousel', 'reel', 'story' ),
							'default'     => 'image',
						),
						'image_urls'     => array(
							'type'        => 'array',
							'description' => 'Array of image URLs to post (1-10 for carousel, 1 for single image)',
							'items'       => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'maxItems'    => 10,
						),
						'video_url'      => array(
							'type'        => 'string',
							'description' => 'Public video URL for Reel or Story publishing',
							'format'      => 'uri',
						),
						'cover_url'      => array(
							'type'        => 'string',
							'description' => 'Optional cover image URL for Reel',
							'format'      => 'uri',
						),
						'share_to_feed'  => array(
							'type'        => 'boolean',
							'description' => 'Whether to share the Reel to the main feed (default true)',
							'default'     => true,
						),
						'story_image_url' => array(
							'type'        => 'string',
							'description' => 'Image URL for Story publishing (use this or video_url for stories)',
							'format'      => 'uri',
						),
						'aspect_ratio'   => array(
							'type'        => 'string',
							'description' => 'Aspect ratio for images: 1:1, 4:5, 3:4, or 1.91:1',
							'enum'        => array( '1:1', '4:5', '3:4', '1.91:1' ),
							'default'     => '4:5',
						),
						'source_url'     => array(
							'type'        => 'string',
							'description' => 'Source URL to include in caption',
							'format'      => 'uri',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'media_id'   => array( 'type' => 'string' ),
						'media_kind' => array( 'type' => 'string' ),
						'permalink'  => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'error'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_publish' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Get account ability
			wp_register_ability(
				'datamachine/instagram-account',
				array(
					'label'               => __( 'Instagram Account Info', 'data-machine-socials' ),
					'description'         => __( 'Get authenticated Instagram account details', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'user_id'       => array( 'type' => 'string' ),
							'username'      => array( 'type' => 'string' ),
							'authenticated' => array( 'type' => 'boolean' ),
							'error'         => array( 'type' => 'string' ),
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
	 * Execute Instagram publish.
	 *
	 * Routes to the appropriate publishing flow based on media_kind:
	 * - image (default): single image post
	 * - carousel: multi-image carousel (auto-detected from image_urls count)
	 * - reel: video Reel via container flow
	 * - story: ephemeral Story (image or video, 24h lifespan)
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with post details or error.
	 */
	public static function execute_publish( array $input ): array {
		$content    = $input['content'] ?? '';
		$media_kind = $input['media_kind'] ?? 'image';
		$source_url = $input['source_url'] ?? '';

		if ( empty( $content ) ) {
			return array(
				'success' => false,
				'error'   => 'Content is required',
			);
		}

		// Auth check.
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'instagram' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error'   => 'Instagram not authenticated',
			);
		}

		$user_id      = $provider->get_user_id();
		$access_token = $provider->get_valid_access_token();

		if ( empty( $user_id ) || empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Instagram credentials not available',
			);
		}

		// Build caption with source URL if provided.
		$caption = $content;
		if ( ! empty( $source_url ) ) {
			$caption .= "\n\n" . $source_url;
		}

		// Truncate to max caption length.
		if ( mb_strlen( $caption ) > self::MAX_CAPTION_LENGTH ) {
			$caption = mb_substr( $caption, 0, self::MAX_CAPTION_LENGTH - 3 ) . '...';
		}

		// Route to appropriate publish flow.
		if ( 'reel' === $media_kind ) {
			return self::publish_reel( $input, $user_id, $access_token, $caption );
		}

		if ( 'story' === $media_kind ) {
			return self::publish_story( $input, $user_id, $access_token );
		}

		return self::publish_image( $input, $user_id, $access_token, $caption );
	}

	/**
	 * Publish an image or carousel to Instagram.
	 *
	 * @param array  $input        Ability input.
	 * @param string $user_id      Instagram user ID.
	 * @param string $access_token Valid access token.
	 * @param string $caption      Prepared caption text.
	 * @return array Result.
	 */
	private static function publish_image( array $input, string $user_id, string $access_token, string $caption ): array {
		$image_urls = $input['image_urls'] ?? array();

		// Validate image URLs.
		if ( ! empty( $image_urls ) ) {
			if ( count( $image_urls ) > self::MAX_CAROUSEL_IMAGES ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Maximum %d images allowed for Instagram carousel', self::MAX_CAROUSEL_IMAGES ),
				);
			}

			foreach ( $image_urls as $url ) {
				if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
					return array(
						'success' => false,
						'error'   => 'Invalid image URL: ' . $url,
					);
				}
			}
		}

		// Create media containers for each image.
		$is_carousel   = count( $image_urls ) > 1;
		$container_ids = array();

		foreach ( $image_urls as $image_url ) {
			$container_body = array(
				'image_url'    => $image_url,
				'access_token' => $access_token,
			);

			// For single images, include caption.
			// For carousel items, omit caption (goes on carousel container).
			if ( $is_carousel ) {
				$container_body['is_carousel_item'] = 'true';
			} else {
				$container_body['caption'] = $caption;
			}

			$response = wp_remote_post(
				self::GRAPH_API_URL . "/{$user_id}/media",
				array(
					'body'    => $container_body,
					'timeout' => 40,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error'   => 'Error creating media container: ' . $response->get_error_message(),
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $body['id'] ) ) {
				$error_msg = 'No container ID returned for image';
				if ( isset( $body['error']['message'] ) ) {
					$error_msg .= ': ' . $body['error']['message'];
				}
				return array(
					'success' => false,
					'error'   => $error_msg,
				);
			}

			$container_id = $body['id'];

			// Check initial status.
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

			if ( 'ERROR' === $status || 'EXPIRED' === $status ) {
				return array(
					'success' => false,
					'error'   => 'Container status error: ' . $status,
				);
			}

			$container_ids[] = $container_id;

			// If not finished, wait for processing.
			if ( 'FINISHED' !== $status ) {
				$ready = self::wait_for_container( $access_token, $container_id );
				if ( ! $ready ) {
					return array(
						'success' => false,
						'error'   => 'Media processing failed for container: ' . $container_id,
					);
				}
			}
		}

		// Create main container (carousel or single).
		$main_container_id = null;
		$media_kind        = 'image';
		if ( $is_carousel && count( $container_ids ) > 1 ) {
			$children      = implode( ',', $container_ids );
			$carousel_resp = wp_remote_post(
				self::GRAPH_API_URL . "/{$user_id}/media",
				array(
					'body'    => array(
						'media_type'   => 'CAROUSEL',
						'children'     => $children,
						'caption'      => $caption,
						'access_token' => $access_token,
					),
					'timeout' => 40,
				)
			);

			if ( is_wp_error( $carousel_resp ) ) {
				return array(
					'success' => false,
					'error'   => 'Error creating carousel container: ' . $carousel_resp->get_error_message(),
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
					'error'   => $error_msg,
				);
			}

			$main_container_id = $carousel_body['id'];
			$media_kind        = 'carousel';
		} elseif ( count( $container_ids ) === 1 ) {
			$main_container_id = $container_ids[0];
		} else {
			// No images - Instagram requires at least one image.
			return array(
				'success' => false,
				'error'   => 'At least one image is required for Instagram posts',
			);
		}

		return self::publish_container( $user_id, $access_token, $main_container_id, $media_kind );
	}

	/**
	 * Publish a Reel (video) to Instagram.
	 *
	 * Uses the Instagram Graph API container flow:
	 * 1. POST /{user_id}/media with media_type=REELS and video_url
	 * 2. Poll container status until FINISHED (video processing)
	 * 3. POST /{user_id}/media_publish with creation_id
	 *
	 * @param array  $input        Ability input.
	 * @param string $user_id      Instagram user ID.
	 * @param string $access_token Valid access token.
	 * @param string $caption      Prepared caption text.
	 * @return array Result.
	 */
	private static function publish_reel( array $input, string $user_id, string $access_token, string $caption ): array {
		$video_url     = $input['video_url'] ?? '';
		$cover_url     = $input['cover_url'] ?? '';
		$share_to_feed = $input['share_to_feed'] ?? true;

		if ( empty( $video_url ) ) {
			return array(
				'success' => false,
				'error'   => 'video_url is required for Reel publishing',
			);
		}

		if ( ! filter_var( $video_url, FILTER_VALIDATE_URL ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid video URL: ' . $video_url,
			);
		}

		// Pre-publish validation via core video-metadata if local path is available.
		$video_file_path = $input['video_file_path'] ?? '';
		if ( ! empty( $video_file_path ) && file_exists( $video_file_path ) ) {
			$metadata = \DataMachine\Core\FilesRepository\VideoMetadata::extract( $video_file_path );

			// Instagram Reels: max 15 min, H.264 codec recommended.
			if ( ! empty( $metadata['duration'] ) && $metadata['duration'] > 900 ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Video duration (%.0fs) exceeds Instagram Reels maximum (900s)', $metadata['duration'] ),
				);
			}
		}

		// Build the container request body.
		$container_body = array(
			'media_type'    => 'REELS',
			'video_url'     => $video_url,
			'caption'       => $caption,
			'share_to_feed' => $share_to_feed ? 'true' : 'false',
			'access_token'  => $access_token,
		);

		if ( ! empty( $cover_url ) ) {
			if ( ! filter_var( $cover_url, FILTER_VALIDATE_URL ) ) {
				return array(
					'success' => false,
					'error'   => 'Invalid cover URL: ' . $cover_url,
				);
			}
			$container_body['cover_url'] = $cover_url;
		}

		// Step 1: Create the Reel container.
		$response = wp_remote_post(
			self::GRAPH_API_URL . "/{$user_id}/media",
			array(
				'body'    => $container_body,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => 'Error creating Reel container: ' . $response->get_error_message(),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['id'] ) ) {
			$error_msg = 'No container ID returned for Reel';
			if ( isset( $body['error']['message'] ) ) {
				$error_msg .= ': ' . $body['error']['message'];
			}
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$container_id = $body['id'];

		// Step 2: Wait for video processing (longer timeout than images).
		$ready = self::wait_for_container(
			$access_token,
			$container_id,
			self::VIDEO_POLL_MAX_RETRIES,
			self::VIDEO_POLL_INTERVAL
		);

		if ( ! $ready ) {
			return array(
				'success' => false,
				'error'   => 'Reel video processing failed or timed out for container: ' . $container_id,
			);
		}

		// Step 3: Publish.
		return self::publish_container( $user_id, $access_token, $container_id, 'reel' );
	}

	/**
	 * Publish a Story to Instagram.
	 *
	 * Stories support a single image or video and are ephemeral (24h lifespan).
	 * Uses the Instagram Graph API container flow with media_type=STORIES.
	 *
	 * Note: Stories do not support captions via the API. The content param is
	 * accepted but not sent to the API (logged for tracking purposes only).
	 *
	 * @param array  $input        Ability input.
	 * @param string $user_id      Instagram user ID.
	 * @param string $access_token Valid access token.
	 * @return array Result.
	 */
	private static function publish_story( array $input, string $user_id, string $access_token ): array {
		$story_image_url = $input['story_image_url'] ?? '';
		$video_url       = $input['video_url'] ?? '';

		// Stories require exactly one media source: image or video.
		if ( empty( $story_image_url ) && empty( $video_url ) ) {
			return array(
				'success' => false,
				'error'   => 'story_image_url or video_url is required for Story publishing',
			);
		}

		// Build the container request body.
		$container_body = array(
			'media_type'   => 'STORIES',
			'access_token' => $access_token,
		);

		if ( ! empty( $video_url ) ) {
			if ( ! filter_var( $video_url, FILTER_VALIDATE_URL ) ) {
				return array(
					'success' => false,
					'error'   => 'Invalid video URL: ' . $video_url,
				);
			}
			$container_body['video_url'] = $video_url;
		} else {
			if ( ! filter_var( $story_image_url, FILTER_VALIDATE_URL ) ) {
				return array(
					'success' => false,
					'error'   => 'Invalid story image URL: ' . $story_image_url,
				);
			}
			$container_body['image_url'] = $story_image_url;
		}

		// Step 1: Create the Story container.
		$response = wp_remote_post(
			self::GRAPH_API_URL . "/{$user_id}/media",
			array(
				'body'    => $container_body,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => 'Error creating Story container: ' . $response->get_error_message(),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['id'] ) ) {
			$error_msg = 'No container ID returned for Story';
			if ( isset( $body['error']['message'] ) ) {
				$error_msg .= ': ' . $body['error']['message'];
			}
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$container_id = $body['id'];

		// Step 2: Wait for processing.
		// Video stories need longer polling; image stories are usually instant.
		$max_retries = ! empty( $video_url ) ? self::VIDEO_POLL_MAX_RETRIES : 10;
		$interval    = ! empty( $video_url ) ? self::VIDEO_POLL_INTERVAL : 1;

		$ready = self::wait_for_container( $access_token, $container_id, $max_retries, $interval );
		if ( ! $ready ) {
			return array(
				'success' => false,
				'error'   => 'Story media processing failed or timed out for container: ' . $container_id,
			);
		}

		// Step 3: Publish.
		return self::publish_container( $user_id, $access_token, $container_id, 'story' );
	}

	/**
	 * Publish a prepared container to Instagram and fetch the permalink.
	 *
	 * Shared final step for image, carousel, reel, and story flows.
	 *
	 * @param string $user_id        Instagram user ID.
	 * @param string $access_token   Valid access token.
	 * @param string $container_id   Container ID from media creation.
	 * @param string $media_kind     Media kind identifier (image, carousel, reel, story).
	 * @return array Result with success, media_id, media_kind, permalink.
	 */
	private static function publish_container( string $user_id, string $access_token, string $container_id, string $media_kind ): array {
		$publish_resp = wp_remote_post(
			self::GRAPH_API_URL . "/{$user_id}/media_publish",
			array(
				'body'    => array(
					'creation_id'  => $container_id,
					'access_token' => $access_token,
				),
				'timeout' => 40,
			)
		);

		if ( is_wp_error( $publish_resp ) ) {
			return array(
				'success' => false,
				'error'   => 'Error publishing to Instagram: ' . $publish_resp->get_error_message(),
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
				'error'   => $error_msg,
			);
		}

		$media_id = $publish_body['id'];

		// Fetch permalink.
		$permalink      = null;
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
			'success'    => true,
			'media_id'   => $media_id,
			'media_kind' => $media_kind,
			'permalink'  => $permalink,
		);
	}

	/**
	 * Wait for media container to be processed.
	 *
	 * @param string $access_token Access token.
	 * @param string $container_id Container ID.
	 * @param int    $max_retries  Maximum poll attempts (default 10 for images).
	 * @param int    $interval     Seconds between polls (default 1 for images).
	 * @return bool True if container is ready.
	 */
	private static function wait_for_container( string $access_token, string $container_id, int $max_retries = 10, int $interval = 1 ): bool {
		$url = self::GRAPH_API_URL . "/{$container_id}?fields=status_code&access_token={$access_token}";

		for ( $i = 0; $i < $max_retries; $i++ ) {
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

			sleep( $interval );
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
		$input;
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'instagram' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success'       => false,
				'error'         => 'Instagram not authenticated',
				'authenticated' => false,
			);
		}

		return array(
			'success'       => true,
			'authenticated' => true,
			'user_id'       => $provider->get_user_id() ?? '',
			'username'      => $provider->get_username() ?? '',
		);
	}
}
