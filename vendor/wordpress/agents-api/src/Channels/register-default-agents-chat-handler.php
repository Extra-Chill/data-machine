<?php
/**
 * Default agents/chat runtime handler.
 *
 * Agents API ships the canonical `agents/chat` dispatcher
 * (see register-agents-chat-ability.php) but, historically, registered no
 * runtime behind it: an install with no consumer plugin returned
 * "No agents/chat handler is registered." This file makes Agents API
 * self-sufficient by registering a default, provider-agnostic runtime that
 * runs a real agent loop natively.
 *
 * The handler is the generic driver only — it owns nothing a consumer would
 * want to override:
 *
 *   1. resolve the agent from Agents API's own runtime-bundle registry
 *      ({@see WP_Agents_Registry});
 *   2. resolve provider/model/system-prompt/tools from the chat input and the
 *      registered agent's default config;
 *   3. build provider-agnostic dispatch through the generic AI-client
 *      abstraction via {@see WP_Agent_Conversation_Loop::run_conversation()},
 *      which constructs the {@see WP_Agent_Default_Provider_Turn_Adapter} (a
 *      wp-ai-client builder keyed purely by the requested provider + model — no
 *      provider hardcoding);
 *   4. mediate tool calls through Agents API's own
 *      {@see WP_Agent_Ability_Tool_Executor} and the per-target executor
 *      registry from #377;
 *   5. return the canonical `agents/chat` output shape.
 *
 * It registers itself as a FALLBACK at a high filter priority, so any explicit
 * consumer runtime registered at the default priority still wins. A vanilla
 * Agents API install gets a working `agents/chat` for free; a consumer-backed
 * install is unchanged.
 *
 * @package AgentsAPI
 * @since   0.106.0
 */

namespace AgentsAPI\AI\Channels;

use AgentsAPI\AI\WP_Agent_Conversation_Loop;
use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\WP_Agent_Runtime_Profile;
use AgentsAPI\AI\WP_Agent_Tool_Pair_Validator;
use AgentsAPI\AI\Tools\WP_Agent_Ability_Tool_Executor;
use AgentsAPI\AI\Tools\WP_Agent_Default_Chat_Tool_Executor;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor_Registry;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Sessions;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Database\Chat\WP_Agent_Principal_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-agnostic default runtime for the canonical `agents/chat` ability.
 */
class WP_Agent_Default_Chat_Handler {

	/**
	 * Fallback filter priority for the default handler.
	 *
	 * `wp_agent_chat_handler` resolves to the first non-null callable returned as
	 * the filter chain runs in ascending priority order. Registering this default
	 * at a deliberately high priority number means any consumer runtime added at
	 * the default priority (10) returns its callable first and wins; the default
	 * only fills the seam when nothing else has.
	 */
	public const FALLBACK_PRIORITY = 1000;

	/**
	 * Default maximum agent-loop turns when neither the request nor the agent
	 * config specifies one. Bounds tool-mediated runs so the default driver
	 * cannot spin unbounded.
	 */
	public const DEFAULT_MAX_TURNS = 12;

	/**
	 * Register the default handler as a fallback chat runtime.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_chat_handler( array( self::class, 'execute' ), self::FALLBACK_PRIORITY );
		add_filter(
			'wp_agent_chat_stream_handler',
			static function ( $existing, array $input ) {
				unset( $input );
				return null === $existing ? array( self::class, 'execute_stream' ) : $existing;
			},
			self::FALLBACK_PRIORITY,
			2
		);
	}

	/**
	 * Execute one canonical streaming chat turn through the native runtime.
	 *
	 * Provider deltas are emitted only when canonical input explicitly requests
	 * token streaming. The terminal output always matches execute().
	 *
	 * @param array<string,mixed> $input Canonical agents/chat input.
	 * @param callable            $emit  Canonical provider delta sink.
	 * @return array<string,mixed>|\WP_Error Canonical agents/chat output, or WP_Error.
	 */
	public static function execute_stream( array $input, callable $emit ) {
		return self::execute_native( $input, true === ( $input['token_streaming'] ?? false ) ? $emit : null );
	}

	/**
	 * Execute one canonical chat turn natively, without any external runtime.
	 *
	 * @param array<string,mixed> $input Canonical agents/chat input.
	 * @return array<string,mixed>|\WP_Error Canonical agents/chat output, or WP_Error.
	 */
	public static function execute( array $input ) {
		return self::execute_native( $input );
	}

