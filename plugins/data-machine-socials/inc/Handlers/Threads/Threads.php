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
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'threads_publish' === $handler_slug ) {
					$tools['threads_publish'] = array(
						'class' => self::class,
						'method' => 'handle_tool_call',
						'handler' => 'threads_publish',
						'description' => 'Post content to Meta Threads. Supports text and images.',
						'parameters' => array(
							'type' => 'object',
							'properties' => array(
								'content' => array(
									'type' => 'string',
									'description' => 'The text content to post to Threads',
								),
							),
							'required' => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'threads'
		);
	}

	/**
	* Lazy-load auth provider when needed.
	*
	* @return ThreadsAuth|null Auth provider instance or null if unavailable
	*/
	private function get_auth() {
		if ( $this->auth === null ) {
			$auth_abilities = new AuthAbilities();
			$this->auth = $auth_abilities->getProvider( 'threads' );

			if ( $this->auth === null ) {
				$this->log(
					'error',
					'Threads Handler: Authentication service not available',
					array(
						'handler' => 'threads',
						'missing_service' => 'threads',
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

$file_storage = new \DataMachine\Core\FilesRepository\FileStorage();
$image_url = '';
$image_file_path = $engine->getImagePath();
if ( ! empty( $image_file_path ) ) {
$image_url = $file_storage->get_public_url( $image_file_path );
}

$result = ThreadsPublishAbility::execute_publish(
array(
'content' => $parameters['content'] ?? '',
'image_url' => $image_url,
'source_url' => $engine->getSourceUrl(),
)
);

if ( $result['success'] ) {
return $this->successResponse(
array(
'media_id' => $result['post_id'] ?? '',
'post_url' => $result['post_url'] ?? '',
'content' => $parameters['content'] ?? '',
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
