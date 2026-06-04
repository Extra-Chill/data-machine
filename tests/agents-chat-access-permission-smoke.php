<?php
/**
 * Pure-PHP smoke tests for canonical agents/chat agent access enforcement.
 *
 * Run with: php tests/agents-chat-access-permission-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace AgentsAPI\AI\Channels {
	function register_chat_handler( callable $handler ): void {
		$GLOBALS['datamachine_agents_chat_access_handler'] = $handler;
	}
}

namespace DataMachine\Core\Agents {
	class AgentIdentity {
		public function __construct(
			public int $agent_id,
			public string $agent_slug,
			public int $owner_id,
			public string $agent_name
		) {}
	}

	class AgentIdentityResolver {
		public function resolve_agent_identity( int|string|array $agent ): AgentIdentity {
			$key = is_array( $agent ) ? (string) ( $agent['agent_slug'] ?? $agent['agent_id'] ?? '' ) : (string) $agent;
			$key = \sanitize_title( $key );
			if ( ! isset( $GLOBALS['datamachine_agents_chat_access_identities'][ $key ] ) ) {
				throw new \InvalidArgumentException( 'agent_not_found' );
			}

			return $GLOBALS['datamachine_agents_chat_access_identities'][ $key ];
		}
	}
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function can( string $action ): bool {
			return 'chat' === $action && (bool) ( $GLOBALS['datamachine_agents_chat_access_broad_chat'] ?? false );
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( ...$args ): bool {
			unset( $args );
			return true;
		}
	}

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( string $value ): string {
			$value = strtolower( trim( $value ) );
			$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
			return trim( (string) $value, '-' );
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			unset( $domain );
			return $text;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		}
	}

	class WP_Agent_Access_Grant {
		public const ROLE_ADMIN    = 'admin';
		public const ROLE_OPERATOR = 'operator';
		public const ROLE_VIEWER   = 'viewer';
	}

	class WP_Agent_Access {
		public static function can_current_principal_access_agent( string $agent_id, string $minimum_role, array $context = array() ): bool {
			unset( $context );
			$GLOBALS['datamachine_agents_chat_access_last_check'] = array( $agent_id, $minimum_role );
			return (bool) ( $GLOBALS['datamachine_agents_chat_access_grants'][ $agent_id ] ?? false );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/Abilities/Chat/AgentsChatHandler.php';

	use DataMachine\Abilities\Chat\AgentsChatHandler;
	use DataMachine\Core\Agents\AgentIdentity;

	$passes   = 0;
	$failures = array();

	$assert_same = static function ( mixed $expected, mixed $actual, string $label ) use ( &$passes, &$failures ): void {
		if ( $expected === $actual ) {
			++$passes;
			echo "PASS: {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "FAIL: {$label}\n";
		echo '  expected: ' . var_export( $expected, true ) . "\n";
		echo '  actual:   ' . var_export( $actual, true ) . "\n";
	};

	$GLOBALS['datamachine_agents_chat_access_identities'] = array(
		'wiki-brain' => new AgentIdentity( 42, 'wiki-brain', 7, 'Wiki Brain' ),
		'42'         => new AgentIdentity( 42, 'wiki-brain', 7, 'Wiki Brain' ),
	);

	$handler = new AgentsChatHandler();

	$GLOBALS['datamachine_agents_chat_access_broad_chat'] = true;
	$GLOBALS['datamachine_agents_chat_access_grants']     = array( 'wiki-brain' => false );
	$assert_same( false, $handler->checkPermission( false, array( 'agent' => 'wiki-brain' ) ), 'chat-capable caller without agent access is denied' );
	$assert_same( array( 'wiki-brain', WP_Agent_Access_Grant::ROLE_VIEWER ), $GLOBALS['datamachine_agents_chat_access_last_check'], 'unauthorized caller is checked against canonical slug' );

	$GLOBALS['datamachine_agents_chat_access_broad_chat'] = false;
	$GLOBALS['datamachine_agents_chat_access_grants']     = array( 'wiki-brain' => true );
	$assert_same( true, $handler->checkPermission( false, array( 'agent' => 'wiki-brain' ) ), 'explicitly authorized caller can chat with the agent' );

	$GLOBALS['datamachine_agents_chat_access_grants'] = array( 'wiki-brain' => false );
	$assert_same( true, $handler->checkPermission( true, array( 'agent' => 'wiki-brain' ) ), 'explicit upstream admin/operator override can chat with the agent' );
	$assert_same( true, $handler->checkPermission( true, array( 'agent' => 'wp-codebox-sandbox' ) ), 'explicit upstream runtime-principal override is preserved for runtime agent slugs' );

	$GLOBALS['datamachine_agents_chat_access_broad_chat'] = true;
	$GLOBALS['datamachine_agents_chat_access_grants']     = array( 'wiki-brain' => false );
	$assert_same( false, $handler->checkPermission( false, array( 'agent' => '42' ) ), 'numeric agent ID cannot bypass access checks' );
	$assert_same( array( 'wiki-brain', WP_Agent_Access_Grant::ROLE_VIEWER ), $GLOBALS['datamachine_agents_chat_access_last_check'], 'numeric agent ID is normalized to canonical slug before access check' );

	$assert_same( false, $handler->checkPermission( false, array( 'agent' => '999' ) ), 'unknown numeric agent ID is denied' );

	if ( $failures ) {
		echo "\n" . count( $failures ) . " failed, {$passes} passed\n";
		exit( 1 );
	}

	echo "\n{$passes} passed, 0 failed\n";
}