	/**
	 * Execute one canonical chat turn with an optional provider delta sink.
	 *
	 * @param array<string,mixed> $input      Canonical agents/chat input.
	 * @param callable|null       $delta_sink Request-scoped provider delta sink.
	 * @return array<string,mixed>|\WP_Error Canonical agents/chat output, or WP_Error.
	 */
	private static function execute_native( array $input, ?callable $delta_sink = null ) {
		$input_messages = self::input_messages( $input );
		if ( is_wp_error( $input_messages ) ) {
			return $input_messages;
		}

		$agent_slug = is_string( $input['agent'] ?? null ) ? trim( $input['agent'] ) : '';
		$agent      = self::resolve_agent( $agent_slug );
		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		$config = self::resolve_runtime_config( $agent, $input );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$runtime_profile = self::resolve_runtime_profile( $agent, $input, $config );

		$provider = self::first_non_empty(
			is_string( $input['provider'] ?? null ) ? $input['provider'] : '',
			$runtime_profile instanceof WP_Agent_Runtime_Profile ? $runtime_profile->provider_id() : '',
			is_string( $config['provider'] ?? null ) ? $config['provider'] : '',
			is_string( $config['provider_id'] ?? null ) ? $config['provider_id'] : ''
		);
		if ( '' === $provider ) {
			return new \WP_Error(
				'agents_chat_provider_required',
				'A provider id is required. Supply input.provider or set a provider in the agent default config.',
				array( 'status' => 400 )
			);
		}

		$model = self::first_non_empty(
			is_string( $input['model'] ?? null ) ? $input['model'] : '',
			$runtime_profile instanceof WP_Agent_Runtime_Profile ? $runtime_profile->model_id() : '',
			is_string( $config['model'] ?? null ) ? $config['model'] : '',
			is_string( $config['model_id'] ?? null ) ? $config['model_id'] : ''
		);
		if ( '' === $model ) {
			return new \WP_Error(
				'agents_chat_model_required',
				'A model id is required. Supply input.model or set a model in the agent default config.',
				array( 'status' => 400 )
			);
		}

		$system_prompt   = self::resolve_system_prompt( $config );
		$max_turns       = self::resolve_max_turns( $input, $config );
		$tool_call_rules = self::resolve_tool_call_rules( $config );
		$structured_output = $input['structured_output'] ?? $config['structured_output'] ?? null;
		if ( null !== $structured_output ) {
			try {
				$structured_output = $structured_output instanceof \AgentsAPI\AI\WP_Agent_Structured_Output_Request ? $structured_output : \AgentsAPI\AI\WP_Agent_Structured_Output_Request::from_array( $structured_output );
			} catch ( \InvalidArgumentException $error ) {
				return new \WP_Error( 'agents_chat_invalid_structured_output', $error->getMessage(), array( 'status' => 400 ) );
			}
		}

		$store      = WP_Agent_Conversation_Sessions::get_store( $input );
		$session_id = is_string( $input['session_id'] ?? null ) ? trim( $input['session_id'] ) : '';
		$messages   = array();

		if ( $store instanceof WP_Agent_Conversation_Store ) {
			$loaded = ( '' !== $session_id ) ? $store->get_session( $session_id ) : null;
			if ( is_array( $loaded ) && is_array( $loaded['messages'] ?? null ) ) {
				$messages = $loaded['messages'];
			} else {
				$created = self::create_session( $store, $agent_slug, $input );
				if ( '' !== $created ) {
					$session_id = $created;
				}
			}
		}
		if ( ! $store instanceof WP_Agent_Conversation_Store && is_array( $input['history'] ?? null ) ) {
			$messages = self::stateless_history( $input['history'] );
		}

		if ( '' === $session_id ) {
			$session_id = self::generate_session_id();
		}

		$runtime_context = array(
			'agent'           => $agent,
			'agent_slug'      => $agent_slug,
			'session_id'      => $session_id,
			'run_id'          => is_string( $input['run_id'] ?? null ) ? trim( $input['run_id'] ) : '',
			'principal'       => $input['principal'] ?? null,
			'runtime_profile' => $runtime_profile instanceof WP_Agent_Runtime_Profile ? $runtime_profile->to_array() : null,
			'client_context'  => self::runtime_client_context( $input ),
		);
		$executor_registry = WP_Agent_Tool_Executor_Registry::fromFilters( $runtime_context );
		$tool_declarations = self::resolve_tool_declarations( $config, self::runtime_tool_declarations( $agent, $runtime_context ), $executor_registry );
		if ( is_wp_error( $tool_declarations ) ) {
			return $tool_declarations;
		}
		$trusted_runtime_tools = array_keys(
			array_filter(
				$tool_declarations,
				static fn( array $declaration ): bool => WP_Agent_Tool_Declaration::EXECUTOR_CLIENT === ( $declaration['executor'] ?? null )
			)
		);
		$tool_declarations = ( new \WP_Agent_Tool_Policy() )->resolve(
			$tool_declarations,
			array(
				'agent_config' => $config,
				'allow_only'   => is_array( $input['allow_only'] ?? null ) ? $input['allow_only'] : array(),
				'tool_policy'  => is_array( $input['tool_policy'] ?? null ) ? $input['tool_policy'] : array(),
				'principal'    => $input['principal'] ?? null,
				// These client tools were added by the server-only declaration filter,
				// not supplied by the caller, so they are trusted policy opt-ins.
				'runtime_tools' => $trusted_runtime_tools,
			)
		);
		if ( null !== $structured_output && ( ! empty( $tool_declarations ) || null !== $delta_sink ) ) {
			return new \WP_Error( 'agents_chat_structured_output_incompatible', 'Structured output requires a no-tools, non-streaming turn.', array( 'status' => 400 ) );
		}

		$messages   = array_merge( $messages, $input_messages );
		$messages   = WP_Agent_Message::normalize_many( $messages );

		$loop_options = array(
			'system_prompt' => $system_prompt,
			'max_turns'     => $max_turns,
			'workspace'     => self::workspace_from_input( $input ),
			'principal'     => $input['principal'] ?? null,
			'context'       => array(
				'session_id'             => $session_id,
				'agent_slug'             => $agent_slug,
				'run_id'                 => $runtime_context['run_id'],
				'_agents_run_claim_token' => $input['_agents_run_claim_token'] ?? '',
				'principal'              => $input['principal'] ?? null,
				'conversation_store'     => $store,
				'tool_executor_registry' => $executor_registry,
				'runtime_profile'        => $runtime_context['runtime_profile'],
			),
		);
		if ( null !== $structured_output ) {
			$loop_options['structured_output'] = $structured_output;
		}
		if ( ! empty( $tool_declarations ) ) {
			// Default executor: dispatch tool calls through registered abilities.
			// The loop also consults the #377 per-target executor registry
			// (`agents_api_tool_executors`) for declarations that select another
			// execution environment, so consumers can override per tool target.
			$loop_options['tool_executor'] = new WP_Agent_Default_Chat_Tool_Executor( new WP_Agent_Ability_Tool_Executor() );
		}
		$runtime_tool_store = \AgentsAPI\AI\agents_runtime_tool_request_store_optional( $runtime_context );
		if ( null !== $runtime_tool_store ) {
			$loop_options['runtime_tool_request_store'] = $runtime_tool_store;
		}
		if ( ! empty( $tool_call_rules ) ) {
			// Declarative deterministic tool-call gating. The loop enforces these
			// rules natively ({@see WP_Agent_Tool_Call_Gate}) — bounded discovery
			// before a required commit, and a completion block until the commit
			// tool runs — so the guarantee is the runtime's, not a prompt's.
			$loop_options['tool_call_rules'] = $tool_call_rules;
		}
		if ( null !== $delta_sink ) {
			$loop_options['on_provider_delta'] = $delta_sink;
		}

		$result = WP_Agent_Conversation_Loop::run_conversation(
			$messages,
			$tool_declarations,
			$provider,
			$model,
			$loop_options
		);

		if ( $store instanceof WP_Agent_Conversation_Store ) {
			$final_messages = is_array( $result['messages'] ?? null ) ? $result['messages'] : $messages;
			$store->update_session(
				$session_id,
				$final_messages,
				self::session_metadata( $result ),
				$provider,
				$model
			);
		}

		return self::to_canonical_output( $session_id, $result, $runtime_profile );
	}

