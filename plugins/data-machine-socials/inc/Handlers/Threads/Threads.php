<?php
/**
* Threads publish handler.
*
* Handles publishing content to Meta's Threads platform.
*
* @package DataMachineSocials
* @subpackage Handlers\Threads
* @since 1.0.0
*/

namespace DataMachineSocials\Handlers\Threads;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Threads\ThreadsPublishAbility;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Threads extends PublishHandler {

	use HandlerRegistrationTrait;

	/**
	* @var ThreadsAuth Authentication handler instance
	*/
	private $auth;

	public function __construct() {
		parent::__construct( 'threads' );

		// Self-register with filters
		self::registerHandler(
			'threads_publish',
			'publish',
			self::class,
			'Threads',
			'Post content to Meta Threads',
			true,
			ThreadsAuth::class,
			ThreadsSettings::class,
			function ( $handler_slug, $handler_config, $engine_data ) {
				return array(
					'threads_publish' => array(
						'class'                   => self::class,
						'client_context_bindings' => array( 'job_id' ),
						'method'      => 'handle_tool_call',
						'handler'     => $handler_slug,
						'description' => 'Post content to Meta Threads. Supports text and images.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'content' => array(
									'type'        => 'string',
									'description' => 'The text content to post to Threads',
								),
							),
							'required'   => array( 'content' ),
						),
					),
				);
			},
			'threads',
			array(
			'charLimit'          => 500,
			'maxImages'          => 10,
			'aspectRatios'       => array( 'any' ),
			'defaultAspectRatio' => 'any',
			'supportsCarousel'   => true,
			'capabilities'       => array(
				array( 'slug' => 'publish', 'label' => 'Publish' ),
			),
			'preview'            => array(
				'aspectRatio'     => 'native',
				'captionPosition' => 'below',
				'previewSurface'  => 'feed',
			),
			)
		);
	}

	/**
	* Lazy-load auth provider when needed.
	*
	* @return ThreadsAuth|null Auth provider instance or null if unavailable
	*/
	private function get_auth() {
		if ( null === $this->auth ) {
			$auth_abilities = new AuthAbilities();
			$this->auth     = $auth_abilities->getProvider( 'threads' );

			if ( null === $this->auth ) {
				$this->log(
					'error',
					'Threads Handler: Authentication service not available',
					array(
						'handler'             => 'threads',
						'missing_service'     => 'threads',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth;
	}

	protected function executePublish( array $parameters, array $handler_config ): array {
		$handler_config;
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
		}

		$media     = $this->resolveMediaUrls( $engine );
		$image_url = $media['image_url'];
		$video_url = $media['video_url'];

		$publish_input = array(
			'content'    => $parameters['content'] ?? '',
			'image_url'  => $image_url,
			'source_url' => $engine->getSourceUrl(),
		);

		if ( ! empty( $video_url ) ) {
			$publish_input['video_url'] = $video_url;
		}

		$result = ThreadsPublishAbility::execute_publish( $publish_input );

		if ( $result['success'] ) {
			return $this->successResponse(
			array(
				'media_id' => $result['post_id'] ?? '',
				'post_url' => $result['post_url'] ?? '',
				'content'  => $parameters['content'] ?? '',
			)
			);
		}

		return $this->errorResponse(
		$result['error'] ?? 'Threads publish failed',
		array(),
		'critical'
		);
	}

	public static function get_label(): string {
		return __( 'Threads', 'data-machine-socials' );
	}
}
