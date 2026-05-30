<?php
/**
 * Publish Bluesky Chat Tool
 *
 * Chat tool for publishing posts to Bluesky.
 * Wraps the datamachine/bluesky-publish ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class PublishBluesky extends AbstractSocialTool {

	protected string $tool_name = 'publish_bluesky';

	protected string $platform = 'bluesky';

	protected string $platform_label = 'Bluesky';

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Publish a post to Bluesky. Supports text with optional image and source URL. Requires Bluesky authentication to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'content'    => array(
						'type'        => 'string',
						'description' => 'Post content text. Max 300 characters.',
					),
					'title'      => array(
						'type'        => 'string',
						'description' => 'Optional title for the post.',
					),
					'image_url'  => array(
						'type'        => 'string',
						'description' => 'Optional public URL of an image to attach.',
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => 'Optional source URL to embed as a link card.',
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
		$tool_name = 'publish_bluesky';

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

		if ( ! empty( $parameters['title'] ) ) {
			$input['title'] = sanitize_text_field( $parameters['title'] );
		}

		if ( ! empty( $parameters['image_url'] ) ) {
			$input['image_url'] = sanitize_url( $parameters['image_url'] );
		}

		if ( ! empty( $parameters['source_url'] ) ) {
			$input['source_url'] = sanitize_url( $parameters['source_url'] );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\Bluesky\BlueskyPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'   => 'Post published to Bluesky!',
				'post_id'  => $result['post_id'] ?? '',
				'post_url' => $result['post_url'] ?? '',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Bluesky publish failed' ), $tool_name );
	}
}