	/**
	 * Resolve canonical inbound messages while preserving the text shorthand.
	 *
	 * @param array<string,mixed> $input Canonical chat input.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private static function input_messages( array $input ) {
		$messages = array();
		$message  = is_string( $input['message'] ?? null ) ? trim( $input['message'] ) : '';
		if ( '' !== $message ) {
			$messages[] = WP_Agent_Message::text( 'user', $message );
		}

		if ( is_array( $input['input_messages'] ?? null ) && array() !== $input['input_messages'] ) {
			try {
				$typed_messages = WP_Agent_Message::normalize_many( array_values( $input['input_messages'] ) );
			} catch ( \InvalidArgumentException $error ) {
				return new \WP_Error( 'agents_chat_invalid_input_messages', $error->getMessage(), array( 'status' => 400 ) );
			}
			foreach ( $typed_messages as $typed_message ) {
				if ( ! in_array( $typed_message['type'], array( WP_Agent_Message::TYPE_TOOL_CALL, WP_Agent_Message::TYPE_TOOL_RESULT ), true ) ) {
					return new \WP_Error( 'agents_chat_invalid_input_message_type', 'Canonical input_messages may contain only tool call/result continuations.', array( 'status' => 400 ) );
				}
			}
			if ( ! WP_Agent_Tool_Pair_Validator::is_paired( $typed_messages ) ) {
				return new \WP_Error( 'agents_chat_unpaired_input_messages', 'Canonical tool call/result input_messages must be paired.', array( 'status' => 400 ) );
			}
			$messages = array_merge( $messages, $typed_messages );
		}

		if ( array() === $messages ) {
			return new \WP_Error( 'agents_chat_empty_message', 'Message or input_messages must contain at least one message.', array( 'status' => 400 ) );
		}

		return $messages;
	}

	/**
	 * Resolve the effective registered-agent config for one chat request.
	 *
	 * Hosts can derive trusted runtime values from canonical input without
	 * mutating the registered agent or leaking one request's config into another.
	 *
	 * @param \WP_Agent|null      $agent Selected registered agent, or null.
	 * @param array<string,mixed> $input Canonical agents/chat input.
	 * @return array<string,mixed>|\WP_Error Effective request config, or error.
	 */
	private static function resolve_runtime_config( ?\WP_Agent $agent, array $input ) {
		$config = $agent instanceof \WP_Agent ? $agent->get_default_config() : array();
		if ( ! $agent instanceof \WP_Agent ) {
			return $config;
		}

		/**
		 * Filters a registered agent's effective config for one chat request.
		 *
		 * @param array<string,mixed> $config Registered agent default config.
		 * @param \WP_Agent           $agent  Selected registered agent.
		 * @param array<string,mixed> $input  Canonical agents/chat input.
		 */
		$resolved = self::apply_runtime_config_filters( $config, $agent, $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( ! is_array( $resolved ) ) {
			return new \WP_Error(
				'agents_chat_invalid_runtime_agent_config',
				'The runtime agent config must be an array or WP_Error.',
				array( 'status' => 500 )
			);
		}

		return \AgentsAPI\AI\agents_api_string_keyed_array( $resolved );
	}

	/**
	 * Invoke the host runtime-config boundary without assuming filter output type.
	 *
	 * @param array<string,mixed> $config Registered agent default config.
	 * @param \WP_Agent           $agent  Selected registered agent.
	 * @param array<string,mixed> $input  Canonical agents/chat input.
	 * @return mixed Filtered runtime config.
	 */
	private static function apply_runtime_config_filters( array $config, \WP_Agent $agent, array $input ) {
		return apply_filters( 'agents_api_runtime_agent_config', $config, $agent, $input );
	}

	/**
	 * Resolve the registered agent's provider/model binding for this chat turn.
	 *
	 * Only canonical, normalized request context is forwarded. Runtime overrides
	 * remain server-owned through the agent and resolver contracts.
	 *
	 * @param \WP_Agent|null      $agent  Selected agent, or null for an agent-less turn.
	 * @param array<string,mixed> $input  Canonical agents/chat input.
	 * @param array<string,mixed> $config Agent default config.
	 * @return WP_Agent_Runtime_Profile|null Resolved profile, or null without an agent/binding.
	 */
	private static function resolve_runtime_profile( ?\WP_Agent $agent, array $input, array $config ): ?WP_Agent_Runtime_Profile {
		if ( ! $agent instanceof \WP_Agent || ! function_exists( 'wp_resolve_agent_runtime_profile' ) ) {
			return null;
		}

		$context = array(
			'mode'           => 'chat',
			'agent_config'   => $config,
			'principal'      => $input['principal'] ?? null,
			'workspace'      => self::workspace_from_input( $input ),
			'workspace_id'   => is_string( $input['workspace_id'] ?? null ) ? trim( $input['workspace_id'] ) : '',
			'client_id'      => is_string( $input['client_id'] ?? null ) ? trim( $input['client_id'] ) : '',
			'client_context' => is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array(),
		);

		if ( is_string( $input['provider'] ?? null ) ) {
			$context['provider_id'] = trim( $input['provider'] );
		}
		if ( is_string( $input['model'] ?? null ) ) {
			$context['model_id'] = trim( $input['model'] );
		}

		$profile = wp_resolve_agent_runtime_profile( $agent, $context );
		return $profile instanceof WP_Agent_Runtime_Profile ? $profile : null;
	}

	/**
	 * Resolve a registered agent from the Agents API runtime registry.
	 *
	 * An empty slug runs an agent-less turn (provider/model/system-prompt must
	 * then come from the request). A non-empty slug that is not registered is a
	 * hard error so callers learn the agent is missing rather than silently
	 * falling back to bare input.
	 *
	 * @param string $agent_slug Requested agent slug.
	 * @return \WP_Agent|\WP_Error|null Registered agent, null for agent-less, or error.
	 */
	private static function resolve_agent( string $agent_slug ) {
		if ( '' === $agent_slug ) {
			return null;
		}

		if ( ! class_exists( '\WP_Agents_Registry' ) ) {
			return new \WP_Error(
				'agents_chat_registry_unavailable',
				'The Agents API registry is unavailable.',
				array( 'status' => 500 )
			);
		}

		$registry = \WP_Agents_Registry::get_instance();
		if ( ! $registry instanceof \WP_Agents_Registry ) {
			return new \WP_Error(
				'agents_chat_registry_unavailable',
				'The Agents API registry is not yet initialized.',
				array( 'status' => 500 )
			);
		}

		if ( ! $registry->is_registered( $agent_slug ) ) {
			return new \WP_Error(
				'agents_chat_agent_not_found',
				sprintf( 'Agent "%s" is not registered.', $agent_slug ),
				array( 'status' => 404 )
			);
		}

		return $registry->get_registered( $agent_slug );
	}

	/**
	 * Resolve the agent system prompt from its default config.
	 *
	 * @param array<string,mixed> $config Agent default config.
	 * @return string
	 */
	private static function resolve_system_prompt( array $config ): string {
		foreach ( array( 'system_prompt', 'instructions', 'system', 'prompt' ) as $key ) {
			if ( is_string( $config[ $key ] ?? null ) && '' !== trim( $config[ $key ] ) ) {
				return $config[ $key ];
			}
		}

		return '';
	}

	/**
	 * Build host-mediated tool declarations from the agent's declared abilities.
	 *
	 * The agent config lists the ability names the agent may call. Runtime agent
	 * bundles declare this set as `enabled_tools` (the field the bundle schema and
	 * validators standardize on); `tools`, `abilities`, and `tool_names` are also
	 * accepted as aliases. Each name becomes a server tool declaration whose
	 * model-facing name is the ability name; {@see WP_Agent_Ability_Tool_Executor}
	 * dispatches it back through the Abilities API.
	 *
	 * Runtime overlays come only from the server-side
	 * `agents_api_runtime_tool_declarations` filter after the agent, generated
	 * session id, and run id are resolved. Each entry must name an existing
	 * canonical enabled tool. Host overlays can replace only model-facing
	 * description, parameters, and runtime execution metadata. Client overlays
	 * are additional, request-scoped declarations and suspend at the runtime-tool
	 * continuation boundary instead of being dispatched as abilities.
	 *
	 * @param array<string,mixed> $config Agent default config.
	 * @param array<mixed>        $overlays Server-provided declaration overlays.
	 * @param WP_Agent_Tool_Executor_Registry $executor_registry Frozen registry for this run.
	 * @return array<string,array<mixed>>|\WP_Error Declarations, or an invalid overlay error.
	 */
	private static function resolve_tool_declarations( array $config, array $overlays = array(), ?WP_Agent_Tool_Executor_Registry $executor_registry = null ) {
		$names = is_array( $config['enabled_tools'] ?? null ) ? $config['enabled_tools'] : array();
		if ( empty( $names ) ) {
			foreach ( array( 'tools', 'abilities', 'tool_names' ) as $key ) {
				if ( is_array( $config[ $key ] ?? null ) ) {
					$names = array_merge( $names, $config[ $key ] );
				}
			}
		}

		$declarations = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === trim( $name ) ) {
				continue;
			}
			$name = trim( $name );
			if ( isset( $declarations[ $name ] ) ) {
				continue;
			}

			$ability     = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
			$description = '';
			$parameters  = array();
			if ( $ability instanceof \WP_Ability ) {
				$description = trim( (string) $ability->get_description() );
				$parameters  = $ability->get_input_schema();
			}

			$source = WP_Agent_Tool_Declaration::sourceFromName( $name );
			if ( '' === $source ) {
				$source = 'agents';
			}

			$declarations[ $name ] = array(
				'name'        => $name,
				'source'      => $source,
				'description' => '' !== $description ? $description : $name,
				'parameters'  => $parameters,
				'executor'    => WP_Agent_Tool_Declaration::EXECUTOR_HOST,
				'scope'       => WP_Agent_Tool_Declaration::SCOPE_RUN,
				'ability'     => $name,
			);
		}

