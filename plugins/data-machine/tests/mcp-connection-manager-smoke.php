<?php
/**
 * Smoke tests for generic MCP server registry and connection manager contract.
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ): string {
		return (string) json_encode( $value );
	}
}

$GLOBALS['datamachine_mcp_smoke_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_mcp_smoke_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['datamachine_mcp_smoke_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['datamachine_mcp_smoke_filters'][ $hook ], SORT_NUMERIC );
		foreach ( $GLOBALS['datamachine_mcp_smoke_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted_args ] ) {
				$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}

		return $value;
	}
}

require_once __DIR__ . '/../inc/Engine/MCP/MCPServerRegistry.php';
require_once __DIR__ . '/../inc/Engine/MCP/MCPConnectionManager.php';
require_once __DIR__ . '/../inc/Engine/MCP/functions.php';

use DataMachine\Engine\MCP\MCPConnectionManager;
use DataMachine\Engine\MCP\MCPServerRegistry;

final class FixtureMCPConnection {
	public static int $closed = 0;

	public function close(): void {
		++self::$closed;
	}
}

$passes = 0;
$fails  = 0;

$assert = static function ( string $label, bool $condition ) use ( &$passes, &$fails ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		++$passes;
		return;
	}

	echo "FAIL: {$label}\n";
	++$fails;
};

add_filter(
	'datamachine_mcp_servers',
	static function ( array $servers ): array {
		$servers['context-a8c'] = array(
			'transport' => 'http',
			'url'       => 'https://mcp.example.test/v1',
			'headers'   => array(
				'Authorization' => 'Bearer should-not-leak',
				'Accept'        => 'application/json',
			),
			'env'       => array(
				'PUBLIC_FLAG' => '1',
				'API_TOKEN'   => 'should-not-leak',
			),
			'auth_ref'  => 'wpcom:default',
		);

		return $servers;
	}
);

echo "\n[1] Registry loads and normalizes server configs\n";
MCPServerRegistry::reset();
MCPConnectionManager::reset();

$raw = datamachine_mcp_server( 'context-a8c', false );
$assert( 'registered server is available', is_array( $raw ) );
$assert( 'server id is normalized into config', 'context-a8c' === ( $raw['server_id'] ?? null ) );
$assert( 'default arrays are normalized', isset( $raw['args'] ) && is_array( $raw['args'] ) );
$assert( 'raw config preserves local authorization value for runtime adapter', 'Bearer should-not-leak' === ( $raw['headers']['Authorization'] ?? null ) );

$assert( 'request-local helper registration succeeds', datamachine_mcp_register_server( 'wporg', array( 'url' => 'https://wporg.example.test/mcp' ) ) );
$assert( 'helper-registered server is readable', 'wporg' === ( datamachine_mcp_server( 'wporg', false )['server_id'] ?? null ) );

echo "\n[2] Redacted config never leaks secrets\n";
$redacted = datamachine_mcp_server( 'context-a8c', true );
$encoded  = wp_json_encode( $redacted );
$assert( 'authorization header is redacted', '[redacted]' === ( $redacted['headers']['Authorization'] ?? null ) );
$assert( 'token-like env key is redacted', '[redacted]' === ( $redacted['env']['API_TOKEN'] ?? null ) );
$assert( 'non-secret env key remains visible', '1' === ( $redacted['env']['PUBLIC_FLAG'] ?? null ) );
$assert( 'redacted output does not include bearer value', ! str_contains( $encoded, 'should-not-leak' ) );

echo "\n[3] Missing connector reports failed state without fake runtime\n";
$missing = datamachine_mcp_connect( 'context-a8c' );
$state   = datamachine_mcp_state( 'context-a8c' );
$assert( 'missing connector returns WP_Error', is_wp_error( $missing ) && 'datamachine_mcp_connector_missing' === $missing->get_error_code() );
$assert( 'failed state is tracked', 'failed' === ( $state['status'] ?? null ) );
$assert( 'state config is redacted', '[redacted]' === ( $state['config']['headers']['Authorization'] ?? null ) );

echo "\n[4] Connector hook provides reusable connection and cleanup lifecycle\n";
add_filter(
	'datamachine_mcp_connector',
	static function ( $connector, array $config, array $context ) {
		unset( $connector, $context );
		if ( 'context-a8c' !== ( $config['server_id'] ?? null ) ) {
			return null;
		}

		return static fn() => new FixtureMCPConnection();
	},
	10,
	3
);

MCPConnectionManager::reset();
$first  = datamachine_mcp_connect( 'context-a8c' );
$second = datamachine_mcp_connect( 'context-a8c' );
$assert( 'connector returns fixture connection', $first instanceof FixtureMCPConnection );
$assert( 'connection is reused within the request', $first === $second );
$assert( 'connected state is tracked', 'connected' === ( datamachine_mcp_state( 'context-a8c' )['status'] ?? null ) );

$restarted = datamachine_mcp_restart( 'context-a8c' );
$assert( 'restart returns a fresh connection', $restarted instanceof FixtureMCPConnection && $restarted !== $first );
$assert( 'restart closes previous connection', 1 === FixtureMCPConnection::$closed );

datamachine_mcp_cleanup( 'context-a8c' );
$assert( 'cleanup closes active connection', 2 === FixtureMCPConnection::$closed );
$assert( 'cleanup tracks stopped state', 'stopped' === ( datamachine_mcp_state( 'context-a8c' )['status'] ?? null ) );

echo "\n=== Results ===\n";
echo "Passed: {$passes}\n";
echo "Failed: {$fails}\n";

if ( $fails > 0 ) {
	exit( 1 );
}
