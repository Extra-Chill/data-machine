<?php
/**
 * Publish Facebook Chat Tool
 *
 * Chat tool for publishing posts to a Facebook Page.
 * Wraps the datamachine/facebook-publish ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class PublishFacebook extends AbstractSocialTool {

	protected string $tool_name = 'publish_facebook';

	protected string $platform = 'facebook';

	protected string $platform_label = 'Facebook';

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Publish a post to a Facebook Page. Supports text with optional image and source URL. Requires Facebook OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'content'       => array(
						'type'        => 'string',
						'description' => 'Post content text.',
					),
					'title'         => array(
						'type'        => 'string',
						'description' => 'Optional title to prepend to the post content.',
					),
					'image_url'     => array(
						'type'        => 'string',
						'description' => 'Optional public URL of an image to attach to the post.',
					),
					'source_url'    => array(
						'type'        => 'string',
						'description' => 'Optional source URL to include in the post.',
					),
					'link_handling' => array(
						'type'        => 'string',
						'description' => 'How to handle the source URL: none, append (default), or comment (post as a follow-up comment).',
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
		$tool_name = 'publish_facebook';

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

		if ( ! empty( $parameters['link_handling'] ) ) {
			$input['link_handling'] = sanitize_text_field( $parameters['link_handling'] );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\Facebook\FacebookPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			$response = array(
				'result'   => 'Post published to Facebook!',
				'post_id'  => $result['post_id'] ?? '',
				'post_url' => $result['post_url'] ?? '',
			);

			if ( ! empty( $result['comment_id'] ) ) {
				$response['comment_id']  = $result['comment_id'];
				$response['comment_url'] = $result['comment_url'] ?? '';
			}

			return $response;
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Facebook publish failed' ), $tool_name );
	}
}
