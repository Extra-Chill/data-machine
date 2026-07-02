<?php
/**
 * Canonical chat ability registration.
 *
 * Registers `agents/chat` as the stable, runtime-agnostic entry point for any
 * caller (channel, bridge, REST surface, block) that wants to send one user
 * message to a registered agent and receive an assistant reply. The ability
 * itself is a dispatcher: it validates the canonical input/output shape from
 * https://github.com/Automattic/agents-api/issues/100 and routes execution
 * to whichever runtime registered itself via the `wp_agent_chat_handler` filter.
 * Agents API ships a provider-agnostic default runtime as a low-priority
 * fallback (see register-default-agents-chat-handler.php), so a vanilla install
 * runs an agent loop natively. Consumers register their own runtime in their
 * bootstrap at the default priority to take over.
 *
 * Consumers register a runtime by hooking the filter:
 *
 *     add_filter(
 *         'wp_agent_chat_handler',
 *         function ( $handler, array $input ) {
 *             if ( null !== $handler ) {
 *                 return $handler; // earlier hook already won
 *             }
 *             return [ My_Plugin\Chat_Adapter::class, 'execute' ];
 *         },
 *         10,
 *         2
 *     );
 *
 * The handler receives the canonical input map and must return either an
 * array matching the canonical output shape or a `WP_Error`.
 *
 * Observability hooks fired by the dispatcher:
 *   `agents_chat_dispatch_failed` — fires once per failed dispatch with
 *     `( string $reason, array $input )`. Reasons: `no_handler`,
 *     `invalid_result`, or any `WP_Error::get_error_code()` from a handler.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;
use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\AI\WP_Agent_Run_Outcome;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( WP_Agent_Chat_Run_Control::class ) ) {
	require_once dirname( __DIR__ ) . '/Runtime/class-wp-agent-chat-run-control.php';
}

if ( ! class_exists( WP_Agent_Run_Outcome::class ) ) {
	require_once dirname( __DIR__ ) . '/Runtime/class-wp-agent-run-control.php';
	require_once dirname( __DIR__ ) . '/Runtime/class-wp-agent-run-outcome.php';
}

/**
 * The slug under which this ability is registered. Stable. Consumers and
	 * channels should target this string rather than a runtime-specific slug.
 *
 * @since 0.103.0
 */
