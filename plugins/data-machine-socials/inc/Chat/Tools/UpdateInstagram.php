<?php
/**
 * Update Instagram Chat Tool
 *
 * Chat tool for updating Instagram media (edit caption, delete, archive).
 * Wraps the datamachine/instagram-update ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class UpdateInstagram extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'update_instagram', array( $this, 'getToolDefinition' ) );
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
			'description' => 'Update Instagram media. Edit caption, delete a post, or archive a post. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'action'   => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "edit" (update caption), "delete" (remove post), "archive" (hide from profile)',
					'enum'        => array( 'edit', 'delete', 'archive' ),
				),
				'media_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Instagram media ID to update.',
				),
				'caption'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New caption text (required for "edit" action). Max 2200 characters.',
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
		$tool_name = 'update_instagram';
		$action    = $parameters['action'] ?? '';

		// Validate required parameters.
		if ( empty( $parameters['media_id'] ) ) {
			return $this->buildErrorResponse( 'media_id is required', $tool_name );
		}

		if ( 'edit' === $action && empty( $parameters['caption'] ) ) {
			return $this->buildErrorResponse( 'caption is required for edit action', $tool_name );
		}

		// Get auth provider and valid token.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'instagram' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Instagram auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'instagram', 'status' => 'not_registered' ),
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
				array( 'provider' => 'instagram', 'status' => 'not_authenticated' ),
				array(
					'action'    => 'authenticate_instagram',
					'message'   => 'Instagram OAuth needs to be connected. Go to Data Machine Settings > Auth > Instagram.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		// Get the ability.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse( 'WordPress Abilities API not available', $tool_name );
		}

		$ability = wp_get_ability( 'datamachine/instagram-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/instagram-update ability not registered', $tool_name );
		}

		// Build ability input.
		$input = array(
			'action'   => sanitize_text_field( $action ),
			'media_id' => sanitize_text_field( $parameters['media_id'] ),
		);

		if ( ! empty( $parameters['caption'] ) ) {
			$input['caption'] = sanitize_text_field( $parameters['caption'] );
		}

		// Execute via ability (which handles token retrieval internally).
		$ability_instance = new \DataMachineSocials\Abilities\Instagram\InstagramUpdateAbility();
		$result           = $ability_instance->execute( $input );

		// Format response for AI.
		if ( $result['success'] ) {
			$message = '';
			switch ( $action ) {
				case 'edit':
					$message = 'Caption updated successfully!';
					break;
				case 'delete':
					$message = 'Post deleted successfully!';
					break;
				case 'archive':
					$message = 'Post archived successfully!';
					break;
			}

			return array(
				'result'   => $message,
				'media_id' => $result['data']['media_id'] ?? $parameters['media_id'],
				'data'     => $result['data'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Update failed', $tool_name );
	}
}
