<?php
/**
 * Agents API canonical chat handler adapter.
 *
 * @package DataMachine\Abilities\Chat
 */

namespace DataMachine\Abilities\Chat;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Api\Chat\ChatOrchestrator;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\PluginSettings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Data Machine as the runtime behind the canonical `agents/chat` ability.
 */
class AgentsChatHandler {

	/**
	 * Attach handler and permission filters.
	 */
	public function __construct() {
		if ( function_exists( '\AgentsAPI\AI\Channels\register_chat_handler' ) ) {
			\AgentsAPI\AI\Channels\register_chat_handler( array( $this, 'execute' ) );
		} else {
			add_filter( 'wp_agent_chat_handler', array( $this, 'registerHandler' ), 10, 2 );
		}

		add_filter( 'agents_chat_permission', array( $this, 'checkPermission' ), 10, 2 );
	}

	/**
	 * Register this adapter unless another runtime already won.
	 *
	 * @param callable|null $handler Existing handler.
	 * @param array         $input   Canonical chat input.
	 * @return callable|null
	 */
	public function registerHandler( $handler, array $input ) {
		unset( $input );

		return null !== $handler ? $handler : array( $this, 'execute' );
	}

	/**
	 * Permission callback for the canonical `agents/chat` ability.
	 *
	 * @param bool  $allowed Existing permission decision.
	 * @param array $input   Canonical chat input.
	 * @return bool
	 */
	public function checkPermission( bool $allowed, array $input ): bool {
		unset( $input );

		return $allowed || PermissionHelper::can( 'chat' );
	}

	/**
	 * Execute the canonical chat request through Data Machine's chat runtime.
	 *
	 * @param array $input Canonical agents/chat input.
	 * @return array|WP_Error Canonical agents/chat output.
	 */
	public function execute( array $input ): array|WP_Error {
		$message = trim( (string) ( $input['message'] ?? '' ) );
		if ( '' === $message ) {
			return new WP_Error( 'empty_message', __( 'Message cannot be empty.', 'data-machine' ), array( 'status' => 400 ) );
		}

		$user_id = PermissionHelper::acting_user_id();
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id <= 0 ) {
			return new WP_Error( 'no_user', __( 'No user context available.', 'data-machine' ), array( 'status' => 400 ) );
		}

		$agent_id = $this->resolveAgentId( (string) ( $input['agent'] ?? '' ) );
		if ( $agent_id instanceof WP_Error ) {
			return $agent_id;
		}

		$client_context = is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array();
		$mode           = $this->resolveMode( $input, $client_context );
		$agent_config   = PluginSettings::resolveModelForAgentMode( 0 === $agent_id ? null : $agent_id, $mode );
		$provider       = $agent_config['provider'];
		$model          = $agent_config['model'];

		if ( '' === $model && 'chat' !== $mode ) {
			$chat_config = PluginSettings::resolveModelForAgentMode( 0 === $agent_id ? null : $agent_id, 'chat' );
			$provider    = '' === $provider ? $chat_config['provider'] : $provider;
			$model       = $chat_config['model'];
		}

		if ( '' === $provider ) {
			return new WP_Error( 'provider_required', __( 'AI provider is required. Set a default in Data Machine settings.', 'data-machine' ), array( 'status' => 400 ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'model_required', __( 'AI model is required. Set a default in Data Machine settings.', 'data-machine' ), array( 'status' => 400 ) );
		}

		$result = ChatOrchestrator::processChat(
			$message,
			sanitize_text_field( $provider ),
			sanitize_text_field( $model ),
			$user_id,
			array(
				'session_id'     => $input['session_id'] ?? null,
				'mode'           => $mode,
				'agent_id'       => $agent_id,
				'attachments'    => $input['attachments'] ?? array(),
				'client_context' => $client_context,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->toCanonicalOutput( $result );
	}

	/**
	 * Resolve canonical agent input into Data Machine's integer agent ID.
	 *
	 * @param string $agent Agent slug or numeric ID.
	 * @return int|WP_Error
	 */
	private function resolveAgentId( string $agent ) {
		$agent = trim( $agent );
		if ( '' === $agent ) {
			return 0;
		}

		if ( ctype_digit( $agent ) ) {
			return (int) $agent;
		}

		$row = ( new Agents() )->get_by_slug( sanitize_title( $agent ) );
		if ( ! $row ) {
			return new WP_Error( 'agent_not_found', __( 'Agent not found.', 'data-machine' ), array( 'status' => 404 ) );
		}

		return (int) ( $row['agent_id'] ?? 0 );
	}

	/**
	 * Resolve the Data Machine execution mode from canonical Agents API input.
	 *
	 * @param array $input Canonical agents/chat input.
	 * @param array $client_context Transport-level client context.
	 * @return string Sanitized mode slug.
	 */
	private function resolveMode( array $input, array $client_context ): string {
		$mode = $input['mode'] ?? $client_context['agent_mode'] ?? $client_context['mode'] ?? 'chat';
		$mode = sanitize_key( (string) $mode );

		return '' !== $mode ? $mode : 'chat';
	}

	/**
	 * Convert Data Machine chat output to the canonical agents/chat output shape.
	 *
	 * @param array $result Data Machine chat response.
	 * @return array
	 */
	private function toCanonicalOutput( array $result ): array {
		$metadata                = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
		$metadata['datamachine'] = array_filter(
			array(
				'tool_calls'  => $result['tool_calls'] ?? null,
				'max_turns'   => $result['max_turns'] ?? null,
				'turn_number' => $result['turn_number'] ?? null,
			),
			static fn( $value ): bool => null !== $value
		);

		return array(
			'session_id' => (string) ( $result['session_id'] ?? '' ),
			'reply'      => (string) ( $result['response'] ?? '' ),
			'messages'   => $this->toCanonicalMessages( $result['conversation'] ?? array() ),
			'completed'  => (bool) ( $result['completed'] ?? true ),
			'metadata'   => $metadata,
		);
	}

	/**
	 * Project Data Machine envelopes to the simple canonical message list.
	 *
	 * @param array $conversation Conversation messages.
	 * @return array<int,array{role:string,content:string}>
	 */
	private function toCanonicalMessages( array $conversation ): array {
		$messages = array();

		foreach ( $conversation as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}

			if ( ! is_string( $message['content'] ) ) {
				continue;
			}

			$messages[] = array(
				'role'    => (string) $message['role'],
				'content' => $message['content'],
			);
		}

		return $messages;
	}
}