const AGENTS_CHAT_ABILITY = 'agents/chat';

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( wp_has_ability_category( 'agents-api' ) ) {
			return;
		}

		wp_register_ability_category(
			'agents-api',
			array(
				'label'       => 'Agents API',
				'description' => 'Cross-cutting abilities provided by the Agents API substrate (channel dispatch, canonical chat contract, and workflow dispatch).',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( wp_has_ability( AGENTS_CHAT_ABILITY ) ) {
			return;
		}

		wp_register_ability(
			AGENTS_CHAT_ABILITY,
			array(
				'label'               => 'Agents Chat',
				'description'         => 'Canonical entry point for sending one user message to a registered agent and receiving an assistant reply. Dispatches to whichever runtime is registered via the wp_agent_chat_handler filter.',
				'category'            => 'agents-api',
				'input_schema'        => agents_chat_input_schema(),
				'output_schema'       => agents_chat_output_schema(),
				'execute_callback'    => __NAMESPACE__ . '\\agents_chat_dispatch',
				'permission_callback' => __NAMESPACE__ . '\\agents_chat_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}
);

/**
 * Dispatch a chat request to the registered runtime.
 *
 * @since  0.103.0
 *
 * @param  array<mixed> $input Canonical chat-ability input.
 * @return array<string,mixed>|\WP_Error Canonical output, or WP_Error if no runtime is registered.
 */
function agents_chat_dispatch( array $input ) {
	$principal = agents_chat_principal_from_input( $input );
	if ( is_wp_error( $principal ) ) {
		do_action( 'agents_chat_dispatch_failed', $principal->get_error_code(), $input );
		return $principal;
	}

	if ( $principal instanceof WP_Agent_Execution_Principal ) {
		$input['principal'] = $principal->to_array();
	}

	$run_id = agents_chat_optional_string( $input['run_id'] ?? null );
	if ( null === $run_id ) {
		$input['run_id'] = WP_Agent_Chat_Run_Control::generate_run_id();
		$run_id          = $input['run_id'];
	}

	/**
	 * Filter the chat runtime handler.
	 *
	 * Consumers register a callable that accepts the canonical input array
	 * and returns either the canonical output or WP_Error. The first hook
	 * to return a callable wins; later hooks should respect that decision
	 * unless they intentionally take over (e.g. an agent-specific override).
	 *
	 * @param callable|null $handler Currently registered handler. Null when
	 *                               no runtime has registered.
	 * @param array<mixed>         $input   The canonical input being dispatched. Use
	 *                               $input['agent'] to route per agent slug.
	 */
	$handler = apply_filters( 'wp_agent_chat_handler', null, $input );

	if ( ! is_callable( $handler ) ) {
		/**
		 * Fires when agents/chat dispatched but no handler was registered.
		 * Use for sysadmin-side observability — alerting, Site Health, logs.
		 *
		 * @since 0.103.0
		 *
		 * @param string $reason Dispatch failure reason. Always `'no_handler'`.
		 * @param array<mixed>  $input  The canonical input that was rejected.
		 */
		do_action( 'agents_chat_dispatch_failed', 'no_handler', $input );

		return new \WP_Error(
			'agents_chat_no_handler',
			'No agents/chat handler is registered. Install a consumer plugin that registers a runtime, or add a callable to the wp_agent_chat_handler filter.'
		);
	}

	$session_id = agents_chat_optional_string( $input['session_id'] ?? null );
	$agent      = agents_chat_optional_string( $input['agent'] ?? null ) ?? '';
	if ( null !== $session_id ) {
		WP_Agent_Chat_Run_Control::start_run( $run_id, $session_id, array( 'agent' => $agent ) );
	}

	$result = call_user_func( $handler, $input );

	if ( is_wp_error( $result ) ) {
		if ( null !== $session_id ) {
			WP_Agent_Chat_Run_Control::finish_run( $run_id, WP_Agent_Chat_Run_Control::STATUS_FAILED );
		}

		/** This action is documented above. */
		do_action( 'agents_chat_dispatch_failed', $result->get_error_code(), $input );

		return $result;
	}

	if ( ! is_array( $result ) ) {
		if ( null !== $session_id ) {
			WP_Agent_Chat_Run_Control::finish_run( $run_id, WP_Agent_Chat_Run_Control::STATUS_FAILED );
		}

		/** This action is documented above. */
		do_action( 'agents_chat_dispatch_failed', 'invalid_result', $input );

		return new \WP_Error(
			'agents_chat_invalid_result',
			'agents/chat handler returned an unexpected result type. Handlers must return an array matching the canonical output shape or a WP_Error.'
		);
	}

	$result = agents_chat_string_keyed_array( $result );
	if ( null === agents_chat_optional_string( $result['run_id'] ?? null ) ) {
		$result['run_id'] = $input['run_id'];
	}

	$result_run_id       = agents_chat_optional_string( $result['run_id'] ?? null ) ?? $run_id;
	$resolved_session_id = agents_chat_optional_string( $result['session_id'] ?? null ) ?? $session_id;
	if ( null !== $resolved_session_id ) {
		if ( null === $session_id ) {
			WP_Agent_Chat_Run_Control::start_run( $result_run_id, $resolved_session_id, array( 'agent' => $agent ) );
		}

		$status = WP_Agent_Run_Outcome::run_control_status( $result );
		WP_Agent_Chat_Run_Control::finish_run( $result_run_id, $status );
	}

	return $result;
}

function agents_chat_optional_string( mixed $value ): ?string {
	if ( ! is_scalar( $value ) && ! $value instanceof \Stringable ) {
		return null;
	}

	$value = trim( (string) $value );
	return '' === $value ? null : $value;
}

/**
 * @param array<mixed> $data
 * @return array<string,mixed>
 */
function agents_chat_string_keyed_array( array $data ): array {
	$result = array();
	foreach ( $data as $key => $value ) {
		if ( is_string( $key ) ) {
			$result[ $key ] = $value;
		}
	}
	return $result;
}

/**
 * Permission gate for `agents/chat`. Defaults to `manage_options`; consumers
 * with their own auth model (HMAC-signed webhook, OAuth bearer, etc.) can
 * widen the gate per-request via the `agents_chat_permission` filter.
 *
 * @since 0.103.0
 *
 * @param array<mixed> $input Canonical input.
 * @return bool
 */
function agents_chat_permission( array $input ): bool {
	$allowed   = current_user_can( 'manage_options' );
	$principal = agents_chat_principal_from_input( $input );
	if ( is_wp_error( $principal ) ) {
		return false;
	}

	if ( $principal instanceof WP_Agent_Execution_Principal ) {
		/**
		 * Filter permission for host-attested runtime principals.
		 *
		 * Hosts can authorize disposable runtimes here after validating their own
		 * runtime binding/session claims. Agents API normalizes the principal but
		 * does not trust arbitrary runtime principal input by default.
		 *
		 * @param bool                         $allowed   Current permission decision.
		 * @param WP_Agent_Execution_Principal $principal Normalized principal.
		 * @param array<mixed>                 $input     Canonical chat input.
		 */
		$allowed = (bool) apply_filters( 'agents_chat_runtime_principal_permission', $allowed, $principal, $input );
	}

	/**
	 * Filter the permission decision for the canonical chat ability.
	 *
	 * @param bool  $allowed Default: current_user_can( 'manage_options' ).
	 * @param array<mixed> $input   The canonical input being authorized.
	 */
	return (bool) apply_filters(
		'agents_chat_permission',
		$allowed,
		$input
	);
}

/**
 * Normalize optional execution principal input for agents/chat.
 *
 * @param array<mixed> $input Canonical chat input.
 * @return WP_Agent_Execution_Principal|\WP_Error|null
 */
function agents_chat_principal_from_input( array $input ) {
	if ( ! array_key_exists( 'principal', $input ) || null === $input['principal'] ) {
		return null;
	}

	if ( $input['principal'] instanceof WP_Agent_Execution_Principal ) {
		return $input['principal'];
	}

	if ( ! is_array( $input['principal'] ) ) {
		return new \WP_Error( 'agents_chat_invalid_principal', 'agents/chat principal must be an object when provided.' );
	}

	try {
		return WP_Agent_Execution_Principal::from_array( agents_chat_string_keyed_array( $input['principal'] ) );
	} catch ( \Throwable $error ) {
		return new \WP_Error(
			'agents_chat_invalid_principal',
			'Invalid agents/chat execution principal.',
			array( 'reason' => $error->getMessage() )
		);
	}
}

/**
 * Canonical input JSON schema (per agents-api#100).
 *
 * @since  0.103.0
 *
 * @return array<mixed>
 * @return array<string, mixed>
 */
function agents_chat_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'agent', 'message' ),
		'properties' => array(
			'agent'                 => array(
				'type'        => 'string',
				'description' => 'Slug or ID of the registered agent that should handle this turn.',
			),
			'message'               => array(
				'type'        => 'string',
				'description' => 'User-side text for the agent to respond to.',
			),
			'session_id'            => array(
				'type'        => array( 'string', 'null' ),
				'description' => 'Existing session ID to continue, or null to start a new session.',
			),
			'run_id'                => array(
				'type'        => array( 'string', 'null' ),
				'description' => 'Optional client-supplied idempotency/run key. If omitted, the dispatcher provides an opaque run ID to the runtime and response.',
			),
			'principal'             => agents_chat_principal_schema(),
			'session_owner'         => agents_chat_session_owner_schema(),
			'attachments'           => array(
				'type'        => 'array',
				'description' => 'Channel-side attachments (images, voice notes, files, link previews). Shape is runtime-defined; runtimes ignore unknown attachment types.',
				'default'     => array(),
				'items'       => array( 'type' => 'object' ),
			),
			'tool_policy'           => array(
				'type'        => array( 'object', 'null' ),
				'description' => 'Optional caller-owned tool policy for this turn. Runtimes may use this to narrow tool visibility for peer-agent or delegated-runtime invocations.',
				'properties'  => array(
					'mode'  => array(
						'type' => 'string',
						'enum' => array( 'allow', 'deny' ),
					),
					'tools' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'allow_only'            => array(
				'type'        => 'array',
				'description' => 'Optional per-turn allow-list of tool names. Runtimes may intersect this with agent policy and available tool declarations.',
				'default'     => array(),
				'items'       => array( 'type' => 'string' ),
			),
			'completion_assertions' => array(
				'type'        => array( 'object', 'null' ),
				'description' => 'Optional runtime-defined completion assertions for this turn, such as required tool names before natural completion is accepted.',
				'properties'  => array(
					'required_tool_names' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'client_context'        => array(
				'type'        => 'object',
				'description' => 'Optional transport-level context describing where this turn originated. Hosts may include opaque, product-owned metadata; Agents API preserves it but does not define product semantics.',
				'properties'  => array(
					'source'                    => array(
						'type'        => 'string',
						'enum'        => array( 'channel', 'bridge', 'rest', 'block', 'peer-agent', 'jsonrpc' ),
						'description' => 'How the request reached this dispatcher.',
					),
					'client_name'               => array(
						'type'        => 'string',
						'description' => 'Specific client identifier within the source (e.g. "cli-relay" or "messaging-bot").',
					),
					'connector_id'              => array(
						'type'        => 'string',
						'description' => 'Stable connector or channel instance id used for settings, attribution, and external conversation session mapping.',
					),
					'external_provider'         => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'External network identifier defined by the consuming product; null if not applicable.',
					),
					'external_conversation_id'  => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Opaque external conversation id (e.g. a conversation id, channel id, or thread root). Null if the source has no per-conversation isolation.',
					),
					'external_message_id'       => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Stable transport-side message id, used for reply threading / dedup / audit.',
					),
					'sender_id'                 => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Opaque external sender id. In group rooms this identifies the human sender inside the conversation.',
					),
					'room_kind'                 => array(
						'type'        => array( 'string', 'null' ),
						'enum'        => array( 'dm', 'group', 'channel', null ),
						'description' => 'Conversation kind: direct message, multi-participant group, broadcast channel. Null when the source has no notion of room kind.',
					),
					'caller_agent'              => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Agent slug that initiated this turn when source is peer-agent. Null when the source is not another agent.',
					),
					'caller_session_id'         => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Originating agent session id when source is peer-agent. Null when unavailable or not applicable.',
					),
					'peer_agent_call'           => array(
						'type'        => 'boolean',
						'description' => 'Whether this turn is an explicit agent-to-agent delegation call.',
					),
					'runtime_tools'             => array(
						'type'                 => 'object',
						'description'          => 'Explicit runtime-local tool declarations supplied by a trusted caller for this turn.',
						'additionalProperties' => array( 'type' => 'object' ),
					),
					'runtime_tool_declarations' => array(
						'type'                 => 'object',
						'description'          => 'Alias for explicit runtime-local tool declarations supplied by a trusted caller for this turn.',
						'additionalProperties' => array( 'type' => 'object' ),
					),
					'tool_declarations'         => array(
						'type'                 => 'object',
						'description'          => 'Transport-level tool declarations supplied by a trusted caller for this turn.',
						'additionalProperties' => array( 'type' => 'object' ),
					),
					'runtime_tool_callback'     => array(
						'type'        => 'string',
						'description' => 'Runtime-local callback identifier for executing runtime tool calls in trusted in-process callers.',
					),
					'runtime_tool_timeout'      => array(
						'type'        => 'integer',
						'description' => 'Runtime-local tool timeout in seconds.',
					),
				),
			),
		),
	);
}

