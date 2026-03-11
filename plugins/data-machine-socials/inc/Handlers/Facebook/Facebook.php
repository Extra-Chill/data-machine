<?php
/**
* Modular Facebook publish handler.
*
* Posts content to a specified Facebook Page using the self-contained
* FacebookAuth class for authentication. This modular approach separates
* concerns between main handler logic and authentication functionality.
*
* @package Data_Machine_Socials
* @subpackage Handlers\Facebook
* @since 0.1.0
*/

namespace DataMachineSocials\Handlers\Facebook;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Facebook\FacebookPublishAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Facebook extends PublishHandler {

	use HandlerRegistrationTrait;

	/**
	* @var FacebookAuth Authentication handler instance
	*/
	private $auth;

	public function __construct() {
		parent::__construct( 'facebook' );

		// Self-register with filters
		self::registerHandler(
			'facebook_publish',
			'publish',
			self::class,
			'Facebook',
			'Post content to Facebook Pages',
			true,
			FacebookAuth::class,
			FacebookSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				$handler_config;
				if ( 'facebook_publish' === $handler_slug ) {
					$tools['facebook_publish'] = array(
						'class'       => self::class,
						'method'      => 'handle_tool_call',
						'handler'     => 'facebook_publish',
						'description' => 'Post content to a Facebook Page. Supports text and images.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'content' => array(
									'type'        => 'string',
									'description' => 'The text content to post to Facebook',
								),
							),
							'required'   => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'facebook'
		);
	}

	/**
	* Lazy-load auth provider when needed.
	*
	* @return FacebookAuth|null Auth provider instance or null if unavailable
	*/
	private function get_auth() {
		if ( null === $this->auth ) {
			$auth_abilities = new AuthAbilities();
			$this->auth     = $auth_abilities->getProvider( 'facebook' );

			if ( null === $this->auth ) {
				$this->log(
					'error',
					'Facebook Handler: Authentication service not available',
					array(
						'handler'             => 'facebook',
						'missing_service'     => 'facebook',
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

		$file_storage    = new \DataMachine\Core\FilesRepository\FileStorage();
		$image_url       = '';
		$image_file_path = $engine->getImagePath();
		if ( ! empty( $image_file_path ) ) {
			$image_url = $file_storage->get_public_url( $image_file_path );
		}

		$result = FacebookPublishAbility::execute_publish(
		array(
			'title'         => $parameters['title'] ?? '',
			'content'       => $parameters['content'] ?? '',
			'image_url'     => $image_url,
			'source_url'    => $engine->getSourceUrl(),
			'link_handling' => $handler_config['link_handling'] ?? 'append',
		)
		);

		if ( $result['success'] ) {
			return $this->successResponse(
			array(
				'post_id'     => $result['post_id'] ?? '',
				'post_url'    => $result['post_url'] ?? '',
				'content'     => $parameters['content'] ?? '',
				'comment_id'  => $result['comment_id'] ?? null,
				'comment_url' => $result['comment_url'] ?? null,
			)
			);
		}

		return $this->errorResponse(
		$result['error'] ?? 'Facebook publish failed',
		array(),
		'critical'
		);
	}

	public static function get_label(): string {
		return __( 'Facebook', 'data-machine-socials' );
	}
}
