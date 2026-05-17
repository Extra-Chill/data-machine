<?php
/**
 * Agent Chat Tool.
 *
 * Lets an agent ask another registered agent one question through the canonical
 * Agents API `agents/chat` ability.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Engine\AI\Tools\BaseTool;

defined( 'ABSPATH' ) || exit;

class AgentChat extends BaseTool {

	private const MAX_AGENT_CHAT_DEPTH = 2;

	public function __construct() {
		$this->registerTool(
			'agent_chat',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array(
				'access_level'    => 'public',
				'requires_opt_in' => true,
			)
		);
	}

	/**
	 * Get the model-facing tool definition.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => self::class,
			'method'          => 'handle_tool_call',
			'backing_ability' => 'agents/chat',
			'description'     => 'Ask another registered agent a question through the canonical agents/chat ability and return its reply. Use this when a peer agent owns the needed context. Do not call yourself.',
			'parameters'      => array(
				'agent'           => array(
					'type'        => 'string',
					'description' => 'Target registered agent slug to ask, for example woocommerce-wiki.',
					'required'    => true,
				),
				'message'         => array(
					'type'        => 'string',
					'description' => 'Question or request to send to the peer agent.',
					'required'    => true,
				),
				'peer_session_id' => array(
					'type'        => 'string',
					'description' => 'Optional peer-agent session ID to continue. Omit to start a fresh peer turn.',
				),
			),
			'runtime'         => array(
				'duplicate_policy' => 'repeatable',
			),
		);
	}

	/**
	 * Execute the peer agent chat turn.
	 *
	 * @param array $parameters Tool parameters plus Data Machine runtime payload.
	 * @param array $tool_def   Tool definition.
	 * @return array Tool execution result.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		$target_agent = sanitize_title( (string) ( $parameters['agent'] ?? '' ) );
		$message      = trim( (string) ( $parameters['message'] ?? '' ) );

		if ( '' === $target_agent || '' === $message ) {
			return $this->buildErrorResponse( 'agent and message are required.', 'agent_chat' );
		}

		$current_agent = $this->current_agent_slug( $parameters );
		if ( '' !== $current_agent && $target_agent === $current_agent ) {
			return $this->buildErrorResponse( 'agent_chat cannot call the current agent. Ask a different peer agent.', 'agent_chat' );
		}

		$client_context = is_array( $parameters['client_context'] ?? null ) ? $parameters['client_context'] : array();
		$depth          = max( 0, (int) ( $client_context['agent_chat_depth'] ?? 0 ) );
		if ( $depth >= self::MAX_AGENT_CHAT_DEPTH ) {
			return $this->buildErrorResponse( 'agent_chat depth limit reached.', 'agent_chat' );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'agents/chat' ) : null;
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'agents/chat ability is not available.', 'agent_chat' );
		}

		$chat_input = array(
			'agent'          => $target_agent,
			'message'        => $message,
			'client_context' => $this->peer_client_context( $parameters, $client_context, $current_agent, $depth ),
		);

		$session_id = sanitize_text_field( (string) ( $parameters['peer_session_id'] ?? '' ) );
		if ( '' !== $session_id ) {
			$chat_input['session_id'] = $session_id;
		}

		$modes = $this->resolve_peer_modes( $parameters, $client_context );
		if ( ! empty( $modes ) ) {
			$chat_input['modes'] = $modes;
		}

		$permission = $ability->check_permissions( $chat_input );
		if ( is_wp_error( $permission ) ) {
			return $this->buildErrorResponse( $permission->get_error_message(), 'agent_chat' );
		}
		if ( true !== $permission ) {
			return $this->buildErrorResponse( 'agents/chat denied access to the target agent.', 'agent_chat' );
		}

		$result = $ability->execute( $chat_input );
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'agent_chat' );
		}

		if ( ! is_array( $result ) ) {
			return $this->buildErrorResponse( 'agents/chat returned an invalid response.', 'agent_chat' );
		}

		return array(
			'success'    => true,
			'tool_name'  => 'agent_chat',
			'agent'      => $target_agent,
			'session_id' => sanitize_text_field( (string) ( $result['session_id'] ?? '' ) ),
			'reply'      => (string) ( $result['reply'] ?? '' ),
			'messages'   => is_array( $result['messages'] ?? null ) ? $result['messages'] : array(),
			'completed'  => (bool) ( $result['completed'] ?? true ),
			'metadata'   => array_merge(
				is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array(),
				array(
					'source'          => 'agents/chat',
					'peer_agent'      => true,
					'caller_agent'    => $current_agent,
					'agent_chat_depth' => $depth + 1,
				)
			),
		);
	}

	/**
	 * Resolve the current agent slug from the runtime payload.
	 *
	 * @param array $parameters Tool parameters plus runtime payload.
	 * @return string
	 */
	private function current_agent_slug( array $parameters ): string {
		if ( ! empty( $parameters['agent_slug'] ) ) {
			return sanitize_title( (string) $parameters['agent_slug'] );
		}

		$agent_id = (int) ( $parameters['agent_id'] ?? 0 );
		if ( $agent_id <= 0 || ! class_exists( Agents::class ) ) {
			return '';
		}

		$row = ( new Agents() )->get_agent( $agent_id );
		return sanitize_title( (string) ( $row['agent_slug'] ?? '' ) );
	}

	/**
	 * Build client context for the peer chat request.
	 *
	 * @param array  $parameters      Tool parameters plus runtime payload.
	 * @param array  $client_context  Current client context.
	 * @param string $current_agent   Current agent slug.
	 * @param int    $depth           Current agent-chat depth.
	 * @return array
	 */
	private function peer_client_context( array $parameters, array $client_context, string $current_agent, int $depth ): array {
		$context = array_merge(
			$client_context,
			array(
				'source'           => 'bridge',
				'client_name'      => 'datamachine-agent-chat-tool',
				'connector_id'     => 'datamachine-agent-chat-tool',
				'peer_agent_call'  => true,
				'caller_agent'     => $current_agent,
				'agent_chat_depth' => $depth + 1,
			)
		);

		if ( isset( $parameters['session_id'] ) ) {
			$context['caller_session_id'] = sanitize_text_field( (string) $parameters['session_id'] );
		}

		return $context;
	}

	/**
	 * Resolve peer modes, inheriting the caller's active modes by default.
	 *
	 * @param array $parameters     Tool parameters plus runtime payload.
	 * @param array $client_context Current client context.
	 * @return array<int,string>
	 */
	private function resolve_peer_modes( array $parameters, array $client_context ): array {
		$modes = $parameters['modes'] ?? $parameters['agent_modes'] ?? $client_context['agent_modes'] ?? null;
		if ( ! is_array( $modes ) || empty( $modes ) ) {
			$mode  = (string) ( $parameters['mode'] ?? $client_context['agent_mode'] ?? $client_context['mode'] ?? '' );
			$modes = '' !== $mode ? array( $mode ) : array();
		}

		$normalized = array();
		foreach ( $modes as $mode ) {
			$mode = sanitize_key( (string) $mode );
			if ( '' !== $mode ) {
				$normalized[] = $mode;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
