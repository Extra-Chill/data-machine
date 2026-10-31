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

defined( 'ABSPATH' ) || exit;

class UpdateInstagram extends AbstractSocialTool {

	protected string $tool_name = 'update_instagram';

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
			'description' => 'Update Instagram media. Edit caption, delete a post, or archive a post. Requires Instagram OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'description' => 'Action to perform: "edit" (update caption), "delete" (remove post), "archive" (hide from profile)',
						'enum'        => array( 'edit', 'delete', 'archive' ),
					),
					'media_id' => array(
						'type'        => 'string',
						'description' => 'Instagram media ID to update.',
					),
					'caption'  => array(
						'type'        => 'string',
						'description' => 'New caption text (required for "edit" action). Max 2200 characters.',
					),
				),
				'required'   => array( 'action', 'media_id' ),
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

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		// Get the ability.
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
		$ability = wp_get_ability( 'datamachine/instagram-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/instagram-update ability not registered', $tool_name );
		}
		$ability_instance = $ability;
		$result           = $ability_instance->execute( $input );

		// Format response for AI.
		if ( ! is_wp_error( $result ) && $result['success'] ) {
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

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Update failed' ), $tool_name );
	}
}
