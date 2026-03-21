<?php
/**
 * LinkedIn publishing handler with OAuth 2.0 and AI tool integration.
 *
 * Handles posting content to LinkedIn with support for:
 * - Text content (up to ~3,000 characters)
 * - Media uploads (images)
 * - Article sharing with thumbnails
 * - Member and organization posting
 *
 * @package DataMachineSocials\Handlers\LinkedIn
 * @since 0.5.0
 */

namespace DataMachineSocials\Handlers\LinkedIn;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\LinkedIn\LinkedInPublishAbility;
defined( 'ABSPATH' ) || exit;

/**
 * LinkedIn Publishing Handler
 *
 * Publishes content to LinkedIn via OAuth 2.0 authentication.
 * Supports media uploads and article sharing.
 */
class LinkedIn extends PublishHandler {

	use HandlerRegistrationTrait;

	/** @var LinkedInAuth OAuth authentication handler */
	private $auth;

	public function __construct() {
		parent::__construct( 'linkedin' );

		// Self-register with filters.
		self::registerHandler(
			'linkedin_publish',
			'publish',
			self::class,
			'LinkedIn',
			'Post content to LinkedIn with media and article support',
			true,
			LinkedInAuth::class,
			LinkedInSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				$handler_config;
				if ( 'linkedin_publish' === $handler_slug ) {
					$tools['linkedin_publish'] = array(
						'class'       => self::class,
						'method'      => 'handle_tool_call',
						'handler'     => 'linkedin_publish',
						'description' => 'Post content to LinkedIn. Supports text (up to 3000 chars), images, and article sharing.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'content' => array(
									'type'        => 'string',
									'description' => 'The text content to post to LinkedIn',
								),
							),
							'required'   => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'linkedin',
			array(
				'charLimit'          => 3000,
				'maxImages'          => 9,
				'aspectRatios'       => array( 'any' ),
				'defaultAspectRatio' => 'any',
				'supportsCarousel'   => false,
			)
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return LinkedInAuth|null Auth provider instance or null if unavailable.
	 */
	private function get_auth() {
		if ( null === $this->auth ) {
			$auth_abilities = new AuthAbilities();
			$this->auth     = $auth_abilities->getProvider( 'linkedin' );

			if ( null === $this->auth ) {
				$this->log(
					'error',
					'LinkedIn Handler: Authentication service not available',
					array(
						'handler'             => 'linkedin',
						'missing_service'     => 'linkedin',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth;
	}

	/**
	 * Execute LinkedIn publishing.
	 *
	 * @param array $parameters Tool parameters including content and configuration.
	 * @param array $handler_config Handler configuration.
	 * @return array Response with post details or error.
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
		}

		$media_path = $engine->getImagePath();

		$result = LinkedInPublishAbility::execute_publish(
			array(
				'content'     => $parameters['content'] ?? '',
				'image_path'  => $media_path,
				'source_url'  => $engine->getSourceUrl(),
				'visibility'  => $handler_config['visibility'] ?? 'PUBLIC',
			)
		);

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
			$result['error'] ?? 'LinkedIn publish failed',
			array(),
			'critical'
		);
	}

	public static function get_label(): string {
		return __( 'Post to LinkedIn', 'data-machine-socials' );
	}
}