/**
 * Canonical execution principal schema for agents/chat.
 *
 * @return array<string, mixed>
 */
function agents_chat_principal_schema(): array {
	return array(
		'type'        => array( 'object', 'null' ),
		'description' => 'Optional execution principal resolved by a trusted host/runtime. Disposable runtimes should use auth_source=runtime and request_context=runtime with opaque owner isolation.',
		'properties'  => array(
			'acting_user_id'     => array( 'type' => 'integer' ),
			'effective_agent_id' => array( 'type' => 'string' ),
			'auth_source'        => array( 'type' => 'string' ),
			'request_context'    => array( 'type' => 'string' ),
			'token_id'           => array( 'type' => array( 'integer', 'null' ) ),
			'request_metadata'   => array( 'type' => 'object' ),
			'workspace_id'       => array( 'type' => array( 'string', 'null' ) ),
			'client_id'          => array( 'type' => array( 'string', 'null' ) ),
			'audience_id'        => array( 'type' => array( 'string', 'null' ) ),
			'audience_claims'    => array( 'type' => 'object' ),
			'owner_type'         => array( 'type' => array( 'string', 'null' ) ),
			'owner_key'          => array( 'type' => array( 'string', 'null' ) ),
			'binding'            => array( 'type' => array( 'object', 'null' ) ),
		),
	);
}

