<?php
/**
 * Update Facebook Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class UpdateFacebook extends AbstractSocialTool {

	protected string $tool_name = 'update_facebook';

	protected string $platform = 'facebook';

	protected string $platform_label = 'Facebook';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Facebook Page posts. Edit message, hide, unhide, or delete posts. Requires Facebook OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'  => array(
						'type'        => 'string',
						'description' => 'Action: "edit" (update message), "hide", "unhide", "delete"',
						'enum'        => array( 'edit', 'hide', 'unhide', 'delete' ),
					),
					'post_id' => array(
						'type'        => 'string',
						'description' => 'Facebook post ID to operate on.',
					),
					'message' => array(
						'type'        => 'string',
						'description' => 'New message text (required for edit action).',
					),
				),
				'required'   => array( 'action', 'post_id' ),
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

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		// Get the ability.
		$ability = wp_get_ability( 'datamachine/facebook-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/facebook-update ability not registered', $tool_name );
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

		if ( ! is_wp_error( $result ) && $result['success'] ) {
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

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Update failed' ), $tool_name );
	}
}
