<?php
/**
 * Slack publish handler — posts messages to channels.
 *
 * Thin wrapper that maps pipeline context to ability input and delegates
 * the actual API call to PostMessageSlackAbility.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\Slack
 * @since 0.2.0
 */

namespace DataMachineBusiness\Handlers\Slack;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineBusiness\Abilities\Slack\PostMessageSlackAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SlackPublish extends PublishHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'slack_publish' );

		self::registerHandler(
			'slack_publish',
			'publish',
			self::class,
			__( 'Slack', 'data-machine-business' ),
			__( 'Post messages to Slack channels', 'data-machine-business' ),
			true,
			\DataMachineBusiness\OAuth\Providers\SlackAuth::class,
			SlackSettings::class,
			null,
			'slack'
		);
	}

	/**
	 * Publish a message to Slack by delegating to the ability.
	 *
	 * Maps pipeline parameters + handler config → ability input,
	 * then delegates the actual API call to PostMessageSlackAbility.
	 *
	 * @param array $parameters     Tool parameters including content
	 * @param array $handler_config Handler-specific configuration
	 * @return array Result with success, data/error, and tool_name
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$channel = $handler_config['slack_channel'] ?? '';
		if ( empty( $channel ) ) {
			return $this->errorResponse( 'Slack channel is not configured in handler settings' );
		}

		$text = $parameters['content'] ?? $parameters['text'] ?? '';
		if ( empty( $text ) ) {
			return $this->errorResponse( 'No message content provided' );
		}

		// Apply source URL handling from handler config
		$link_handling = $handler_config['link_handling'] ?? 'append';
		$source_url    = $parameters['source_url'] ?? '';
		if ( 'append' === $link_handling && ! empty( $source_url ) ) {
			$text .= "\n\n<" . $source_url . '>';
		}

		// Build ability input from pipeline context
		$ability_input = array(
			'channel' => $channel,
			'text'    => $text,
		);

		// Thread reply support
		$thread_ts = $parameters['thread_ts'] ?? $handler_config['slack_thread_ts'] ?? '';
		if ( ! empty( $thread_ts ) ) {
			$ability_input['thread_ts'] = $thread_ts;
		}

		// Unfurl links from handler config
		if ( ! empty( $handler_config['slack_unfurl_links'] ) ) {
			$ability_input['unfurl_links'] = true;
		}

		// Delegate to the ability — single source of API logic
		$ability = new PostMessageSlackAbility();
		$result  = $ability->execute( $ability_input );

		if ( empty( $result['success'] ) ) {
			return $this->errorResponse( $result['error'] ?? 'Unknown Slack error' );
		}

		return $this->successResponse(
			$result['data'] ?? array(),
			'slack_publish'
		);
	}

	/**
	 * Get handler display label.
	 *
	 * @return string Handler label
	 */
	public static function get_label(): string {
		return __( 'Slack Publish', 'data-machine-business' );
	}
}
