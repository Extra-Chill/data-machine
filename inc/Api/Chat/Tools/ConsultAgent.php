<?php
/**
 * Consult Agent Tool.
 *
 * Lets an agent consult another registered agent through the canonical Agents
 * API `agents/chat` ability.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Engine\AI\Tools\BaseTool;

defined( 'ABSPATH' ) || exit;

class ConsultAgent extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'consult_agent',
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
			'description'     => 'Consult another registered agent through the canonical agents/chat ability and return its answer. Use this when a peer agent owns the needed context. Do not call yourself.',
			'parameters'      => array(
				'agent'           => array(
					'type'        => 'string',
					'description' => 'Target registered agent slug to consult, for example woocommerce-wiki.',
					'required'    => true,
				),
				'question'        => array(
					'type'        => 'string',
					'description' => 'Focused question or request to send to the peer agent.',
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
		$target_agent = sanitize_title( (string) ( $parameters['agent'] ?? '' ) );
		$question     = trim( (string) ( $parameters['question'] ?? $parameters['message'] ?? '' ) );

		if ( '' === $target_agent || '' === $question ) {
			return $this->buildErrorResponse( 'agent and question are required.', 'consult_agent' );
		}

		$current_agent = $this->current_agent_slug( $parameters );
		if ( '' !== $current_agent && $target_agent === $current_agent ) {
			return $this->buildErrorResponse( 'consult_agent cannot call the current agent. Consult a different peer agent.', 'consult_agent' );
		}

		$allowed_agents = $this->allowed_agents( $tool_def );
		if ( ! empty( $allowed_agents ) && ! in_array( $target_agent, $allowed_agents, true ) ) {
			return $this->buildErrorResponse( 'consult_agent can only call approved peer agents for this mode.', 'consult_agent' );
		}

		$client_context = is_array( $parameters['client_context'] ?? null ) ? $parameters['client_context'] : array();

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'agents/chat' ) : null;
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'agents/chat ability is not available.', 'consult_agent' );
		}

		$chat_input = array(
			'agent'          => $target_agent,
			'message'        => $question,
			'client_context' => $this->peer_client_context( $parameters, $client_context, $current_agent ),
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
			return $this->buildErrorResponse( $permission->get_error_message(), 'consult_agent' );
		}
		if ( true !== $permission ) {
			return $this->buildErrorResponse( 'agents/chat denied access to the target agent.', 'consult_agent' );
		}

		$result = $ability->execute( $chat_input );
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'consult_agent' );
		}

		if ( ! is_array( $result ) ) {
			return $this->buildErrorResponse( 'agents/chat returned an invalid response.', 'consult_agent' );
		}

		return array(
			'success'    => true,
			'tool_name'  => 'consult_agent',
			'agent'      => $target_agent,
			'peer_session_id' => sanitize_text_field( (string) ( $result['session_id'] ?? '' ) ),
			'answer'     => (string) ( $result['reply'] ?? '' ),
			'messages'   => is_array( $result['messages'] ?? null ) ? $result['messages'] : array(),
			'completed'  => (bool) ( $result['completed'] ?? true ),
			'metadata'   => array_merge(
				is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array(),
				array(
					'source'       => 'agents/chat',
					'peer_agent'   => true,
					'caller_agent' => $current_agent,
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
	 * @return array
	 */
	private function peer_client_context( array $parameters, array $client_context, string $current_agent ): array {
		$context = array_merge(
			$client_context,
			array(
				'source'           => 'peer-agent',
				'client_name'      => 'datamachine-consult-agent-tool',
				'connector_id'     => 'datamachine-consult-agent-tool',
				'peer_agent_call'  => true,
				'caller_agent'     => $current_agent,
			)
		);

		if ( isset( $parameters['session_id'] ) ) {
			$context['caller_session_id'] = sanitize_text_field( (string) $parameters['session_id'] );
		}

		return $context;
	}

	/**
	 * Resolve an optional tool-definition allowlist for peer agents.
	 *
	 * Domain integrations can set `allowed_agents` while exposing consult_agent in a
	 * custom mode. Empty means any accessible non-self agent remains allowed.
	 *
	 * @param array $tool_def Tool definition.
	 * @return array<int,string>
	 */
	private function allowed_agents( array $tool_def ): array {
		$allowed = $tool_def['allowed_agents'] ?? array();
		if ( ! is_array( $allowed ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $allowed as $agent ) {
			$agent = sanitize_title( (string) $agent );
			if ( '' !== $agent ) {
				$normalized[] = $agent;
			}
		}

		return array_values( array_unique( $normalized ) );
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
