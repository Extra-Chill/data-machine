<?php
/**
 * TikTok publish handler.
 *
 * Handles publishing video content to TikTok via the Content Posting API
 * (Direct Post, PULL_FROM_URL). Only video is supported — TikTok has no
 * image-only post type via this API.
 *
 * @package DataMachineSocials
 * @subpackage Handlers\TikTok
 * @since 0.17.0
 */

namespace DataMachineSocials\Handlers\TikTok;

use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\TikTok\TikTokPublishAbility;
use DataMachineSocials\Handlers\TikTok\TikTokAuth;
use DataMachineSocials\Handlers\TikTok\TikTokSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TikTok extends PublishHandler {

	use HandlerRegistrationTrait;

	/**
	 * @var TikTokAuth Authentication handler instance
	 */
	private $auth;

	public function __construct() {
		parent::__construct( 'tiktok' );

		self::registerHandler(
			'tiktok_publish',
			'publish',
			self::class,
			'TikTok',
			'Post video content to TikTok via the Content Posting API (Direct Post, server-hosted URL)',
			true,
			TikTokAuth::class,
			TikTokSettings::class,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Registry callback signature.
			function ( $handler_slug, $_handler_config, $_engine_data ) {
				return array(
					'tiktok_publish' => array(
						'class'                   => self::class,
						'client_context_bindings' => array( 'job_id' ),
						'method'                  => 'handle_tool_call',
						'handler'                 => $handler_slug,
						'description'             => 'Post a video to TikTok from a public video URL. Pre-audit posts are private-only.',
						'parameters'              => array(
							'type'       => 'object',
							'properties' => array(
								'content'       => array(
									'type'        => 'string',
									'description' => 'The caption text to post to TikTok (max 2200 characters)',
								),
								'video_url'     => array(
									'type'        => 'string',
									'description' => 'Public HTTPS video URL for TikTok to pull',
									'format'      => 'uri',
								),
								'privacy_level' => array(
									'type'        => 'string',
									'description' => 'Visibility: PUBLIC_TO_EVERYONE (requires audit), SELF_ONLY, MUTUAL_FOLLOW_FRIENDS, FOLLOWER_OF_CREATOR',
									'enum'        => array( 'PUBLIC_TO_EVERYONE', 'SELF_ONLY', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR' ),
									'default'     => 'PUBLIC_TO_EVERYONE',
								),
							),
							'required'   => array( 'content', 'video_url' ),
						),
					),
				);
			},
			'tiktok',
			array(
				'charLimit'           => 2200,
				'maxImages'           => 0,
				'supportsVideo'       => true,
				'supportedMediaKinds' => array( 'video' ),
				'composer'            => array(
					'crossPostCompatible' => false,
					'mediaKinds'          => array( 'video' ),
					'ability'             => 'datamachine/tiktok-publish',
				),
				'capabilities'        => array(
					array(
						'slug'  => 'publish',
						'label' => 'Publish',
					),
				),
				'preview'             => array(
					'aspectRatio'     => '9:16',
					'captionPosition' => 'below',
					'previewSurface'  => 'vertical',
				),
			)
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return TikTokAuth|null Auth provider instance or null if unavailable.
	 */
	private function get_auth() {
		if ( null === $this->auth ) {
			$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
			$this->auth     = $auth_abilities->getProvider( 'tiktok' );

			if ( null === $this->auth ) {
				$this->log(
					'error',
					'TikTok Handler: Authentication service not available',
					array(
						'handler'             => 'tiktok',
						'missing_service'     => 'tiktok',
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

		// TikTok only supports video — resolve the video URL from engine data.
		$media     = $this->resolveMediaUrls( $engine );
		$video_url = $media['video_url'];

		if ( empty( $video_url ) ) {
			return $this->errorResponse(
				'TikTok requires a video. No video URL found in engine data.',
				array(),
				'critical'
			);
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

		$privacy_level = $parameters['privacy_level'] ?? $handler_config['default_privacy_level'] ?? 'PUBLIC_TO_EVERYONE';

		$publish_input = array(
			'content'       => $content,
			'video_url'     => $video_url,
			'privacy_level' => $privacy_level,
			'source_url'    => $engine->getSourceUrl(),
		);

		$result = TikTokPublishAbility::execute_publish( $publish_input );

		if ( is_wp_error( $result ) ) {
			return $this->errorResponse(
				$result->get_error_message(),
				array(),
				'critical'
			);
		}

		if ( ! empty( $result['success'] ) ) {
			return $this->successResponse(
				array(
					'publish_id'     => $result['publish_id'] ?? '',
					'status'         => $result['status'] ?? '',
					'public_post_id' => $result['public_post_id'] ?? '',
					'post_url'       => $result['post_url'] ?? '',
					'content'        => $content,
				)
			);
		}

		return $this->errorResponse(
			$result['error'] ?? 'TikTok publish failed',
			array(),
			'critical'
		);
	}

	public static function get_label(): string {
		return __( 'TikTok', 'data-machine-socials' );
	}
}
