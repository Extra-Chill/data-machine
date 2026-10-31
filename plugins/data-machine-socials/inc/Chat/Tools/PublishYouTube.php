<?php
/**
 * Upload YouTube Video Chat Tool
 *
 * Chat tool for uploading a video to YouTube. Wraps the
 * datamachine/youtube-upload ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.17.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class PublishYouTube extends AbstractSocialTool {

	protected string $tool_name = 'publish_youtube';

	protected string $platform = 'youtube';

	protected string $platform_label = 'YouTube';

	/**
	 * Get tool definition for AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Upload a video to YouTube via the Data API resumable upload protocol. Requires a title and either a local file path or a public video URL. Defaults to private until the API project passes YouTube compliance audit. Community posts are not supported.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'title'       => array(
						'type'        => 'string',
						'description' => 'Video title (max 100 characters).',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Video description (max 5000 characters).',
					),
					'file_path'   => array(
						'type'        => 'string',
						'description' => 'Absolute local path to the video file to upload.',
					),
					'video_url'   => array(
						'type'        => 'string',
						'description' => 'Public URL of a video to download and upload (used when no local path is available).',
					),
					'tags'        => array(
						'type'        => 'array',
						'description' => 'Video tags.',
						'items'       => array( 'type' => 'string' ),
					),
					'category_id' => array(
						'type'        => 'string',
						'description' => 'YouTube category ID (e.g. 10 = Music).',
					),
					'privacy'     => array(
						'type'        => 'string',
						'description' => 'Privacy status: private (default), unlisted, or public. Unverified projects can only upload private.',
					),
				),
				'required'   => array( 'title' ),
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
		$tool_name = 'publish_youtube';

		if ( empty( $parameters['title'] ) ) {
			return $this->buildErrorResponse( 'title is required', $tool_name );
		}

		if ( empty( $parameters['file_path'] ) && empty( $parameters['video_url'] ) ) {
			return $this->buildErrorResponse( 'Either file_path or video_url is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$input = array(
			'title'          => sanitize_text_field( $parameters['title'] ),
			'privacy_status' => in_array( $parameters['privacy'] ?? '', array( 'private', 'unlisted', 'public' ), true ) ? $parameters['privacy'] : 'private',
		);

		if ( ! empty( $parameters['description'] ) ) {
			$input['description'] = sanitize_textarea_field( $parameters['description'] );
		}
		if ( ! empty( $parameters['file_path'] ) ) {
			$input['video_file_path'] = $parameters['file_path'];
		}
		if ( ! empty( $parameters['video_url'] ) ) {
			$input['video_url'] = sanitize_url( $parameters['video_url'] );
		}
		if ( ! empty( $parameters['tags'] ) && is_array( $parameters['tags'] ) ) {
			$input['tags'] = array_map( 'sanitize_text_field', $parameters['tags'] );
		}
		if ( ! empty( $parameters['category_id'] ) ) {
			$input['category_id'] = sanitize_text_field( $parameters['category_id'] );
		}

		$result = \DataMachineSocials\Abilities\YouTube\YouTubeUploadAbility::execute_upload( $input );

		if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
			return array(
				'result'         => 'Video uploaded to YouTube!',
				'video_id'       => $result['video_id'] ?? '',
				'url'            => $result['url'] ?? '',
				'privacy_status' => $result['privacy_status'] ?? 'private',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'YouTube upload failed' ), $tool_name );
	}
}
