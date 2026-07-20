<?php
/**
 * Pure-PHP regression coverage for chat caller identity propagation.
 *
 * Run with: php tests/chat-caller-context-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

namespace AgentsAPI\AI {
	final class WP_Agent_Execution_Principal {
		public function __construct( public readonly int $acting_user_id ) {}
	}
}

namespace AgentsAPI\AI\Channels {
	function register_chat_handler( callable $handler ): void {
		$GLOBALS['datamachine_chat_caller_handler'] = $handler;
	}
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static ?\AgentsAPI\AI\WP_Agent_Execution_Principal $principal = null;

		public static function get_execution_principal(): ?\AgentsAPI\AI\WP_Agent_Execution_Principal {
			return self::$principal;
		}

		public static function can( string $action ): bool {
			unset( $action );
			return true;
		}

		public static function acting_user_id(): int {
			return 700;
		}

		public static function get_acting_token_id(): ?int {
			return null;
		}
	}
}

namespace DataMachine\Abilities\Chat {
	class ChatTranscriptOwner {
		public static function resolve_for_request( array $options, int $user_id ): array {
			unset( $options );
			return array(
				'type' => 'user',
				'key'  => (string) $user_id,
			);
		}
	}
}

namespace DataMachine\Core\Agents {
	class AgentIdentity {}
	class AgentIdentityResolver {}
}

namespace DataMachine\Core {
	class PluginSettings {
		public const DEFAULT_MAX_TURNS = 25;

		public static function get( string $key, mixed $default = null ): mixed {
			unset( $key );
			return $default;
		}

		public static function resolveModelForAgentMode( int $agent_id, string $mode ): array {
			unset( $agent_id, $mode );
			return array(
				'provider' => 'test-provider',
				'model'    => 'test-model',
			);
		}
	}
}

namespace DataMachine\Core\Database\Chat {
	class CallerContextStore {
		public array $session = array(
			'session_id' => 'caller-context-session',
			'user_id'    => 700,
			'agent_id'   => 9,
			'agent_slug' => 'agent-9',
			'messages'   => array(),
			'metadata'   => array(),
			'mode'       => 'chat',
			'provider'   => 'test-provider',
			'model'      => 'test-model',
			'title'      => 'Existing title',
		);

		public function get_session( string $session_id ): array {
			unset( $session_id );
			return $this->session;
		}

		public function session_matches_owner( array $session, array $owner ): bool {
			unset( $session, $owner );
			return true;
		}

		public function acquire_session_lock( string $session_id ): string {
			unset( $session_id );
			return 'lock';
		}

		public function release_session_lock( string $session_id, string $lock ): void {
			unset( $session_id, $lock );
		}

		public function update_session( string $session_id, array $messages, array $metadata, string $provider, string $model ): bool {
			unset( $session_id, $provider, $model );
			$this->session['messages'] = $messages;
			$this->session['metadata'] = $metadata;
			return true;
		}
	}

	class ConversationStoreFactory {
		private static ?CallerContextStore $store = null;

		public static function get(): CallerContextStore {
			self::$store ??= new CallerContextStore();
			return self::$store;
		}

		public static function resolve_agent_slug_for_transcript( int $agent_id ): string {
			return 'agent-' . $agent_id;
		}
	}
}

namespace DataMachine\Core\Workspace {
	class WordPressWorkspaceScope {
		public static function current(): string {
			return 'site:test';
		}
	}
}

namespace DataMachine\Engine\AI\Tools {
	class ToolManager {}

	class ToolPolicyResolver {
		public const MODE_CHAT = 'chat';

		public static function normalizeModes( mixed $modes ): array {
			return array_values( array_filter( array_map( 'strval', (array) $modes ) ) );
		}

		public function resolve( array $args ): array {
			$GLOBALS['datamachine_chat_caller_resolver_args'][] = $args;
			$calling_user_id = (int) ( $args['calling_user_id'] ?? 0 );
			return $calling_user_id > 0
				? array( 'caller_tool' => array( 'caller' => $calling_user_id ) )
				: array();
		}
	}
}

namespace DataMachine\Engine\AI {
	class ConversationManager {
		public static function buildConversationMessage( string $role, mixed $content, array $metadata ): array {
			return compact( 'role', 'content', 'metadata' );
		}

		public static function buildMultiModalContent( string $message, array $attachments ): array {
			return compact( 'message', 'attachments' );
		}
	}

	class DataMachineAgentConsentPolicy {
		public static function get(): self {
			return new self();
		}

		public function can_store_transcript( array $context ): object {
			unset( $context );
			return new class() {
				public function to_array(): array {
					return array( 'allowed' => true );
				}
			};
		}
	}

	function datamachine_run_conversation( array $messages, array $tools, string $provider, string $model, array $modes, array $context, int $max_turns, bool $single_turn ): array {
		unset( $provider, $model, $modes, $max_turns, $single_turn );
		$GLOBALS['datamachine_chat_caller_loop_tools'][]   = $tools;
		$GLOBALS['datamachine_chat_caller_loop_context'][] = $context;
		$messages[] = array( 'role' => 'assistant', 'content' => 'ok' );
		return array(
			'messages'      => $messages,
			'final_content' => 'ok',
			'turn_count'    => 1,
			'usage'         => array(),
			'metadata'      => array(
				'completed'        => true,
				'response_marker'  => 'preserved',
			),
		);
	}

	function datamachine_conversation_metadata( array $result ): array {
		if ( is_array( $result['metadata']['datamachine'] ?? null ) ) {
			return $result['metadata']['datamachine'];
		}

		return is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
	}

	function datamachine_summarize_tool_execution_results( array $results, bool $include_results ): array {
		unset( $results, $include_results );
		return array();
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Error {}

	function add_filter( ...$args ): bool {
		unset( $args );
		return true;
	}

	function get_current_user_id(): int {
		return (int) ( $GLOBALS['datamachine_chat_caller_current_user'] ?? 0 );
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}

	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $key ) ?? '' );
	}

	function current_time( string $type, bool $gmt = false ): string {
		unset( $type, $gmt );
		return '2026-07-20 00:00:00';
	}

	function home_url( string $path = '' ): string {
		unset( $path );
		return 'https://example.test/';
	}

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}

	function set_transient( string $key, mixed $value, int $expiration ): bool {
		unset( $key, $value, $expiration );
		return true;
	}

	function wp_get_ability( string $name ): null {
		unset( $name );
		return null;
	}

	function do_action( ...$args ): void {
		unset( $args );
	}

	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	require_once dirname( __DIR__ ) . '/inc/Abilities/Chat/AgentsChatHandler.php';
	require_once dirname( __DIR__ ) . '/inc/Api/Chat/ChatOrchestrator.php';

	$failures = array();
	$assert_same = static function ( mixed $expected, mixed $actual, string $label ) use ( &$failures ): void {
		if ( $expected === $actual ) {
			echo "PASS: {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "FAIL: {$label}\n";
		echo '  expected: ' . var_export( $expected, true ) . "\n";
		echo '  actual:   ' . var_export( $actual, true ) . "\n";
	};

	$handler = new \DataMachine\Abilities\Chat\AgentsChatHandler();
	$method  = new ReflectionMethod( $handler, 'resolveCallingUserId' );

	$GLOBALS['datamachine_chat_caller_current_user'] = 41;
	$assert_same( 41, $method->invoke( $handler, array() ), 'browser caller uses the authenticated WordPress user' );

	\DataMachine\Abilities\PermissionHelper::$principal = new \AgentsAPI\AI\WP_Agent_Execution_Principal( 52 );
	$assert_same( 52, $method->invoke( $handler, array() ), 'delegated execution principal preserves its acting user' );

	\DataMachine\Abilities\PermissionHelper::$principal = new \AgentsAPI\AI\WP_Agent_Execution_Principal( 0 );
	$assert_same( 0, $method->invoke( $handler, array() ), 'system execution principal does not inherit the browser or runtime owner' );
	\DataMachine\Abilities\PermissionHelper::$principal = null;

	$run_turn = static function ( int $calling_user_id ): array {
		return \DataMachine\Api\Chat\ChatOrchestrator::executeConversationTurn(
			'caller-context-session',
			array(),
			'test-provider',
			'test-model',
			array(
				'user_id'         => 700,
				'calling_user_id' => $calling_user_id,
				'agent_id'        => 9,
				'modes'           => array( 'chat' ),
			)
		);
	};

	$run_turn( 41 );
	$assert_same( 41, $GLOBALS['datamachine_chat_caller_resolver_args'][0]['calling_user_id'], 'browser caller reaches tool resolution' );
	$assert_same( 41, $GLOBALS['datamachine_chat_caller_loop_tools'][0]['caller_tool']['caller'], 'browser caller-sensitive tool is visible' );

	$run_turn( 52 );
	$assert_same( 52, $GLOBALS['datamachine_chat_caller_resolver_args'][1]['calling_user_id'], 'delegated acting user reaches tool resolution' );
	$assert_same( 52, $GLOBALS['datamachine_chat_caller_loop_context'][1]['calling_user_id'], 'delegated acting user reaches the conversation turn' );

	$run_turn( 0 );
	$assert_same( array(), $GLOBALS['datamachine_chat_caller_loop_tools'][2], 'system context does not receive caller-sensitive tools' );
	$assert_same( 700, $GLOBALS['datamachine_chat_caller_loop_context'][2]['user_id'], 'system context retains separate runtime ownership' );
	$assert_same( 0, $GLOBALS['datamachine_chat_caller_loop_context'][2]['calling_user_id'], 'system context retains an explicit no-human caller' );

	$response = \DataMachine\Api\Chat\ChatOrchestrator::processChat(
		'hello',
		'test-provider',
		'test-model',
		700,
		array(
			'session_id'      => 'caller-context-session',
			'agent_id'        => 9,
			'calling_user_id' => 52,
		)
	);
	$assert_same( 'preserved', $response['metadata']['datamachine']['response_marker'] ?? null, 'loop response metadata survives final response assembly' );

	$store = \DataMachine\Core\Database\Chat\ConversationStoreFactory::get();
	$store->session['metadata']['status']          = 'processing';
	$store->session['metadata']['has_pending_tools'] = true;
	$store->session['metadata']['calling_user_id'] = 52;
	\DataMachine\Api\Chat\ChatOrchestrator::processContinue( 'caller-context-session', 700 );
	$assert_same( 52, $GLOBALS['datamachine_chat_caller_resolver_args'][4]['calling_user_id'], 'processContinue restores the delegated caller from session metadata' );
	$assert_same( 52, $GLOBALS['datamachine_chat_caller_loop_tools'][4]['caller_tool']['caller'], 'delegated caller-sensitive tool remains visible after continuation' );

	$store->session['metadata']['status']          = 'processing';
	$store->session['metadata']['has_pending_tools'] = true;
	$store->session['metadata']['calling_user_id'] = 52;
	\DataMachine\Api\Chat\ChatOrchestrator::processContinue( 'caller-context-session', 700, 0 );
	$assert_same( 0, $GLOBALS['datamachine_chat_caller_resolver_args'][5]['calling_user_id'], 'processContinue preserves an explicit no-human override' );
	$assert_same( array(), $GLOBALS['datamachine_chat_caller_loop_tools'][5], 'owner-scoped tools do not appear after no-human continuation' );
	$assert_same( 700, $GLOBALS['datamachine_chat_caller_loop_context'][5]['user_id'], 'continued no-human context retains separate runtime ownership' );
	$assert_same( 0, $GLOBALS['datamachine_chat_caller_loop_context'][5]['calling_user_id'], 'continued no-human context does not inherit the runtime owner' );

	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " assertion(s)\n";
		exit( 1 );
	}

	echo "\nOK: chat caller context regression assertions passed.\n";
}
