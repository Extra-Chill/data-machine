<?php
/**
 * Publish Instagram Reel Chat Tool
 *
 * Chat tool for publishing Reels (video) to Instagram.
 * Wraps the datamachine/instagram-publish ability with media_kind=reel
 * for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.4.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class PublishReelInstagram extends AbstractSocialTool {

	protected string $tool_name = 'publish_reel_instagram';

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
			'description' => 'Publish an Instagram Reel (video post). Requires a public video URL and Instagram OAuth to be configured. Video processing may take up to 60 seconds.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'caption'       => array(
						'type'        => 'string',
						'description' => 'Caption text for the Reel. Max 2200 characters.',
					),
					'video_url'     => array(
						'type'        => 'string',
						'description' => 'Public URL of the video file to publish as a Reel.',
					),
					'cover_url'     => array(
						'type'        => 'string',
						'description' => 'Optional public URL of a cover image for the Reel.',
					),
					'share_to_feed' => array(
						'type'        => 'boolean',
						'description' => 'Whether to share the Reel to the main profile feed. Defaults to true.',
					),
					'source_url'    => array(
						'type'        => 'string',
						'description' => 'Optional source URL to include at the end of the caption.',
					),
				),
				'required'   => array( 'caption', 'video_url' ),
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
		$tool_name = 'publish_reel_instagram';

		// Validate required parameters.
		if ( empty( $parameters['caption'] ) ) {
			return $this->buildErrorResponse( 'caption is required', $tool_name );
		}

		if ( empty( $parameters['video_url'] ) ) {
			return $this->buildErrorResponse( 'video_url is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		// Build ability input.
		$input = array(
			'content'    => sanitize_textarea_field( $parameters['caption'] ),
			'media_kind' => 'reel',
			'video_url'  => sanitize_url( $parameters['video_url'] ),
		);

		if ( ! empty( $parameters['cover_url'] ) ) {
			$input['cover_url'] = sanitize_url( $parameters['cover_url'] );
		}

		if ( isset( $parameters['share_to_feed'] ) ) {
			$input['share_to_feed'] = (bool) $parameters['share_to_feed'];
		}

		if ( ! empty( $parameters['source_url'] ) ) {
			$input['source_url'] = sanitize_url( $parameters['source_url'] );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'     => 'Reel published to Instagram!',
				'media_id'   => $result['media_id'] ?? '',
				'media_kind' => $result['media_kind'] ?? 'reel',
				'permalink'  => $result['permalink'] ?? '',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Reel publish failed' ), $tool_name );
	}
}
