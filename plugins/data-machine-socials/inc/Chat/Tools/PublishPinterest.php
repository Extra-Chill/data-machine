<?php
/**
 * Publish Pinterest Chat Tool
 *
 * Chat tool for publishing pins to Pinterest.
 * Wraps the datamachine/pinterest-publish ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class PublishPinterest extends BaseTool {

	public function __construct() {
		$this->registerTool( 'publish_pinterest', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Publish a pin to Pinterest. Requires a title, description, and image URL. Optionally specify a board and destination link. Requires Pinterest OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'title'       => array(
						'type'        => 'string',
						'description' => 'Pin title. Max 100 characters.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Pin description. Max 500 characters.',
					),
					'image_url'   => array(
						'type'        => 'string',
						'description' => 'Public URL of the image for the pin.',
					),
					'link'        => array(
						'type'        => 'string',
						'description' => 'Optional destination URL the pin will link to.',
					),
					'board_id'    => array(
						'type'        => 'string',
						'description' => 'Optional Pinterest board ID. Uses default board if not specified.',
					),
				),
				'required'   => array( 'title', 'description', 'image_url' ),
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
		$tool_name = 'publish_pinterest';

		if ( empty( $parameters['title'] ) ) {
			return $this->buildErrorResponse( 'title is required', $tool_name );
		}

		if ( empty( $parameters['description'] ) ) {
			return $this->buildErrorResponse( 'description is required', $tool_name );
		}

		if ( empty( $parameters['image_url'] ) ) {
			return $this->buildErrorResponse( 'image_url is required', $tool_name );
		}

		// Get auth provider and check authentication.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'pinterest' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Pinterest auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'pinterest',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_pinterest_auth',
					'message'   => 'Pinterest OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Pinterest is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'pinterest',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_pinterest',
					'message'   => 'Pinterest OAuth needs to be connected. Go to Data Machine Settings > Auth > Pinterest.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		// Build ability input.
		$input = array(
			'title'       => sanitize_text_field( $parameters['title'] ),
			'description' => sanitize_textarea_field( $parameters['description'] ),
			'image_url'   => sanitize_url( $parameters['image_url'] ),
		);

		if ( ! empty( $parameters['link'] ) ) {
			$input['link'] = sanitize_url( $parameters['link'] );
		}

		if ( ! empty( $parameters['board_id'] ) ) {
			$input['board_id'] = sanitize_text_field( $parameters['board_id'] );
		}

		// Execute via the publish ability.
		$result = \DataMachineSocials\Abilities\Pinterest\PinterestPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'  => 'Pin published to Pinterest!',
				'pin_id'  => $result['pin_id'] ?? '',
				'pin_url' => $result['pin_url'] ?? '',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Pinterest publish failed' ), $tool_name );
	}
}
