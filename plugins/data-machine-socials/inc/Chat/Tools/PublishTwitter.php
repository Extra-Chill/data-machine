<?php
/**
 * Publish Twitter Chat Tool
 *
 * Chat tool for publishing tweets to Twitter/X.
 * Wraps the datamachine/twitter-publish ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class PublishTwitter extends BaseTool {

	public function __construct() {
		$this->registerTool( 'publish_twitter', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Publish a tweet to Twitter/X. Supports text (max 280 characters) with optional image and source URL. Requires Twitter OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'content'    => array(
						'type'        => 'string',
						'description' => 'Tweet text. Max 280 characters.',
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => 'Optional source URL to include in or after the tweet.',
					),
					'link_handling' => array(
						'type'        => 'string',
						'description' => 'How to handle the source URL: append (add to tweet text) or reply (post as a reply). Defaults to append.',
					),
				),
				'required'   => array( 'content' ),
			),
		);
	}

	/**
	 * Handle chat tool call.
	 *
	 * @param array $parameters Tool parameters from AI agent.
	 * @param array $tool_def   Tool definition context.
	 * @return array Result for AI agent.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'publish_twitter';

		if ( empty( $parameters['content'] ) ) {
			return $this->buildErrorResponse( 'content is required', $tool_name );
		}

		// Get auth provider and check authentication.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'twitter' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Twitter auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'twitter',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_twitter_auth',
					'message'   => 'Twitter OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Twitter is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'twitter',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_twitter',
					'message'   => 'Twitter OAuth needs to be connected. Go to Data Machine Settings > Auth > Twitter.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		// Build ability input.
		$input = array(
			'content' => sanitize_textarea_field( $parameters['content'] ),
		);

		if ( ! empty( $parameters['source_url'] ) ) {
			$input['source_url'] = sanitize_url( $parameters['source_url'] );
		}

		if ( ! empty( $parameters['link_handling'] ) ) {
			$input['link_handling'] = sanitize_text_field( $parameters['link_handling'] );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\Twitter\TwitterPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			$response = array(
				'result'    => 'Tweet published to Twitter!',
				'tweet_id'  => $result['tweet_id'] ?? '',
				'tweet_url' => $result['tweet_url'] ?? '',
			);

			if ( ! empty( $result['reply_tweet_id'] ) ) {
				$response['reply_tweet_id']  = $result['reply_tweet_id'];
				$response['reply_tweet_url'] = $result['reply_tweet_url'] ?? '';
			}

			return $response;
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Twitter publish failed' ), $tool_name );
	}
}
