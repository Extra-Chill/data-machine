<?php
/**
 * Publish Instagram Story Chat Tool
 *
 * Chat tool for publishing Stories (ephemeral image or video) to Instagram.
 * Wraps the datamachine/instagram-publish ability with media_kind=story
 * for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.4.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class PublishStoryInstagram extends AbstractSocialTool {

	protected string $tool_name = 'publish_story_instagram';

	protected string $platform = 'instagram';

	protected string $platform_label = 'Instagram';

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Publish an Instagram Story. Stories are ephemeral (24h lifespan) and support a single image or video. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'image_url' => array(
						'type'        => 'string',
						'description' => 'Public URL of an image for the Story. Provide either image_url or video_url.',
					),
					'video_url' => array(
						'type'        => 'string',
						'description' => 'Public URL of a video for the Story. Provide either image_url or video_url.',
					),
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
		$tool_name = 'publish_story_instagram';

		$image_url = $parameters['image_url'] ?? '';
		$video_url = $parameters['video_url'] ?? '';

		// Validate — need exactly one media source.
		if ( empty( $image_url ) && empty( $video_url ) ) {
			return $this->buildErrorResponse( 'image_url or video_url is required for Story publishing', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		// Build ability input.
		$input = array(
			'content'    => 'Story',
			'media_kind' => 'story',
		);

		if ( ! empty( $video_url ) ) {
			$input['video_url'] = sanitize_url( $video_url );
		} else {
			$input['story_image_url'] = sanitize_url( $image_url );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'     => 'Story published to Instagram! (visible for 24 hours)',
				'media_id'   => $result['media_id'] ?? '',
				'media_kind' => $result['media_kind'] ?? 'story',
				'permalink'  => $result['permalink'] ?? '',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Story publish failed' ), $tool_name );
	}
}
