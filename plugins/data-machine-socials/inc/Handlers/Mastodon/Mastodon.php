<?php
/**
 * Mastodon publisher with instance-agnostic OAuth2 authentication.
 *
 * @package DataMachineSocials
 * @subpackage Handlers\Mastodon
 * @since 0.17.0
 */

namespace DataMachineSocials\Handlers\Mastodon;

use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Mastodon\MastodonPublishAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mastodon extends PublishHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'mastodon' );

		// Self-register with filters.
		self::registerHandler(
			'mastodon_publish',
			'publish',
			self::class,
			'Mastodon',
			'Post content to a Mastodon / Fediverse instance',
			true,
			MastodonAuth::class,
			MastodonSettings::class,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Registry callback signature.
			function ( $handler_slug, $_handler_config, $_engine_data ) {
				return array(
					'mastodon_publish' => array(
						'class'                   => self::class,
						'client_context_bindings' => array( 'job_id' ),
						'method'                  => 'handle_tool_call',
						'handler'                 => $handler_slug,
						'description'             => 'Post content to Mastodon. Supports text, images, and source URLs.',
						'parameters'              => array(
							'type'       => 'object',
							'properties' => array(
								'content' => array(
									'type'        => 'string',
									'description' => 'The text content to post to Mastodon',
								),
							),
							'required'   => array( 'content' ),
						),
					),
				);
			},
			'mastodon',
			array(
				'charLimit'          => MastodonAuth::DEFAULT_CHAR_LIMIT,
				'maxImages'          => 4,
				'aspectRatios'       => array( 'any' ),
				'defaultAspectRatio' => 'any',
				'supportsCarousel'   => false,
				'composer'           => array(
					'crossPostCompatible' => false,
					'mediaKinds'          => array( 'text', 'image' ),
					'ability'             => 'datamachine/mastodon-publish',
				),
				'capabilities'       => array(
					array(
						'slug'  => 'publish',
						'label' => 'Publish',
					),
				),
				'preview'            => array(
					'aspectRatio'     => '16:9',
					'captionPosition' => 'above',
					'previewSurface'  => 'feed',
				),
			)
		);
	}

	protected function executePublish( array $parameters, array $handler_config ): array {
		$handler_config;
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
		}

		$media     = $this->resolveMediaUrls( $engine );
		$image_url = $media['image_url'];

		$visibility = $handler_config['visibility'] ?? 'public';

		$publish_input = array(
			'content'    => $parameters['content'] ?? '',
			'image_url'  => $image_url,
			'source_url' => $engine->getSourceUrl(),
			'visibility' => $visibility,
		);

		$title = $parameters['title'] ?? '';
		if ( ! empty( $title ) ) {
			$publish_input['title'] = $title;
		}

		$link_handling = $handler_config['link_handling'] ?? 'append';
		if ( ! empty( $link_handling ) ) {
			$publish_input['link_handling'] = $link_handling;
		}

		$result = MastodonPublishAbility::execute_publish( $publish_input );

		if ( $result['success'] ) {
			return $this->successResponse(
				array(
					'post_id'  => $result['post_id'] ?? '',
					'post_url' => $result['post_url'] ?? '',
					'content'  => $parameters['content'] ?? '',
				)
			);
		}

		return $this->errorResponse(
			$result['error'] ?? 'Mastodon publish failed',
			array(),
			'critical'
		);
	}

	/**
	 * Returns the user-friendly label for this publish handler.
	 *
	 * @return string The label.
	 */
	public static function get_label(): string {
		return __( 'Post to Mastodon', 'data-machine-socials' );
	}
}
