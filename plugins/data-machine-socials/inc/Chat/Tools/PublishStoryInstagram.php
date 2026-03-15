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

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class PublishStoryInstagram extends BaseTool {

	public function __construct() {
		$this->registerTool( 'publish_story_instagram', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Publish an Instagram Story. Stories are ephemeral (24h lifespan) and support a single image or video. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'image_url' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Public URL of an image for the Story. Provide either image_url or video_url.',
				),
				'video_url' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Public URL of a video for the Story. Provide either image_url or video_url.',
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

		// Get auth provider and check authentication.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'instagram' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Instagram auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'instagram',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_instagram_auth',
					'message'   => 'Instagram OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Instagram is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'instagram',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_instagram',
					'message'   => 'Instagram OAuth needs to be connected. Go to Data Machine Settings > Auth > Instagram.',
					'tool_hint' => 'authenticate_handler',
				)
			);
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

		if ( $result['success'] ) {
			return array(
				'result'     => 'Story published to Instagram! (visible for 24 hours)',
				'media_id'   => $result['media_id'] ?? '',
				'media_kind' => $result['media_kind'] ?? 'story',
				'permalink'  => $result['permalink'] ?? '',
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Story publish failed', $tool_name );
	}
}
