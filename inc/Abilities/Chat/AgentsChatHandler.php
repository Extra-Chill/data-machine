<?php
/**
 * Agents API canonical chat handler adapter.
 *
 * @package DataMachine\Abilities\Chat
 */

namespace DataMachine\Abilities\Chat;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Api\Chat\ChatOrchestrator;
use DataMachine\Core\Agents\AgentIdentity;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
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
		\AgentsAPI\AI\Channels\register_chat_handler( array( $this, 'execute' ) );

		add_filter( 'agents_chat_permission', array( $this, 'checkPermission' ), 10, 2 );
	}

	/**
	 * Permission callback for the canonical `agents/chat` ability.
	 *
	 * @param bool  $allowed Existing permission decision.
	 * @param array $input   Canonical chat input.
	 * @return bool
	 */
	public function checkPermission( bool $allowed, array $input ): bool {
		if ( ! $allowed ) {
			$principal = $this->resolveCallerPrincipal( $input );
			if ( null !== $principal && (bool) apply_filters( 'agents_chat_runtime_principal_permission', false, $principal, $input ) ) {
				return true;
			}
		}

		$agent = trim( (string) ( $input['agent'] ?? '' ) );
		if ( '' === $agent ) {
			return $allowed || PermissionHelper::can( 'chat' );
		}

		$identity = $this->resolveAgentIdentity( $agent );
		if ( $identity instanceof WP_Error ) {
			return $allowed;
		}

		return $allowed || \WP_Agent_Access::can_current_principal_access_agent( $identity->agent_slug, \WP_Agent_Access_Grant::ROLE_VIEWER );
	}

	/**
	 * Resolve an explicit non-REST runtime principal supplied by trusted runtime code.
	 *
	 * @param array $input Canonical chat input.
	 * @return object|null
	 */
	private function resolveCallerPrincipal( array $input ): ?object {
		$principal_class = '\AgentsAPI\AI\WP_Agent_Execution_Principal';
		if ( defined( 'REST_REQUEST' ) || ! class_exists( $principal_class ) ) {
			return null;
		}

		$principal = $input['principal'] ?? null;
		if ( $principal instanceof \AgentsAPI\AI\WP_Agent_Execution_Principal ) {
			return $principal;
		}

		if ( is_array( $principal ) ) {
			try {
				return \AgentsAPI\AI\WP_Agent_Execution_Principal::from_array( $principal );
			} catch ( \InvalidArgumentException ) {
				return null;
			}
		}

		return null;
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

		$identity = $this->resolveAgentIdentity( (string) ( $input['agent'] ?? '' ) );
		if ( $identity instanceof WP_Error ) {
			return $identity;
		}
		$agent_id = $identity ? $identity->agent_id : 0;

		$calling_user_id = $this->resolveCallingUserId( $input );
		$user_id = $this->resolveRuntimeUserId( $identity, (string) ( $input['agent'] ?? '' ), $input );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'no_user', __( 'No user context available.', 'data-machine' ), array( 'status' => 400 ) );
		}

		$client_context = is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array();
		$modes          = $this->resolveModes( $input, $client_context );
		$mode           = implode( ',', $modes );
		$agent_config   = PluginSettings::resolveModelForAgentModes( 0 === $agent_id ? null : $agent_id, $modes, 'chat' );
		$provider       = (string) ( $input['provider'] ?? '' );
		$model          = (string) ( $input['model'] ?? '' );
		if ( '' === $provider ) {
			$provider = $agent_config['provider'];
		}
		if ( '' === $model ) {
			$model = $agent_config['model'];
		}

		if ( '' === $provider ) {
			return new WP_Error( 'provider_required', __( 'AI provider is required. Set a default in Data Machine settings.', 'data-machine' ), array( 'status' => 400 ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'model_required', __( 'AI model is required. Set a default in Data Machine settings.', 'data-machine' ), array( 'status' => 400 ) );
		}

		$workspace = $this->resolveTranscriptWorkspace( $input );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		$result = ChatOrchestrator::processChat(
			$message,
			sanitize_text_field( $provider ),
			sanitize_text_field( $model ),
			$user_id,
			array(
				'session_id'            => $input['session_id'] ?? null,
				'selected_pipeline_id'  => (int) ( $input['selected_pipeline_id'] ?? 0 ),
				'max_turns'             => $input['max_turns'] ?? null,
				'request_id'            => $input['request_id'] ?? null,
				'interrupt_source'      => is_callable( $input['interrupt_source'] ?? null ) ? $input['interrupt_source'] : null,
				'modes'                 => $modes,
				'agent_id'              => $agent_id,
				'agent_slug'            => $identity ? $identity->agent_slug : '',
				'calling_user_id'        => $calling_user_id,
				'attachments'           => $input['attachments'] ?? array(),
				'client_context'        => $client_context,
				'tool_policy'           => is_array( $input['tool_policy'] ?? null ) ? $input['tool_policy'] : null,
				'allow_only'            => is_array( $input['allow_only'] ?? null ) ? $input['allow_only'] : null,
				'completion_assertions' => is_array( $input['completion_assertions'] ?? null ) ? $input['completion_assertions'] : null,
				'event_sink'            => $input['event_sink'] ?? null,
				'session_owner'         => is_array( $input['session_owner'] ?? null ) ? $input['session_owner'] : null,
				'transcript_owner'      => is_array( $input['transcript_owner'] ?? null ) ? $input['transcript_owner'] : null,
				'workspace'             => $workspace,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$output = $this->toCanonicalOutput( $result );
		return $this->withInputControlDiagnostics( $output, $input );
	}

	/**
	 * Resolve the authenticated user on whose behalf the chat executes.
	 *
	 * The runtime and transcript may be owned by an agent owner even when no
	 * human is acting. An execution principal therefore takes precedence over
	 * WordPress request state, including when it explicitly identifies a
	 * system/runtime context with acting_user_id 0.
	 *
	 * @param array $input Canonical chat input.
	 * @return int Acting WordPress user ID, or 0 when no human is acting.
	 */
	private function resolveCallingUserId( array $input ): int {
		$principal = $this->resolveCallerPrincipal( $input ) ?? PermissionHelper::get_execution_principal();
		if ( $principal instanceof \AgentsAPI\AI\WP_Agent_Execution_Principal ) {
			return max( 0, $principal->acting_user_id );
		}

		return max( 0, get_current_user_id() );
	}

	/**
	 * Resolve canonical agent input into Data Machine's canonical identity.
	 *
	 * @param string $agent Agent slug or numeric ID.
	 * @return AgentIdentity|WP_Error|null
	 */
	private function resolveAgentIdentity( string $agent ): AgentIdentity|WP_Error|null {
		$agent = trim( $agent );
		if ( '' === $agent ) {
			return null;
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_identity( $agent );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'agent_not_found', __( 'Agent not found.', 'data-machine' ), array( 'status' => 404 ) );
		}
	}

	/**
	 * Resolve the WordPress user context used by Data Machine's chat runtime.
	 *
	 * Anonymous audience chats execute under the selected agent owner after the
	 * Agents API access check succeeds, keeping model credentials and tool policy
	 * scoped to the site-owned brain agent instead of an anonymous WP user.
	 *
	 * @param AgentIdentity|null $identity Resolved agent identity, or null for unscoped legacy chats.
	 * @param string $agent    Requested Agents API agent slug/id.
	 * @param array  $input    Canonical input plus Data Machine facade fields.
	 * @return int Runtime WordPress user ID, or 0 when no safe context exists.
	 */
	private function resolveRuntimeUserId( ?AgentIdentity $identity, string $agent, array $input ): int {
		$user_id = (int) ( $input['user_id'] ?? 0 );
		if ( $user_id > 0 && PermissionHelper::can( 'chat' ) ) {
			return $user_id;
		}

		$user_id = PermissionHelper::acting_user_id();
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id > 0 ) {
			return $user_id;
		}

		if ( ! $identity ) {
			return 0;
		}

		if ( ! \WP_Agent_Access::can_current_principal_access_agent( $identity->agent_slug, \WP_Agent_Access_Grant::ROLE_VIEWER ) ) {
			return 0;
		}

		return $identity->owner_id;
	}

	/**
	 * Resolve the canonical transcript workspace from trusted runtime input.
	 *
	 * Opaque client context is intentionally ignored. Transport adapters that
	 * select a workspace must place the canonical value at the top-level input.
	 *
	 * @param array $input Canonical agents/chat input.
	 * @return WP_Agent_Workspace_Scope|WP_Error
	 */
	private function resolveTranscriptWorkspace( array $input ): WP_Agent_Workspace_Scope|WP_Error {
		if ( ! array_key_exists( 'workspace', $input ) ) {
			return WordPressWorkspaceScope::current();
		}

		if ( $input['workspace'] instanceof WP_Agent_Workspace_Scope ) {
			return $input['workspace'];
		}

		if ( ! is_array( $input['workspace'] ) ) {
			return new WP_Error( 'invalid_transcript_workspace', __( 'Transcript workspace must be a canonical workspace object.', 'data-machine' ), array( 'status' => 400 ) );
		}

		try {
			return WP_Agent_Workspace_Scope::from_array( $input['workspace'] );
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error( 'invalid_transcript_workspace', $exception->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Resolve the Data Machine execution modes from canonical Agents API input.
	 *
	 * @param array $input Canonical agents/chat input.
	 * @param array $client_context Transport-level client context.
	 * @return array<int,string> Sanitized mode slugs.
	 */
	private function resolveModes( array $input, array $client_context ): array {
		$modes = $input['modes'] ?? $client_context['agent_modes'] ?? null;
		if ( ! is_array( $modes ) || empty( $modes ) ) {
			$modes = array( $input['mode'] ?? $client_context['mode'] ?? 'chat' );
		}

		return ToolPolicyResolver::normalizeModes( $modes );
	}

	/**
	 * Convert Data Machine chat output to the canonical agents/chat output shape.
	 *
	 * @param array $result Data Machine chat response.
	 * @return array
	 */
	private function toCanonicalOutput( array $result ): array {
		$metadata                = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
		$datamachine_metadata    = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
		$tool_execution_summary  = $this->toToolExecutionSummary( $result );
		$datamachine_metadata    = array_filter(
			array_merge(
				$datamachine_metadata,
				array(
					'tool_calls'             => $result['tool_calls'] ?? null,
					'tool_execution_summary' => $tool_execution_summary,
					'conversation'           => $result['conversation'] ?? null,
					'max_turns'              => $result['max_turns'] ?? null,
					'turn_number'            => $result['turn_number'] ?? null,
					'interrupted'            => $result['interrupted'] ?? null,
				)
			),
			static fn( $value ): bool => null !== $value
		);
		$metadata['datamachine'] = $datamachine_metadata;
		$completed               = (bool) ( $datamachine_metadata['completed'] ?? $result['completed'] ?? true );

		return array(
			'session_id' => (string) ( $result['session_id'] ?? '' ),
			'reply'      => (string) ( $result['response'] ?? '' ),
			'messages'   => $this->toCanonicalMessages( $result['conversation'] ?? array() ),
			'completed'  => $completed,
			'metadata'   => $metadata,
		);
	}

	/**
	 * Add bounded diagnostics showing which caller-owned controls reached the runtime adapter.
	 *
	 * @param array $output Canonical output.
	 * @param array $input  Canonical input received by the handler.
	 * @return array Output with namespaced Data Machine diagnostics.
	 */
	private function withInputControlDiagnostics( array $output, array $input ): array {
		$metadata             = is_array( $output['metadata'] ?? null ) ? $output['metadata'] : array();
		$datamachine_metadata = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
		$tool_policy          = is_array( $input['tool_policy'] ?? null ) ? $input['tool_policy'] : array();
		$assertions           = is_array( $input['completion_assertions'] ?? null ) ? $input['completion_assertions'] : array();

		$datamachine_metadata['input_controls'] = array_filter(
			array(
				'has_tool_policy'                => is_array( $input['tool_policy'] ?? null ),
				'tool_policy_mode'               => is_scalar( $tool_policy['mode'] ?? null ) ? (string) $tool_policy['mode'] : null,
				'tool_policy_tools'              => is_array( $tool_policy['tools'] ?? null ) ? array_values( array_map( 'strval', $tool_policy['tools'] ) ) : null,
				'allow_only'                     => is_array( $input['allow_only'] ?? null ) ? array_values( array_map( 'strval', $input['allow_only'] ) ) : null,
				'completion_required_tool_names' => is_array( $assertions['required_tool_names'] ?? null ) ? array_values( array_map( 'strval', $assertions['required_tool_names'] ) ) : null,
			),
			static fn( $value ): bool => null !== $value
		);

		$metadata['datamachine'] = $datamachine_metadata;
		$output['metadata']      = $metadata;

		return $output;
	}

	/**
	 * Build the bounded tool execution diagnostics exposed through canonical metadata.
	 *
	 * @param array $result Data Machine chat response.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function toToolExecutionSummary( array $result ): ?array {
		if ( is_array( $result['tool_execution_summary'] ?? null ) ) {
			return $result['tool_execution_summary'];
		}

		$tool_execution_results = is_array( $result['tool_execution_results'] ?? null ) ? $result['tool_execution_results'] : array();
		if ( empty( $tool_execution_results ) ) {
			return null;
		}

		$summary_function = '\\DataMachine\\Engine\\AI\\datamachine_summarize_tool_execution_results';
		if ( function_exists( $summary_function ) ) {
			return $summary_function( $tool_execution_results, false );
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $result ): ?array {
						if ( ! is_array( $result ) ) {
							return null;
						}
						$tool_name   = isset( $result['tool_name'] ) ? sanitize_key( (string) $result['tool_name'] ) : '';
						$tool_result = is_array( $result['result'] ?? null ) ? $result['result'] : array();
						if ( '' === $tool_name ) {
							return null;
						}
						return array_filter(
							array(
								'tool_name'  => $tool_name,
								'success'    => true === ( $tool_result['success'] ?? false ),
								'turn_count' => isset( $result['turn_count'] ) ? (int) $result['turn_count'] : null,
								'summary'    => isset( $tool_result['message'] ) ? sanitize_text_field( (string) $tool_result['message'] ) : null,
							),
							static fn( $value ): bool => null !== $value && '' !== $value
						);
					},
					$tool_execution_results
				)
			)
		);
	}

	/**
	 * Project Data Machine envelopes to the canonical message list.
	 *
	 * Text messages project to the plain `{role, content}` contract. Interactive
	 * tool parts (`tool_call`/`tool_result` — e.g. a `present_question` choice
	 * card or a confirmation/DiffCard button) are carried through with their
	 * `type`, `payload`, and `metadata` preserved, matching the shape the
	 * persisted transcript and the frontend renderer already consume on session
	 * reload. This makes the live-turn projection equivalent to a reload so the
	 * card renders on the turn it is produced instead of only after a reload.
	 *
	 * @param array $conversation Conversation messages.
	 * @return array<int,array<string,mixed>>
	 */
	private function toCanonicalMessages( array $conversation ): array {
		$messages = array();

		foreach ( $conversation as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}

			$type = (string) ( $message['type'] ?? '' );

			if ( in_array( $type, array( 'tool_call', 'tool_result' ), true ) ) {
				$tool_message = $this->toCanonicalToolMessage( $message, $type );
				if ( null !== $tool_message ) {
					$messages[] = $tool_message;
				}
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

	/**
	 * Project a tool_call/tool_result envelope into the canonical message shape.
	 *
	 * Preserves the interactive payload (tool_name, parameters, model-facing
	 * tool_data, success) so the frontend can render the clickable card on the
	 * live turn. Mirrors the persisted-transcript / session-reload projection so
	 * both paths feed the renderer an identical envelope.
	 *
	 * @param array  $message Tool envelope from the conversation transcript.
	 * @param string $type    Envelope type: `tool_call` or `tool_result`.
	 * @return array<string,mixed>|null Canonical tool message, or null when unrenderable.
	 */
	private function toCanonicalToolMessage( array $message, string $type ): ?array {
		$payload  = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
		$metadata = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();

		$tool_name = (string) ( $payload['tool_name'] ?? $metadata['tool_name'] ?? '' );
		if ( '' === $tool_name ) {
			return null;
		}

		$content = $message['content'];
		if ( ! is_string( $content ) && ! is_array( $content ) ) {
			return null;
		}

		$canonical = array(
			'role'    => (string) $message['role'],
			'content' => $content,
			'type'    => $type,
			'payload' => $payload,
		);

		if ( ! empty( $metadata ) ) {
			$canonical['metadata'] = $metadata;
		}

		return $canonical;
	}
}
