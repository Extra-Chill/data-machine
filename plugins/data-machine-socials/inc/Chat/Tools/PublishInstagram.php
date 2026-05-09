<?php
/**
 * Publish Instagram Post Chat Tool
 *
 * Chat tool for publishing standard image/carousel posts to Instagram.
 * Wraps the datamachine/instagram-publish ability with media_kind=image
 * for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class PublishInstagram extends BaseTool {

	public function __construct() {
		$this->registerTool( 'publish_instagram', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Publish a standard image or carousel post to Instagram. Requires at least one image URL and a caption. Supports up to 10 images for carousel. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'caption'      => array(
						'type'        => 'string',
						'description' => 'Caption text for the post. Max 2200 characters.',
					),
					'image_urls'   => array(
						'type'        => 'array',
						'description' => 'Array of public image URLs to post. 1 image for single post, 2-10 for carousel.',
						'items'       => array(
							'type' => 'string',
						),
					),
					'aspect_ratio' => array(
						'type'        => 'string',
						'description' => 'Aspect ratio for images: 1:1, 4:5, 3:4, or 1.91:1. Defaults to 4:5.',
					),
					'source_url'   => array(
						'type'        => 'string',
						'description' => 'Optional source URL to include at the end of the caption.',
					),
				),
				'required'   => array( 'caption', 'image_urls' ),
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
		$tool_name = 'publish_instagram';

		if ( empty( $parameters['caption'] ) ) {
			return $this->buildErrorResponse( 'caption is required', $tool_name );
		}

		if ( empty( $parameters['image_urls'] ) || ! is_array( $parameters['image_urls'] ) ) {
			return $this->buildErrorResponse( 'image_urls is required (array of image URLs)', $tool_name );
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
			'media_kind' => 'image',
			'image_urls' => array_map( 'sanitize_url', $parameters['image_urls'] ),
		);

		if ( ! empty( $parameters['aspect_ratio'] ) ) {
			$input['aspect_ratio'] = sanitize_text_field( $parameters['aspect_ratio'] );
		}

		if ( ! empty( $parameters['source_url'] ) ) {
			$input['source_url'] = sanitize_url( $parameters['source_url'] );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'     => 'Post published to Instagram!',
				'media_id'   => $result['media_id'] ?? '',
				'media_kind' => $result['media_kind'] ?? 'image',
				'permalink'  => $result['permalink'] ?? '',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Instagram publish failed' ), $tool_name );
	}
}
