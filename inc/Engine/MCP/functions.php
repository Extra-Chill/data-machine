<?php
/**
 * MCP registry and connection helpers.
 *
 * @package DataMachine\Engine\MCP
 */

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\MCP\MCPConnectionManager;
use DataMachine\Engine\MCP\MCPServerRegistry;

if ( ! function_exists( 'datamachine_mcp_register_server' ) ) {
	/**
	 * Register an MCP server for the current request.
	 *
	 * @param string $server_id Server id.
	 * @param array  $config    Server config.
	 * @return bool
	 */
	function datamachine_mcp_register_server( string $server_id, array $config ): bool {
		return MCPServerRegistry::register( $server_id, $config );
	}
}

if ( ! function_exists( 'datamachine_mcp_servers' ) ) {
	/**
	 * Return registered MCP servers.
	 *
	 * @param bool $redacted Whether to redact sensitive config fields.
	 * @return array<string,array>
	 */
	function datamachine_mcp_servers( bool $redacted = true ): array {
		return MCPServerRegistry::all( $redacted );
	}
}

if ( ! function_exists( 'datamachine_mcp_server' ) ) {
	/**
	 * Return one MCP server config.
	 *
	 * @param string $server_id Server id.
	 * @param bool   $redacted  Whether to redact sensitive config fields.
	 * @return array|null
	 */
	function datamachine_mcp_server( string $server_id, bool $redacted = true ): ?array {
		return MCPServerRegistry::get( $server_id, $redacted );
	}
}

if ( ! function_exists( 'datamachine_mcp_connect' ) ) {
	/**
	 * Connect to a registered MCP server.
	 *
	 * @param string $server_id Server id.
	 * @param array  $context   Caller context.
	 * @return mixed|WP_Error
	 */
	function datamachine_mcp_connect( string $server_id, array $context = array() ) {
		return MCPConnectionManager::connect( $server_id, $context );
	}
}

if ( ! function_exists( 'datamachine_mcp_restart' ) ) {
	/**
	 * Restart a registered MCP server connection.
	 *
	 * @param string $server_id Server id.
	 * @param array  $context   Caller context.
	 * @return mixed|WP_Error
	 */
	function datamachine_mcp_restart( string $server_id, array $context = array() ) {
		return MCPConnectionManager::restart( $server_id, $context );
	}
}

if ( ! function_exists( 'datamachine_mcp_cleanup' ) ) {
	/**
	 * Cleanup one MCP connection or all active MCP connections.
	 *
	 * @param string|null $server_id Optional server id.
	 * @return void
	 */
	function datamachine_mcp_cleanup( ?string $server_id = null ): void {
		MCPConnectionManager::cleanup( $server_id );
	}
}

if ( ! function_exists( 'datamachine_mcp_state' ) ) {
	/**
	 * Return redacted MCP connection state.
	 *
	 * @param string|null $server_id Optional server id.
	 * @return array|null
	 */
	function datamachine_mcp_state( ?string $server_id = null ): ?array {
		return MCPConnectionManager::state( $server_id );
	}
}
