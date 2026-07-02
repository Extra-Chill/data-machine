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
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\Tools\WP_Agent_Ability_Tool_Executor;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Sessions;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
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
	}

	/**
	 * Execute one canonical chat turn natively, without any external runtime.
	 *
	 * @param array<string,mixed> $input Canonical agents/chat input.
	 * @return array<string,mixed>|\WP_Error Canonical agents/chat output, or WP_Error.
	 */
	public static function execute( array $input ) {
		$message = is_string( $input['message'] ?? null ) ? trim( $input['message'] ) : '';
		if ( '' === $message ) {
			return new \WP_Error( 'agents_chat_empty_message', 'Message cannot be empty.', array( 'status' => 400 ) );
		}

		$agent_slug = is_string( $input['agent'] ?? null ) ? trim( $input['agent'] ) : '';
		$agent      = self::resolve_agent( $agent_slug );
		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		$config = ( $agent instanceof \WP_Agent ) ? $agent->get_default_config() : array();

		$provider = self::first_non_empty(
			is_string( $input['provider'] ?? null ) ? $input['provider'] : '',
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

		$system_prompt     = self::resolve_system_prompt( $config );
		$tool_declarations = self::resolve_tool_declarations( $config );
		$max_turns         = self::resolve_max_turns( $input, $config );
		$tool_call_rules   = self::resolve_tool_call_rules( $config );

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

		if ( '' === $session_id ) {
			$session_id = self::generate_session_id();
		}

		$messages[] = WP_Agent_Message::text( 'user', $message );
		$messages   = WP_Agent_Message::normalize_many( $messages );

		$loop_options = array(
			'system_prompt' => $system_prompt,
			'max_turns'     => $max_turns,
			'context'       => array(
				'session_id' => $session_id,
				'agent_slug' => $agent_slug,
			),
		);
		if ( ! empty( $tool_declarations ) ) {
			// Default executor: dispatch tool calls through registered abilities.
			// The loop also consults the #377 per-target executor registry
			// (`agents_api_tool_executors`) for declarations that select another
			// execution environment, so consumers can override per tool target.
			$loop_options['tool_executor'] = new WP_Agent_Ability_Tool_Executor();
		}
		if ( ! empty( $tool_call_rules ) ) {
			// Declarative deterministic tool-call gating. The loop enforces these
			// rules natively ({@see WP_Agent_Tool_Call_Gate}) — bounded discovery
			// before a required commit, and a completion block until the commit
			// tool runs — so the guarantee is the runtime's, not a prompt's.
			$loop_options['tool_call_rules'] = $tool_call_rules;
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

		return self::to_canonical_output( $session_id, $result );
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
	 * @param array<string,mixed> $config Agent default config.
	 * @return array<string,array<string,mixed>> Declarations keyed by tool name.
	 */
	private static function resolve_tool_declarations( array $config ): array {
		$names = array();
		foreach ( array( 'enabled_tools', 'tools', 'abilities', 'tool_names' ) as $key ) {
			if ( is_array( $config[ $key ] ?? null ) ) {
				$names = array_merge( $names, $config[ $key ] );
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

		return $declarations;
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
	 * Best-effort creation of a transcript session row for a user-owned chat.
	 *
	 * The default driver stays generic: it only creates a row when a store is
	 * registered and a WordPress user owns the turn (the contracts-only default
	 * CPT store keys sessions by user id). Anonymous or principal-owned chats run
	 * statelessly with a synthesized session id rather than coupling this default
	 * to a specific principal-store implementation.
	 *
	 * @param WP_Agent_Conversation_Store $store      Resolved conversation store.
	 * @param string                      $agent_slug Registered agent slug.
	 * @param array<string,mixed>         $input      Canonical chat input.
	 * @return string Created session id, or empty string when none was created.
	 */
	private static function create_session( WP_Agent_Conversation_Store $store, string $agent_slug, array $input ): string {
		$user_id = self::resolve_user_id( $input );
		if ( $user_id <= 0 ) {
			return '';
		}

		try {
			$workspace = WP_Agent_Workspace_Scope::from_parts( 'site', self::default_workspace_id() );
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}

		try {
			$session_id = $store->create_session(
				$workspace,
				$user_id,
				$agent_slug,
				array( 'source' => 'agents-api-default-chat-handler' ),
				'chat'
			);
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}

		return $session_id;
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
	 * @param string              $session_id Session id to thread further turns under.
	 * @param array<string,mixed> $result     Conversation loop result.
	 * @return array<string,mixed>
	 */
	private static function to_canonical_output( string $session_id, array $result ): array {
		$metadata = array_filter(
			array(
				'status'      => is_string( $result['status'] ?? null ) ? $result['status'] : null,
				'turn_count'  => isset( $result['turn_count'] ) ? self::int_value( $result['turn_count'] ) : null,
				'usage'       => is_array( $result['usage'] ?? null ) ? $result['usage'] : null,
				'run_outcome' => is_array( $result['run_outcome'] ?? null ) ? $result['run_outcome'] : null,
			),
			static fn( $value ): bool => null !== $value
		);

		return array(
			'session_id' => $session_id,
			'reply'      => is_string( $result['final_content'] ?? null ) ? $result['final_content'] : '',
			'messages'   => self::to_canonical_messages( is_array( $result['messages'] ?? null ) ? array_values( $result['messages'] ) : array() ),
			'completed'  => (bool) ( $result['completed'] ?? true ),
			'metadata'   => array( 'agents_api' => $metadata ),
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
}

WP_Agent_Default_Chat_Handler::register();
