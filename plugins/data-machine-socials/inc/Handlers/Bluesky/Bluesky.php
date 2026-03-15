<?php
/**
* Bluesky publisher with AT Protocol authentication
*/

namespace DataMachineSocials\Handlers\Bluesky;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Bluesky\BlueskyPublishAbility;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bluesky extends PublishHandler {

	use HandlerRegistrationTrait;

	private $auth;

	public function __construct() {
		parent::__construct( 'bluesky' );

		// Self-register with filters
		self::registerHandler(
			'bluesky_publish',
			'publish',
			self::class,
			'Bluesky',
			'Post content to Bluesky social network',
			true,
			BlueskyAuth::class,
			null,
			function ( $tools, $handler_slug, $handler_config ) {
				$handler_config;
				if ( 'bluesky_publish' === $handler_slug ) {
					$tools['bluesky_publish'] = array(
						'class'       => self::class,
						'method'      => 'handle_tool_call',
						'handler'     => 'bluesky_publish',
						'description' => 'Post content to Bluesky. Supports text and images.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'content' => array(
									'type'        => 'string',
									'description' => 'The text content to post to Bluesky',
								),
							),
							'required'   => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'bluesky'
		);
	}

	/**
	* Lazy-load auth provider when needed.
	*
	* @return BlueskyAuth|null Auth provider instance or null if unavailable
	*/
	private function get_auth() {
		if ( null === $this->auth ) {
			$auth_abilities = new AuthAbilities();
			$this->auth     = $auth_abilities->getProvider( 'bluesky' );

			if ( null === $this->auth ) {
				$this->log(
					'error',
					'Bluesky Handler: Authentication service not available',
					array(
						'handler'             => 'bluesky',
						'missing_service'     => 'bluesky',
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

		$file_storage    = new \DataMachine\Core\FilesRepository\FileStorage();
		$image_url       = '';
		$video_url       = '';
		$image_file_path = $engine->getImagePath();
		$video_file_path = $engine->getVideoPath();

		if ( ! empty( $video_file_path ) ) {
			$validation = $this->validateVideo( $video_file_path );
			if ( $validation['valid'] ) {
				$video_url = $file_storage->get_public_url( $video_file_path );
			}
		}
		if ( ! empty( $image_file_path ) ) {
			$image_url = $file_storage->get_public_url( $image_file_path );
		}

		$publish_input = array(
			'title'      => $parameters['title'] ?? '',
			'content'    => $parameters['content'] ?? '',
			'image_url'  => $image_url,
			'source_url' => $engine->getSourceUrl(),
		);

		if ( ! empty( $video_url ) ) {
			$publish_input['video_url'] = $video_url;
		}

		$result = BlueskyPublishAbility::execute_publish( $publish_input );

		if ( $result['success'] ) {
			return $this->successResponse(
			array(
				'post_uri' => $result['post_id'] ?? '',
				'post_url' => $result['post_url'] ?? '',
				'content'  => $parameters['content'] ?? '',
			)
			);
		}

		return $this->errorResponse(
		$result['error'] ?? 'Bluesky publish failed',
		array(),
		'critical'
		);
	}


	/**
	Returns the user-friendly label for this publish handler.

	@return string The label.
*/
	public static function get_label(): string {
		return __( 'Post to Bluesky', 'data-machine-socials' );
	}
}
