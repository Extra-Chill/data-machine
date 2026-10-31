<?php
/**
 * Read Instagram Messages Chat Tool
 *
 * Chat tool for reading Instagram direct-message conversations and messages.
 * Wraps the datamachine/instagram-read ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.21.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReadInstagramMessages extends AbstractSocialTool {

	protected string $tool_name = 'read_instagram_messages';

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
			'description' => 'Read Instagram direct messages. List DM conversations or read the messages in one conversation. Requires Instagram OAuth with a Page access token. Note: Meta does not expose message requests from non-followers, so an absent thread is not proof the DM does not exist.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'description' => 'Action to perform: "conversations" (list DM threads) or "messages" (read one thread). Defaults to "conversations".',
						'enum'        => array( 'conversations', 'messages' ),
					),
					'conversation_id' => array(
						'type'        => 'string',
						'description' => 'Instagram conversation ID. Required for the "messages" action.',
					),
					'limit'           => array(
						'type'        => 'integer',
						'description' => 'Maximum conversations to return (max 100). Defaults to 25.',
					),
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
		$tool_name = $this->tool_name;
		$action    = $parameters['action'] ?? 'conversations';

		if ( 'messages' === $action && empty( $parameters['conversation_id'] ) ) {
			return $this->buildErrorResponse( 'conversation_id is required for the messages action', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/instagram-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/instagram-read ability not registered', $tool_name );
		}

		$input = array(
			'action' => sanitize_text_field( (string) $action ),
		);

		if ( ! empty( $parameters['conversation_id'] ) ) {
			$input['conversation_id'] = sanitize_text_field( (string) $parameters['conversation_id'] );
		}

		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			$error = is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to read Instagram messages' );
			return $this->buildErrorResponse( $error, $tool_name );
		}

		$data = $result['data'] ?? array();

		if ( 'messages' === $action ) {
			$messages = $data['messages'] ?? array();

			return array(
				'success'   => true,
				'data'      => $data,
				'message'   => sprintf( 'Found %d messages in conversation %s. Only the 20 most recent messages have detail; older messages error as deleted on Instagram.', count( $messages ), (string) ( $data['conversation_id'] ?? '' ) ),
				'tool_name' => $tool_name,
			);
		}

		$conversations = $data['conversations'] ?? array();
		if ( empty( $conversations ) ) {
			return array(
				'success'   => true,
				'data'      => null,
				'message'   => 'No Instagram conversations found. Note: Meta does not expose message requests from non-followers, so an absent thread is not proof the DM does not exist.',
				'tool_name' => $tool_name,
			);
		}

		$message = sprintf( 'Found %d Instagram conversations.', $data['count'] ?? count( $conversations ) );
		if ( ! empty( $data['degraded'] ) && ! empty( $data['note'] ) ) {
			$message .= ' ' . $data['note'];
		}

		return array(
			'success'   => true,
			'data'      => $data,
			'message'   => $message,
			'tool_name' => $tool_name,
			'guidance'  => array(
				'has_next'  => $data['has_next'] ?? false,
				'next_step' => ! empty( $data['has_next'] )
					? 'More conversations exist. Use read_instagram_messages with action "messages" and a conversation_id to read a thread.'
					: 'All recent conversations have been returned.',
			),
		);
	}
}
