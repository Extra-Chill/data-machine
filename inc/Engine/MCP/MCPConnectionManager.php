<?php
/**
 * MCP connection manager.
 *
 * @package DataMachine\Engine\MCP
 */

namespace DataMachine\Engine\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Generic MCP connection lifecycle coordinator.
 */
final class MCPConnectionManager {

	public const STATE_REGISTERED = 'registered';
	public const STATE_CONNECTING = 'connecting';
	public const STATE_CONNECTED  = 'connected';
	public const STATE_FAILED     = 'failed';
	public const STATE_STOPPED    = 'stopped';
	public const STATE_RESTARTING = 'restarting';

	/**
	 * Runtime connection handles keyed by server id.
	 *
	 * @var array<string,mixed>
	 */
	private static array $connections = array();

	/**
	 * Runtime state keyed by server id.
	 *
	 * @var array<string,array>
	 */
	private static array $state = array();

	/**
	 * Connect to a registered server through the installed connector hook.
	 *
	 * Data Machine core intentionally does not shell out or instantiate a concrete
	 * MCP transport here. Runtime adapters provide the client through the
	 * `datamachine_mcp_connector` filter.
	 *
	 * @param string $server_id Server id.
	 * @param array  $context   Caller context.
	 * @return mixed|\WP_Error
	 */
	public static function connect( string $server_id, array $context = array() ) {
		$config = MCPServerRegistry::get( $server_id );
		if ( null === $config ) {
			$error = self::error( 'datamachine_mcp_server_not_registered', sprintf( 'MCP server "%s" is not registered.', $server_id ) );
			self::set_state( $server_id, self::STATE_FAILED, $error );
			return $error;
		}

		$server_id = (string) $config['server_id'];
		if ( isset( self::$connections[ $server_id ] ) ) {
			return self::$connections[ $server_id ];
		}

		self::set_state( $server_id, self::STATE_CONNECTING );

		$connector = function_exists( 'apply_filters' ) ? apply_filters( 'datamachine_mcp_connector', null, $config, $context ) : null;
		if ( null === $connector ) {
			$error = self::error( 'datamachine_mcp_connector_missing', sprintf( 'No MCP connector is available for server "%s".', $server_id ) );
			self::set_state( $server_id, self::STATE_FAILED, $error );
			return $error;
		}

		$connection = self::connect_with( $connector, $config, $context );
		if ( self::is_error( $connection ) ) {
			self::set_state( $server_id, self::STATE_FAILED, $connection );
			return $connection;
		}

		self::$connections[ $server_id ] = $connection;
		self::set_state( $server_id, self::STATE_CONNECTED );

		return $connection;
	}

	/**
	 * Restart a server connection by cleaning up any existing handle first.
	 *
	 * @param string $server_id Server id.
	 * @param array  $context   Caller context.
	 * @return mixed|\WP_Error
	 */
	public static function restart( string $server_id, array $context = array() ) {
		self::set_state( $server_id, self::STATE_RESTARTING );
		self::cleanup( $server_id );
		return self::connect( $server_id, $context );
	}

	/**
	 * Cleanup one server connection or all active connections.
	 *
	 * @param string|null $server_id Optional server id.
	 * @return void
	 */
	public static function cleanup( ?string $server_id = null ): void {
		$server_ids = null === $server_id ? array_keys( self::$connections ) : array( $server_id );

		foreach ( $server_ids as $id ) {
			if ( isset( self::$connections[ $id ] ) ) {
				self::close_connection( self::$connections[ $id ] );
				unset( self::$connections[ $id ] );
			}

			self::set_state( (string) $id, self::STATE_STOPPED );
		}
	}

	/**
	 * Return redacted lifecycle state.
	 *
	 * @param string|null $server_id Optional server id.
	 * @return array|null
	 */
	public static function state( ?string $server_id = null ): ?array {
		if ( null !== $server_id ) {
			return self::$state[ $server_id ] ?? null;
		}

		return self::$state;
	}

	/**
	 * Reset manager runtime state for tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::cleanup();
		self::$connections = array();
		self::$state       = array();
	}

	/**
	 * Call a connector callback/object.
	 *
	 * @param mixed $connector Connector callback or object.
	 * @param array $config    Server config.
	 * @param array $context   Caller context.
	 * @return mixed|\WP_Error
	 */
	private static function connect_with( $connector, array $config, array $context ) {
		if ( is_callable( $connector ) ) {
			return $connector( $config, $context );
		}

		if ( is_object( $connector ) && method_exists( $connector, 'connect' ) ) {
			return $connector->connect( $config, $context );
		}

		return self::error( 'datamachine_mcp_connector_invalid', 'The MCP connector must be callable or expose a connect() method.' );
	}

	/**
	 * Close a connection handle when it exposes a known cleanup method.
	 *
	 * @param mixed $connection Connection handle.
	 * @return void
	 */
	private static function close_connection( $connection ): void {
		if ( ! is_object( $connection ) ) {
			return;
		}

		foreach ( array( 'cleanup', 'close', 'disconnect', 'stop' ) as $method ) {
			if ( method_exists( $connection, $method ) ) {
				$connection->{$method}();
				return;
			}
		}
	}

	/**
	 * Store lifecycle state without leaking config or connection handles.
	 *
	 * @param string          $server_id Server id.
	 * @param string          $status    Status.
	 * @param \WP_Error|null $error     Optional error.
	 * @return void
	 */
	private static function set_state( string $server_id, string $status, ?\WP_Error $error = null ): void {
		$entry = array(
			'server_id'  => $server_id,
			'status'     => $status,
			'updated_at' => gmdate( 'c' ),
		);

		if ( null !== $error ) {
			$entry['error'] = array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			);
		}

		$config = MCPServerRegistry::get( $server_id, true );
		if ( null !== $config ) {
			$entry['config'] = $config;
		}

		self::$state[ $server_id ] = $entry;
	}

	/**
	 * Whether a value is a WP_Error.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_error( $value ): bool {
		return function_exists( 'is_wp_error' ) ? is_wp_error( $value ) : $value instanceof \WP_Error;
	}

	/**
	 * Build a WP_Error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}
