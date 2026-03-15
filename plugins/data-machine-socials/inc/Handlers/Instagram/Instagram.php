<?php
/**
 * Instagram publish handler.
 *
 * Handles publishing content to Instagram with support for single images
 * and carousels (up to 10 images). Uses async container creation pattern.
 *
 * Ported from post-to-instagram plugin to data-machine-socials.
 *
 * @package DataMachineSocials
 * @subpackage Handlers\Instagram
 * @since 0.2.0
 */

namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Instagram\InstagramPublishAbility;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Instagram extends PublishHandler {

	use HandlerRegistrationTrait;

	/**
	 * @var InstagramAuth Authentication handler instance
	 */
	private $auth;

	public function __construct() {
		parent::__construct( 'instagram' );

		// Self-register with filters
		self::registerHandler(
			'instagram_publish',
			'publish',
			self::class,
			'Instagram',
			'Post content to Instagram with support for single images and carousels (up to 10 images)',
			true,
			InstagramAuth::class,
			InstagramSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				$handler_config;
				if ( 'instagram_publish' === $handler_slug ) {
					$tools['instagram_publish'] = array(
						'class'       => self::class,
						'method'      => 'handle_tool_call',
						'handler'     => 'instagram_publish',
						'description' => 'Post content to Instagram. Supports single images and carousels (up to 10 images). Images are processed async.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'content'      => array(
									'type'        => 'string',
									'description' => 'The caption text to post to Instagram (max 2200 characters)',
								),
								'image_urls'   => array(
									'type'        => 'array',
									'description' => 'Array of image URLs (1-10 for carousel)',
									'items'       => array(
										'type'   => 'string',
										'format' => 'uri',
									),
								),
								'aspect_ratio' => array(
									'type'        => 'string',
									'description' => 'Aspect ratio for images: 1:1, 4:5, 3:4, or 1.91:1',
									'enum'        => array( '1:1', '4:5', '3:4', '1.91:1' ),
									'default'     => '4:5',
								),
							),
							'required'   => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'instagram'
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return InstagramAuth|null Auth provider instance or null if unavailable
	 */
	private function get_auth() {
		if ( null === $this->auth ) {
			$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
			$this->auth     = $auth_abilities->getProvider( 'instagram' );

			if ( null === $this->auth ) {
				$this->log(
					'error',
					'Instagram Handler: Authentication service not available',
					array(
						'handler'             => 'instagram',
						'missing_service'     => 'instagram',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth;
	}

	protected function executePublish( array $parameters, array $handler_config ): array {
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
		}

		// Resolve media from engine data — video takes priority (becomes a Reel).
		$media          = $this->resolveMediaUrls( $engine );
		$video_url      = $media['video_url'];
		$video_file_path = $media['video_file_path'];
		$image_url      = $media['image_url'];

		// Build image URLs array.
		$image_urls = array();
		if ( ! empty( $image_url ) ) {
			$image_urls[] = $image_url;
		}

		// If additional images provided in parameters.
		if ( ! empty( $parameters['image_urls'] ) && is_array( $parameters['image_urls'] ) ) {
			$image_urls = array_merge( $image_urls, $parameters['image_urls'] );
		}

		// Get aspect ratio from config or parameters.
		$aspect_ratio = $parameters['aspect_ratio'] ?? $handler_config['default_aspect_ratio'] ?? '4:5';

		// Auto-detect media_kind: if video is present, publish as Reel.
		$media_kind = 'image';
		if ( ! empty( $video_url ) ) {
			$media_kind = 'reel';
		}

		// Get content based on caption source setting.
		$content        = '';
		$caption_source = $handler_config['caption_source'] ?? 'content';

		switch ( $caption_source ) {
			case 'post_excerpt':
				$content = $engine->getPostExcerpt() ?? $parameters['content'] ?? '';
				break;
			case 'post_title':
				$content = $engine->getPostTitle() ?? $parameters['content'] ?? '';
				break;
			case 'content':
			default:
				$content = $parameters['content'] ?? '';
				break;
		}

		$publish_input = array(
			'content'      => $content,
			'media_kind'   => $media_kind,
			'image_urls'   => $image_urls,
			'aspect_ratio' => $aspect_ratio,
			'source_url'   => $engine->getSourceUrl(),
		);

		// Pass video URL and file path for Reel publishing (file path enables pre-publish validation).
		if ( ! empty( $video_url ) ) {
			$publish_input['video_url']       = $video_url;
			$publish_input['video_file_path'] = $video_file_path;
		}

		$result = InstagramPublishAbility::execute_publish( $publish_input );

		if ( $result['success'] ) {
			return $this->successResponse(
				array(
					'media_id'    => $result['media_id'] ?? '',
					'media_kind'  => $result['media_kind'] ?? $media_kind,
					'permalink'   => $result['permalink'] ?? '',
					'content'     => $content,
					'image_count' => count( $image_urls ),
				)
			);
		}

		return $this->errorResponse(
			$result['error'] ?? 'Instagram publish failed',
			array(),
			'critical'
		);
	}

	public static function get_label(): string {
		return __( 'Instagram', 'data-machine-socials' );
	}
}
