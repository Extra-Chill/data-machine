<?php
/**
* Twitter publishing handler with OAuth 1.0a and AI tool integration.
*
* Handles posting content to Twitter with support for:
* - Text content (280 character limit)
* - Media uploads (images)
* - URL handling and link shortening
* - Thread creation for long content
*
* @package DataMachineSocials\Handlers\Twitter
*/

namespace DataMachineSocials\Handlers\Twitter;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Twitter\TwitterPublishAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Twitter Publishing Handler
 *
 * Publishes content to Twitter via OAuth 1.0a authentication.
 * Supports media uploads and handles URL shortening automatically.
 */
class Twitter extends PublishHandler {

	use HandlerRegistrationTrait;

	/** @var TwitterAuth OAuth authentication handler */
	private $auth;

	public function __construct() {
		parent::__construct( 'twitter' );

		// Self-register with filters
		self::registerHandler(
			'twitter_publish',
			'publish',
			self::class,
			'Twitter',
			'Post content to Twitter with media support',
			true,
			TwitterAuth::class,
			TwitterSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'twitter_publish' === $handler_slug ) {
					$tools['twitter_publish'] = array(
						'class' => self::class,
						'method' => 'handle_tool_call',
						'handler' => 'twitter_publish',
						'description' => 'Post content to Twitter. Supports text (280 chars), images, and URL handling.',
						'parameters' => array(
							'type' => 'object',
							'properties' => array(
								'content' => array(
									'type' => 'string',
									'description' => 'The text content to post to Twitter',
								),
							),
							'required' => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'twitter'
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return TwitterAuth|null Auth provider instance or null if unavailable
	 */
	private function get_auth() {
		if ( $this->auth === null ) {
			$auth_abilities = new AuthAbilities();
			$this->auth = $auth_abilities->getProvider( 'twitter' );

			if ( $this->auth === null ) {
				$this->log(
					'error',
					'Twitter Handler: Authentication service not available',
					array(
						'handler' => 'twitter',
						'missing_service' => 'twitter',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth;
	}

/**
* Execute Twitter publishing.
*
* @param array $parameters Tool parameters including content and configuration
* @param array $handler_config Handler configuration
* @return array {
* @type bool $success Whether the post was successful
* @type string $error Error message if failed
* @type string $tool_name Tool identifier
* @type string $url Twitter post URL if successful
* @type string $id Twitter post ID if successful
* }
*/
protected function executePublish( array $parameters, array $handler_config ): array {
$engine = $parameters['engine'] ?? null;
if ( ! $engine instanceof EngineData ) {
$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
}

$result = TwitterPublishAbility::execute_publish(
array(
'content' => $parameters['content'] ?? '',
'image_path' => $engine->getImagePath(),
'source_url' => $engine->getSourceUrl(),
'link_handling' => $handler_config['link_handling'] ?? 'append',
)
);

if ( $result['success'] ) {
return $this->successResponse(
array(
'tweet_id' => $result['tweet_id'] ?? '',
'tweet_url' => $result['tweet_url'] ?? '',
'content' => $parameters['content'] ?? '',
'reply_tweet_id' => $result['reply_tweet_id'] ?? null,
'reply_tweet_url' => $result['reply_tweet_url'] ?? null,
)
);
}

return $this->errorResponse(
$result['error'] ?? 'Twitter publish failed',
array(),
'critical'
);
}

public static function get_label(): string {
return __( 'Post to Twitter', 'data-machine-socials' );
}
}
