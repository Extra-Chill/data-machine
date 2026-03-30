<?php
/**
 * Publish LinkedIn Chat Tool
 *
 * Chat tool for publishing posts to LinkedIn.
 * Wraps the datamachine/linkedin-publish ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class PublishLinkedIn extends BaseTool {

	public function __construct() {
		$this->registerTool( 'publish_linkedin', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Publish a post to LinkedIn. Supports text (up to 3000 characters) with optional image and article sharing. Requires LinkedIn OAuth to be configured.',
			'parameters'  => array(
				'content'       => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Post text. Up to 3000 characters.',
				),
				'visibility'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Post visibility: PUBLIC (anyone) or CONNECTIONS (connections only). Defaults to PUBLIC.',
				),
				'article_url'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional article URL to share as an article-type post.',
				),
				'article_title' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional article title (used with article_url).',
				),
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
		$tool_name = 'publish_linkedin';

		if ( empty( $parameters['content'] ) ) {
			return $this->buildErrorResponse( 'content is required', $tool_name );
		}

		// Get auth provider and check authentication.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'linkedin' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'LinkedIn auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'linkedin',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_linkedin_auth',
					'message'   => 'LinkedIn OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'LinkedIn is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'linkedin',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_linkedin',
					'message'   => 'LinkedIn OAuth needs to be connected. Go to Data Machine Settings > Auth > LinkedIn.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		// Build ability input.
		$input = array(
			'content' => sanitize_textarea_field( $parameters['content'] ),
		);

		if ( ! empty( $parameters['visibility'] ) ) {
			$input['visibility'] = sanitize_text_field( $parameters['visibility'] );
		}

		if ( ! empty( $parameters['article_url'] ) ) {
			$input['article_url'] = sanitize_url( $parameters['article_url'] );
		}

		if ( ! empty( $parameters['article_title'] ) ) {
			$input['article_title'] = sanitize_text_field( $parameters['article_title'] );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\LinkedIn\LinkedInPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'   => 'Post published to LinkedIn!',
				'post_id'  => $result['post_id'] ?? '',
				'post_url' => $result['post_url'] ?? '',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'LinkedIn publish failed' ), $tool_name );
	}
}
