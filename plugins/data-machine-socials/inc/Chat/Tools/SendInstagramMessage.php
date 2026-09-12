<?php
/**
 * Send Instagram Message Chat Tool
 *
 * Chat tool for sending Instagram direct messages.
 * Wraps the datamachine/instagram-message-send ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.21.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class SendInstagramMessage extends AbstractSocialTool {

	protected string $tool_name = 'send_instagram_message';

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
			'description' => 'Send an Instagram direct message to a user. Requires Instagram OAuth with a Page access token. Instagram only allows replies inside the 24-hour messaging window.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'recipient_id'   => array(
						'type'        => 'string',
						'description' => 'Instagram-scoped user ID (IGSID) of the recipient, taken from the conversation participant.',
					),
					'message'        => array(
						'type'        => 'string',
						'description' => 'Text of the direct message to send.',
					),
					'messaging_type' => array(
						'type'        => 'string',
						'description' => 'Messaging type. Defaults to RESPONSE.',
						'enum'        => array( 'RESPONSE', 'UPDATE', 'MESSAGE_TAG' ),
					),
				),
				'required'   => array( 'recipient_id', 'message' ),
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
		$tool_name = $this->tool_name;

		if ( empty( $parameters['recipient_id'] ) ) {
			return $this->buildErrorResponse( 'recipient_id is required', $tool_name );
		}

		if ( empty( $parameters['message'] ) ) {
			return $this->buildErrorResponse( 'message is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/instagram-message-send' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/instagram-message-send ability not registered', $tool_name );
		}

		$payload = array(
			'recipient_id' => sanitize_text_field( (string) $parameters['recipient_id'] ),
			'message'      => sanitize_textarea_field( (string) $parameters['message'] ),
		);

		if ( ! empty( $parameters['messaging_type'] ) ) {
			$payload['messaging_type'] = sanitize_text_field( (string) $parameters['messaging_type'] );
		}

		$result = $ability->execute( $payload );

		if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
			return array(
				'result'       => 'Instagram direct message sent successfully!',
				'recipient_id' => $payload['recipient_id'],
				'message_id'   => $result['data']['message_id'] ?? '',
				'data'         => $result['data'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Failed to send Instagram message' ), $tool_name );
	}
}