		if ( array() === $overlays ) {
			return $declarations;
		}
		$executor_registry = $executor_registry ?? new WP_Agent_Tool_Executor_Registry();
		$seen_names        = array();
		$seen_aliases      = array();
		$reserved_aliases  = array();
		foreach ( $declarations as $name => $declaration ) {
			$reserved_aliases[ WP_Agent_Tool_Declaration::providerSafeName( $name ) ] = true;
		}
		foreach ( $overlays as $map_name => $overlay ) {
			if ( ! is_string( $map_name ) || ! is_array( $overlay ) ) {
				return self::runtime_tool_declaration_error( 'declaration' );
			}

			try {
				$normalized = WP_Agent_Tool_Declaration::normalizeForConversationRequest( $overlay );
			} catch ( \InvalidArgumentException $error ) {
				return self::runtime_tool_declaration_error( $error->getMessage() );
			}

			$name      = $normalized['name'] ?? '';
			$is_client = WP_Agent_Tool_Declaration::EXECUTOR_CLIENT === ( $normalized['executor'] ?? null );
			if ( ! is_string( $name ) || $map_name !== $name || isset( $seen_names[ $name ] ) || ( ! $is_client && ! isset( $declarations[ $name ] ) ) || ( $is_client && isset( $declarations[ $name ] ) ) ) {
				return self::runtime_tool_declaration_error( 'name' );
			}
			$seen_names[ $name ] = true;

			$alias = $normalized['provider_safe_name'] ?? WP_Agent_Tool_Declaration::providerSafeName( $name );
			if ( ! is_string( $alias ) || isset( $seen_aliases[ $alias ] ) || ( $is_client && isset( $reserved_aliases[ $alias ] ) ) ) {
				return self::runtime_tool_declaration_error( 'provider_safe_name' );
			}
			$seen_aliases[ $alias ] = true;

			if ( $is_client ) {
				if ( '' !== WP_Agent_Tool_Executor_Registry::targetIdFromDeclaration( $normalized ) ) {
					return self::runtime_tool_declaration_error( 'executor_target' );
				}
				$declarations[ $name ] = $normalized;
				continue;
			}

			$target_id      = WP_Agent_Tool_Executor_Registry::targetIdFromDeclaration( $normalized );
			$runtime        = is_array( $normalized['runtime'] ?? null ) ? $normalized['runtime'] : array();
			$declares_target = array_key_exists( WP_Agent_Tool_Executor_Registry::RUNTIME_EXECUTOR_TARGET, $runtime );
			if ( $declares_target && ( '' === $target_id || null === $executor_registry->executorForTarget( $target_id ) ) ) {
				return self::runtime_tool_declaration_error( 'executor_target' );
			}

			// Imported declaration policy, bindings, and ability identity remain immutable.
			foreach ( array( 'description', 'parameters', 'runtime' ) as $field ) {
				if ( array_key_exists( $field, $normalized ) ) {
					$declarations[ $name ][ $field ] = $normalized[ $field ];
				}
			}
		}