/**
 * Canonical conversation-session owner schema.
 *
 * Session owners isolate persisted transcripts. They are intentionally separate
 * from access principals: `audience:public` can authorize a request, but a
 * stored transcript needs an owner such as `user:<id>`, `browser:<opaque id>`,
 * or a channel-specific conversation key.
 *
 * @since 0.103.0
 *
 * @return array<mixed>
 * @return array<string, mixed>
 */
function agents_chat_session_owner_schema(): array {
	return array(
		'type'        => array( 'object', 'null' ),
		'description' => 'Opaque, isolating owner for persisted conversation sessions. Use for anonymous browser or external-channel chats when no logged-in user owns the transcript. Do not use a shared public audience as a session owner.',
		'properties'  => array(
			'type'  => array(
				'type'        => 'string',
				'description' => 'Owner namespace such as user, browser, channel, token, or system.',
			),
			'key'   => array(
				'type'        => 'string',
				'description' => 'Opaque owner key within the namespace. Runtimes should hash before storage.',
			),
			'label' => array(
				'type'        => 'string',
				'description' => 'Optional human-readable owner label for diagnostics.',
			),
		),
	);
}

/**
 * Canonical output JSON schema (per agents-api#100).
 *
 * @since  0.103.0
 *
 * @return array<mixed>
 * @return array<string, mixed>
 */
