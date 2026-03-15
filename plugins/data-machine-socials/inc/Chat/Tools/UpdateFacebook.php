<?php
/**
 * Update Facebook Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class UpdateFacebook extends BaseTool {

	public function __construct() {
		$this->registerTool( 'update_facebook', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Facebook Page posts. Edit message, hide, unhide, or delete posts. Requires Facebook OAuth to be configured.',
			'parameters'  => array(
				'action'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: "edit" (update message), "hide", "unhide", "delete"',
					'enum'        => array( 'edit', 'hide', 'unhide', 'delete' ),
				),
				'post_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Facebook post ID to operate on.',
				),
				'message' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New message text (required for edit action).',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_facebook';
		$action    = $parameters['action'] ?? '';

		if ( empty( $parameters['post_id'] ) ) {
			return $this->buildErrorResponse( 'post_id is required', $tool_name );
		}

		if ( 'edit' === $action && empty( $parameters['message'] ) ) {
			return $this->buildErrorResponse( 'message is required for edit action', $tool_name );
		}

		// Get auth provider.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'facebook' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Facebook auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'facebook',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_facebook_auth',
					'message'   => 'Facebook OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Facebook is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'facebook',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_facebook',
					'message'   => 'Facebook OAuth needs to be connected. Go to Data Machine Settings > Auth > Facebook.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		// Get the ability.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse( 'WordPress Abilities API not available', $tool_name );
		}

		$ability = wp_get_ability( 'datamachine/facebook-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/facebook-update ability not registered', $tool_name );
		}

		$ability_instance = $ability;
		$result           = $ability_instance->execute( array(
			'action'  => sanitize_text_field( $action ),
			'post_id' => sanitize_text_field( $parameters['post_id'] ),
			'message' => sanitize_text_field( $parameters['message'] ?? '' ),
		) );

		if ( $result['success'] ) {
			$message = '';
			switch ( $action ) {
				case 'edit':
					$message = 'Post updated successfully!';
					break;
				case 'hide':
					$message = 'Post hidden successfully!';
					break;
				case 'unhide':
					$message = 'Post unhidden successfully!';
					break;
				case 'delete':
					$message = 'Post deleted successfully!';
					break;
			}

			return array(
				'result'  => $message,
				'post_id' => $result['data']['post_id'] ?? $parameters['post_id'],
				'data'    => $result['data'],
			);
		}

		return $this->buildErrorResponse( $result['error'] ?? 'Update failed', $tool_name );
	}
}