		return $declarations;
	}

	/**
	 * Collect server-only runtime tool declaration overlays for this run.
	 *
	 * @param \WP_Agent|null     $agent           Selected agent, or null for agent-less turns.
	 * @param array<string,mixed> $runtime_context Final server-generated run context.
	 * @return array<mixed> Declaration overlay map supplied by server code.
	 */
	private static function runtime_tool_declarations( ?\WP_Agent $agent, array $runtime_context ): array {
		$overlays = apply_filters( 'agents_api_runtime_tool_declarations', array(), $agent, $runtime_context );
		return is_array( $overlays ) ? $overlays : array( '__invalid__' => $overlays );
	}

	/**
	 * Provide trusted filters sanitized client-supplied context data.
	 *
	 * This context is not an authorization signal. A runtime declaration filter
	 * must establish authorization from a server-authenticated principal or its
	 * own trusted transport binding before it exposes a client tool.
	 *
	 * @param array<string,mixed> $input Canonical chat input.
	 * @return array<string,mixed>
	 */
	private static function runtime_client_context( array $input ): array {
		$client_context = is_array( $input['client_context'] ?? null ) ? \AgentsAPI\AI\agents_api_string_keyed_array( $input['client_context'] ) : array();

		return agents_chat_strip_runtime_tool_declaration_fields( $client_context );
	}

	/**
	 * Build the public error returned for a rejected trusted-runtime overlay.
	 *
	 * @param string $reason Machine-readable validation reason.
	 * @return \WP_Error
	 */
	private static function runtime_tool_declaration_error( string $reason ): \WP_Error {
		return new \WP_Error(
			'agents_chat_invalid_runtime_tool_declaration',
			'Runtime tool declarations must be a canonical, allowlisted map supplied by the server runtime.',
			array( 'status' => 400, 'reason' => $reason )
		);
	}

	/**
	 * Resolve declarative deterministic tool-call rules from the agent config.
	 *
	 * The agent's bundle declares `tool_call_rules` (bounded discovery + required
	 * commit) and the loop enforces them natively. The handler only forwards the
	 * declared list of rule arrays; {@see WP_Agent_Tool_Call_Gate} normalizes and
	 * enforces them deterministically.
	 *
	 * @param array<string,mixed> $config Agent default config.
	 * @return list<array<mixed>> Declared rule arrays.
	 */
	private static function resolve_tool_call_rules( array $config ): array {
		$raw = $config['tool_call_rules'] ?? null;
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rules = array();
		foreach ( $raw as $rule ) {
			if ( is_array( $rule ) ) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * Resolve the maximum number of agent-loop turns for this request.
	 *
	 * @param array<string,mixed> $input  Canonical chat input.
	 * @param array<string,mixed> $config Agent default config.
	 * @return int
	 */
	private static function resolve_max_turns( array $input, array $config ): int {
		foreach ( array( $input['max_turns'] ?? null, $config['max_turns'] ?? null ) as $candidate ) {
			if ( is_numeric( $candidate ) && (int) $candidate > 0 ) {
				return (int) $candidate;
			}
		}

		return self::DEFAULT_MAX_TURNS;
	}

	/**
	 * Best-effort creation of a transcript session row for the authenticated owner.
	 *
	 * User-owned sessions retain the legacy store path. Non-user principals use
	 * the optional principal-aware store contract when available; otherwise they
	 * remain stateless with a synthesized session id.
	 *
	 * @param WP_Agent_Conversation_Store $store      Resolved conversation store.
	 * @param string                      $agent_slug Registered agent slug.
	 * @param array<string,mixed>         $input      Canonical chat input.
	 * @return string Created session id, or empty string when none was created.
	 */
	private static function create_session( WP_Agent_Conversation_Store $store, string $agent_slug, array $input ): string {
		try {
			$workspace = self::workspace_from_input( $input ) ?? WP_Agent_Workspace_Scope::from_parts( 'site', self::default_workspace_id() );
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}

		try {
			$principal = $input['principal'] ?? null;
			if ( is_array( $principal ) ) {
				$principal = WP_Agent_Execution_Principal::from_array( agents_chat_string_keyed_array( $principal ) );
			}
			$owner = $principal instanceof WP_Agent_Execution_Principal ? $principal->conversation_owner() : null;
			if ( is_array( $owner ) ) {
				if ( WP_Agent_Execution_Principal::OWNER_TYPE_USER === $owner['type'] && is_numeric( $owner['key'] ) && (int) $owner['key'] > 0 ) {
					return $store->create_session(
						$workspace,
						(int) $owner['key'],
						$agent_slug,
						array( 'source' => 'agents-api-default-chat-handler' ),
						'chat'
					);
				}
				if ( $store instanceof WP_Agent_Principal_Conversation_Store ) {
					return $store->create_session_for_owner(
						$workspace,
						$owner,
						$agent_slug,
						array( 'source' => 'agents-api-default-chat-handler' ),
						'chat'
					);
				}
				return '';
			}

			$user_id = self::resolve_user_id( $input );
			if ( $user_id > 0 ) {
				return $store->create_session(
					$workspace,
					$user_id,
					$agent_slug,
					array( 'source' => 'agents-api-default-chat-handler' ),
					'chat'
				);
			}
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}

		return '';
	}

	/** @param array<string,mixed> $input Canonical chat input. */
	private static function workspace_from_input( array $input ): ?WP_Agent_Workspace_Scope {
		if ( ! is_array( $input['workspace'] ?? null ) ) {
			return null;
		}

		try {
			return WP_Agent_Workspace_Scope::from_array( $input['workspace'] );
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
			return null;
		}
	}

	/**
	 * Resolve the owning WordPress user id for transcript persistence.
	 *
	 * @param array<string,mixed> $input Canonical chat input.
	 * @return int
	 */
	private static function resolve_user_id( array $input ): int {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id > 0 ) {
			return $user_id;
		}

		$principal = $input['principal'] ?? null;
		if ( is_array( $principal ) && is_numeric( $principal['acting_user_id'] ?? null ) ) {
			return max( 0, (int) $principal['acting_user_id'] );
		}

		return 0;
	}

	/**
	 * Resolve the default workspace id without assuming multisite.
	 *
	 * @return string
	 */
	private static function default_workspace_id(): string {
		if ( function_exists( 'get_current_blog_id' ) ) {
			return (string) get_current_blog_id();
		}

		return 'default';
	}

	/**
	 * Build generic session metadata for transcript persistence.
	 *
	 * @param array<string,mixed> $result Conversation loop result.
	 * @return array<string,mixed>
	 */
	private static function session_metadata( array $result ): array {
		$completed = (bool) ( $result['completed'] ?? true );

		return array(
			'status'        => $completed ? 'completed' : 'processing',
			'message_count' => is_array( $result['messages'] ?? null ) ? count( $result['messages'] ) : 0,
			'current_turn'  => self::int_value( $result['turn_count'] ?? null ),
		);
	}

	/**
	 * Project the loop result to the canonical `agents/chat` output shape.
	 *
	 * @param string                        $session_id      Session id to thread further turns under.
	 * @param array<string,mixed>           $result          Conversation loop result.
	 * @param WP_Agent_Runtime_Profile|null $runtime_profile Resolved runtime profile.
	 * @return array<string,mixed>
	 */
	private static function to_canonical_output( string $session_id, array $result, ?WP_Agent_Runtime_Profile $runtime_profile = null ): array {
		$metadata = array_filter(
			array(
				'status'             => is_string( $result['status'] ?? null ) ? $result['status'] : null,
				'turn_count'         => isset( $result['turn_count'] ) ? self::int_value( $result['turn_count'] ) : null,
				'usage'              => is_array( $result['usage'] ?? null ) ? $result['usage'] : null,
				'run_outcome'        => is_array( $result['run_outcome'] ?? null ) ? $result['run_outcome'] : null,
				'tool_observability' => is_array( $result['tool_observability'] ?? null ) ? $result['tool_observability'] : null,
				'runtime_profile'    => $runtime_profile instanceof WP_Agent_Runtime_Profile ? self::runtime_profile_metadata( $runtime_profile ) : null,
			),
			static fn( $value ): bool => null !== $value
		);

		$output = array(
			'session_id' => $session_id,
			'reply'      => is_string( $result['final_content'] ?? null ) ? $result['final_content'] : '',
			'messages'   => self::to_canonical_messages( is_array( $result['messages'] ?? null ) ? array_values( $result['messages'] ) : array() ),
			'completed'  => (bool) ( $result['completed'] ?? true ),
			'metadata'   => array( 'agents_api' => $metadata ),
		);
		$run_outcome_status = is_array( $result['run_outcome'] ?? null ) && is_string( $result['run_outcome']['status'] ?? null ) ? $result['run_outcome']['status'] : null;
		if ( null !== $run_outcome_status ) {
			$output['status'] = $run_outcome_status;
		}
		if ( is_array( $result['runtime_tool_pending'] ?? null ) ) {
			$output['runtime_tool_pending'] = $result['runtime_tool_pending'];
		}
		if ( is_array( $result['run_outcome'] ?? null ) ) {
			$output['run_outcome'] = $result['run_outcome'];
		}
		if ( array_key_exists( 'structured_output', $result ) ) {
			$output['structured_output'] = $result['structured_output'];
			if ( ! empty( $result['structured_output_diagnostics'] ) && is_array( $result['structured_output_diagnostics'] ) ) {
				$output['metadata']['agents_api']['structured_output'] = $result['structured_output_diagnostics'];
			}
		}

		return $output;
	}

	/**
	 * Project a runtime profile to public diagnostics without identity payloads.
	 *
	 * @return array<string,mixed>
	 */
	private static function runtime_profile_metadata( WP_Agent_Runtime_Profile $profile ): array {
		return array(
			'agent_slug'  => $profile->agent_slug(),
			'provider_id' => $profile->provider_id(),
			'model_id'    => $profile->model_id(),
			'provenance'  => self::runtime_profile_provenance_metadata( $profile->provenance() ),
		);
	}

	/**
	 * Restrict public provenance to its documented source/path diagnostics.
	 *
	 * @param array<string,mixed> $provenance Internal profile provenance.
	 * @return array<string,mixed>
	 */
	private static function runtime_profile_provenance_metadata( array $provenance ): array {
		$public = array();
		foreach ( array( 'provider_id', 'model_id' ) as $field ) {
			if ( is_array( $provenance[ $field ] ?? null ) ) {
				$public[ $field ] = self::runtime_profile_provenance_entry( $provenance[ $field ] );
			}
		}

		if ( is_array( $provenance['config_sources'] ?? null ) ) {
			$public['config_sources'] = array_values(
				array_map(
					static fn( array $entry ): array => self::runtime_profile_provenance_entry( $entry ),
					array_filter( $provenance['config_sources'], 'is_array' )
				)
			);
		}

		return $public;
	}

	/**
	 * @param array<mixed> $entry Internal provenance entry.
	 * @return array{source:string,path:string}
	 */
	private static function runtime_profile_provenance_entry( array $entry ): array {
		return array(
			'source' => is_string( $entry['source'] ?? null ) ? $entry['source'] : '',
			'path'   => is_string( $entry['path'] ?? null ) ? $entry['path'] : '',
		);
	}

	/**
	 * Reduce loop transcript envelopes to the canonical `{role, content}` list.
	 *
	 * Tool-call and tool-result envelopes are runtime detail and are omitted; the
	 * canonical message list carries only assistant/user text turns.
	 *
	 * @param array<int,mixed> $conversation Loop transcript messages.
	 * @return array<int,array{role:string,content:string}>
	 */
	private static function to_canonical_messages( array $conversation ): array {
		$messages = array();
		foreach ( $conversation as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$type = is_string( $message['type'] ?? null ) ? $message['type'] : '';
			if ( WP_Agent_Message::TYPE_TOOL_CALL === $type || WP_Agent_Message::TYPE_TOOL_RESULT === $type ) {
				continue;
			}

			$role    = $message['role'] ?? null;
			$content = $message['content'] ?? null;
			if ( ! is_string( $role ) || '' === $role || ! is_string( $content ) ) {
				continue;
			}

			$messages[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $messages;
	}

	/**
	 * Coerce a mixed value to an int, defaulting non-numerics to zero.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function int_value( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Return the first non-empty trimmed string from the supplied candidates.
	 *
	 * @param string ...$candidates Candidate strings.
	 * @return string
	 */
	private static function first_non_empty( string ...$candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		return '';
	}

	/**
	 * Generate an opaque session id for stateless (store-less) turns.
	 *
	 * @return string
	 */
	private static function generate_session_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}

		try {
			$bytes = random_bytes( 16 );
		} catch ( \Throwable $error ) {
			unset( $error );
			return 'session-' . uniqid( '', true );
		}

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Normalize caller history for a store-less turn.
	 *
	 * @param array<mixed> $history Raw caller history.
	 * @return array<int,array<string,mixed>> Canonical text messages.
	 */
	private static function stateless_history( array $history ): array {
		$messages = array();
		foreach ( $history as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$role    = is_string( $message['role'] ?? null ) ? $message['role'] : '';
			$content = is_string( $message['content'] ?? null ) ? $message['content'] : '';
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) || '' === trim( $content ) ) {
				continue;
			}
			$messages[] = WP_Agent_Message::text( $role, $content );
		}

		return $messages;
	}
}

WP_Agent_Default_Chat_Handler::register();