function agents_chat_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session_id', 'reply' ),
		'properties' => array(
			'session_id' => array(
				'type'        => 'string',
				'description' => 'Session ID to thread subsequent turns under.',
			),
			'reply'      => array(
				'type'        => 'string',
				'description' => 'Primary assistant text. Must be set even when the runtime supplies multi-message output via `messages`.',
			),
			'run_id'     => array(
				'type'        => 'string',
				'description' => 'Opaque ID for this accepted chat turn. Use with agents/get-chat-run, agents/cancel-chat-run, and agents/queue-chat-message for generic run control.',
			),
			'messages'   => array(
				'type'        => 'array',
				'description' => 'Optional multi-message expansion (e.g. assistant emitted multiple turns or split a long answer). When present, each entry is `{ role, content }`. The single-string `reply` is still required for clients that don\'t parse `messages`.',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'role'    => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
					),
				),
			),
			'completed'  => array(
				'type'        => 'boolean',
				'description' => 'Whether the agent considers this turn complete (true) or expects further work (false, e.g. tool approvals pending).',
			),
			'metadata'   => array(
				'type'        => 'object',
				'description' => 'Runtime-specific metadata (token usage, model, latency, tool calls). Opaque to the dispatcher.',
			),
		),
	);
}

/**
 * Convenience helper for consumers: register a callable as the chat handler.
 *
 * Equivalent to `add_filter( 'wp_agent_chat_handler', ... )` but reads more
 * intentionally at the call site.
 *
 * @since 0.103.0
 *
 * @param callable $handler  Receives the canonical input array, returns the
 *                           canonical output array or WP_Error.
 * @param int      $priority Filter priority. Default 10.
 */
function register_chat_handler( callable $handler, int $priority = 10 ): void {
	add_filter(
		'wp_agent_chat_handler',
		static function ( $existing, array $input ) use ( $handler ) {
			unset( $input );
			if ( null !== $existing ) {
				return $existing;
			}
			return $handler;
		},
		$priority,
		2
	);
}
