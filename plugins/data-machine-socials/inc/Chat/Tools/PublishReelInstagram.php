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

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class PublishReelInstagram extends BaseTool {

	public function __construct() {
		$this->registerTool( 'publish_reel_instagram', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Publish an Instagram Reel (video post). Requires a public video URL and Instagram OAuth to be configured. Video processing may take up to 60 seconds.',
			'parameters'  => array(
				'caption'       => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Caption text for the Reel. Max 2200 characters.',
				),
				'video_url'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Public URL of the video file to publish as a Reel.',
				),
				'cover_url'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional public URL of a cover image for the Reel.',
				),
				'share_to_feed' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Whether to share the Reel to the main profile feed. Defaults to true.',
				),
				'source_url'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional source URL to include at the end of the caption.',
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
		$tool_name = 'publish_reel_instagram';

		// Validate required parameters.
		if ( empty( $parameters['caption'] ) ) {
			return $this->buildErrorResponse( 'caption is required', $tool_name );
		}

		if ( empty( $parameters['video_url'] ) ) {
			return $this->buildErrorResponse( 'video_url is required', $tool_name );
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

		if ( $result['success'] ) {
			return array(
				'result'     => 'Reel published to Instagram!',
				'media_id'   => $result['media_id'] ?? '',
				'media_kind' => $result['media_kind'] ?? 'reel',
				'permalink'  => $result['permalink'] ?? '',
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Reel publish failed', $tool_name );
	}
}
