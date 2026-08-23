<?php
/**
 * YouTube publish handler.
 *
 * Pipeline integration for uploading video content to YouTube. Resolves video
 * media from engine data (video_file_path from the pipeline flow) and uploads
 * it via the resumable upload ability, defaulting to private until the API
 * project passes YouTube's compliance audit.
 *
 * @package DataMachineSocials
 * @subpackage Handlers\YouTube
 * @since 0.17.0
 */

namespace DataMachineSocials\Handlers\YouTube;

use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\YouTube\YouTubeUploadAbility;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YouTube extends PublishHandler {

	use HandlerRegistrationTrait;

	/**
	 * @var YouTubeAuth Authentication handler instance.
	 */
	private $auth;

	public function __construct() {
		parent::__construct( 'youtube' );

		self::registerHandler(
			'youtube_publish',
			'publish',
			self::class,
			'YouTube',
			'Upload a video to YouTube via the Data API resumable upload protocol',
			true,
			YouTubeAuth::class,
			YouTubeSettings::class,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Registry callback signature.
			function ( $handler_slug, $_handler_config, $_engine_data ) {
				return array(
					'youtube_publish' => array(
						'class'                   => self::class,
						'client_context_bindings' => array( 'job_id' ),
						'method'                  => 'handle_tool_call',
						'handler'                 => $handler_slug,
						'description'             => 'Upload a video to YouTube. Requires a video source (local file path or URL) and a title. Defaults to private until the API project is audit-verified.',
						'parameters'              => array(
							'type'       => 'object',
							'properties' => array(
								'title'       => array(
									'type'        => 'string',
									'description' => 'Video title',
								),
								'description' => array(
									'type'        => 'string',
									'description' => 'Video description',
								),
							),
							'required'   => array( 'title' ),
						),
					),
				);
			},
			'youtube',
			array(
				'supportsVideo' => true,
				'composer'      => array(
					'crossPostCompatible' => false,
					'mediaKinds'          => array( 'video' ),
					'ability'             => 'datamachine/youtube-upload',
				),
				'capabilities'  => array(
					array(
						'slug'  => 'publish',
						'label' => 'Publish',
					),
				),
				'preview'       => array(
					'aspectRatio'     => '16:9',
					'captionPosition' => 'below',
					'previewSurface'  => 'feed',
				),
			)
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return YouTubeAuth|null Auth provider instance or null if unavailable.
	 */
	private function get_auth() {
		if ( null === $this->auth ) {
			$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
			$this->auth     = $auth_abilities->getProvider( 'youtube' );

			if ( null === $this->auth ) {
				$this->log(
					'error',
					'YouTube Handler: Authentication service not available',
					array(
						'handler'             => 'youtube',
						'missing_service'     => 'youtube',
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

		// Resolve video from engine data (pipeline flow provides a local file path).
		$media           = $this->resolveMediaUrls( $engine );
		$video_url       = $media['video_url'];
		$video_file_path = $media['video_file_path'];

		// Build title/description from engine content when not passed explicitly.
		$title       = ! empty( $parameters['title'] ) ? $parameters['title'] : ( $engine->getPostTitle() ?? '' );
		$description = ! empty( $parameters['description'] ) ? $parameters['description'] : ( $parameters['content'] ?? '' );

		if ( empty( $title ) ) {
			$title = $engine->getPostExcerpt() ?? '';
		}

		$publish_input = array(
			'title'       => $title,
			'description' => $description,
		);

		// Prefer the local file path (avoids an extra download round-trip).
		if ( ! empty( $video_file_path ) ) {
			$publish_input['video_file_path'] = $video_file_path;
		} elseif ( ! empty( $video_url ) ) {
			$publish_input['video_url'] = $video_url;
		}

		// Privacy status from handler config or default to private (audit gate).
		if ( ! empty( $handler_config['privacy_status'] ) ) {
			$publish_input['privacy_status'] = $handler_config['privacy_status'];
		}

		$result = YouTubeUploadAbility::execute_upload( $publish_input );

		if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
			return $this->successResponse(
				array(
					'video_id'       => $result['video_id'] ?? '',
					'url'            => $result['url'] ?? '',
					'privacy_status' => $result['privacy_status'] ?? 'private',
					'title'          => $title,
				)
			);
		}

		return $this->errorResponse(
			is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'YouTube upload failed' ),
			array(),
			'critical'
		);
	}

	public static function get_label(): string {
		return __( 'YouTube', 'data-machine-socials' );
	}
}
