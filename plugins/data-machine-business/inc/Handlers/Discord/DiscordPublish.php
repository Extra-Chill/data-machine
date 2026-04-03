<?php
/**
 * Discord publish handler — posts messages to channels.
 *
 * Thin wrapper that maps pipeline context to ability input and delegates
 * the actual API call to PostMessageDiscordAbility.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\Discord
 * @since 0.3.0
 */

namespace DataMachineBusiness\Handlers\Discord;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineBusiness\Abilities\Discord\PostMessageDiscordAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiscordPublish extends PublishHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'discord_publish' );

		self::registerHandler(
			'discord_publish',
			'publish',
			self::class,
			__( 'Discord', 'data-machine-business' ),
			__( 'Post messages to Discord channels', 'data-machine-business' ),
			true,
			\DataMachineBusiness\OAuth\Providers\DiscordAuth::class,
			DiscordSettings::class,
			null,
			'discord'
		);
	}

	/**
	 * Publish a message to Discord by delegating to the ability.
	 *
	 * Maps pipeline parameters + handler config → ability input,
	 * then delegates the actual API call to PostMessageDiscordAbility.
	 *
	 * @param array $parameters     Tool parameters including content
	 * @param array $handler_config Handler-specific configuration
	 * @return array Result with success, data/error, and tool_name
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$channel_id = $handler_config['discord_channel_id'] ?? '';
		if ( empty( $channel_id ) ) {
			return $this->errorResponse( 'Discord channel is not configured in handler settings' );
		}

		$content = $parameters['content'] ?? $parameters['text'] ?? '';
		if ( empty( $content ) ) {
			return $this->errorResponse( 'No message content provided' );
		}

		// Apply source URL handling from handler config
		$link_handling = $handler_config['link_handling'] ?? 'append';
		$source_url    = $parameters['source_url'] ?? '';
		if ( 'append' === $link_handling && ! empty( $source_url ) ) {
			$content .= "\n\n" . $source_url;
		}

		// Build ability input from pipeline context
		$ability_input = array(
			'channel_id' => $channel_id,
			'content'    => $content,
		);

		// Delegate to the ability — single source of API logic
		$ability = new PostMessageDiscordAbility();
		$result  = $ability->execute( $ability_input );

		if ( empty( $result['success'] ) ) {
			return $this->errorResponse( $result['error'] ?? 'Unknown Discord error' );
		}

		return $this->successResponse(
			$result['data'] ?? array(),
			'discord_publish'
		);
	}

	/**
	 * Get handler display label.
	 *
	 * @return string Handler label
	 */
	public static function get_label(): string {
		return __( 'Discord Publish', 'data-machine-business' );
	}
}
