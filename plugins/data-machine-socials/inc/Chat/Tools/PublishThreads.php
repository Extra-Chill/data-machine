<?php
/**
 * Publish Threads Chat Tool
 *
 * Chat tool for publishing posts to Threads.
 * Wraps the datamachine/threads-publish ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class PublishThreads extends AbstractSocialTool {

	protected string $tool_name = 'publish_threads';

	protected string $platform = 'threads';

	protected string $platform_label = 'Threads';

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Publish a post to Threads. Supports text (max 500 characters) with optional image and source URL. Requires Threads OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'content'    => array(
						'type'        => 'string',
						'description' => 'Post content text. Max 500 characters.',
					),
					'image_url'  => array(
						'type'        => 'string',
						'description' => 'Optional public URL of an image to attach to the post.',
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => 'Optional source URL to append to the post text.',
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
		$tool_name = 'publish_threads';

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

		if ( ! empty( $parameters['image_url'] ) ) {
			$input['image_url'] = sanitize_url( $parameters['image_url'] );
		}

		if ( ! empty( $parameters['source_url'] ) ) {
			$input['source_url'] = sanitize_url( $parameters['source_url'] );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\Threads\ThreadsPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'   => 'Post published to Threads!',
				'post_id'  => $result['post_id'] ?? '',
				'post_url' => $result['post_url'] ?? '',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Threads publish failed' ), $tool_name );
	}
}
