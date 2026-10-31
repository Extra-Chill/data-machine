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

defined( 'ABSPATH' ) || exit;

class PublishLinkedIn extends AbstractSocialTool {

	protected string $tool_name = 'publish_linkedin';

	protected string $platform = 'linkedin';

	protected string $platform_label = 'LinkedIn';

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
				'type'       => 'object',
				'properties' => array(
					'content'       => array(
						'type'        => 'string',
						'description' => 'Post text. Up to 3000 characters.',
					),
					'visibility'    => array(
						'type'        => 'string',
						'description' => 'Post visibility: PUBLIC (anyone) or CONNECTIONS (connections only). Defaults to PUBLIC.',
					),
					'article_url'   => array(
						'type'        => 'string',
						'description' => 'Optional article URL to share as an article-type post.',
					),
					'article_title' => array(
						'type'        => 'string',
						'description' => 'Optional article title (used with article_url).',
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
		$tool_name = 'publish_linkedin';

		if ( empty( $parameters['content'] ) ) {
			return $this->buildErrorResponse( 'content is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
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
