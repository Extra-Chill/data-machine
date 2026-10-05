<?php
/**
 * MCP server registry.
 *
 * @package DataMachine\Engine\MCP
 */

namespace DataMachine\Engine\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Request-local MCP server registry and config normalizer.
 */
final class MCPServerRegistry {

	/**
	 * Runtime registrations keyed by server id.
	 *
	 * @var array<string,array>|null
	 */
	private static ?array $servers = null;

	/**
	 * Register a server for the current request.
	 *
	 * Persistent registration should happen through the `datamachine_mcp_servers`
	 * filter; this method exists for bootstrap helpers and tests.
	 *
	 * @param string $server_id Server id.
	 * @param array  $config    Server config.
	 * @return bool
	 */
	public static function register( string $server_id, array $config ): bool {
		$server_id = self::normalize_id( $server_id );
		if ( '' === $server_id ) {
			return false;
		}

		self::load();
		self::$servers[ $server_id ] = self::normalize_config( $server_id, $config );
		return true;
	}

	/**
	 * Return all registered servers.
	 *
	 * @param bool $redacted Whether to redact sensitive config fields.
	 * @return array<string,array>
	 */
	public static function all( bool $redacted = false ): array {
		self::load();
		if ( ! $redacted ) {
			return self::$servers;
		}

		$servers = array();
		foreach ( self::$servers as $server_id => $config ) {
			$servers[ $server_id ] = self::redact_config( $config );
		}

		return $servers;
	}

	/**
	 * Get one registered server config.
	 *
	 * @param string $server_id Server id.
	 * @param bool   $redacted  Whether to redact sensitive config fields.
	 * @return array|null
	 */
	public static function get( string $server_id, bool $redacted = false ): ?array {
		self::load();
		$server_id = self::normalize_id( $server_id );
		if ( '' === $server_id || ! isset( self::$servers[ $server_id ] ) ) {
			return null;
		}

		$config = self::$servers[ $server_id ];
		return $redacted ? self::redact_config( $config ) : $config;
	}

	/**
	 * Whether a server is registered.
	 *
	 * @param string $server_id Server id.
	 * @return bool
	 */
	public static function is_registered( string $server_id ): bool {
		return null !== self::get( $server_id );
	}

	/**
	 * Clear cached registrations for tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$servers = null;
	}

	/**
	 * Redact sensitive values recursively.
	 *
	 * @param mixed $value Value to redact.
	 * @param string $key  Current key.
	 * @return mixed
	 */
	public static function redact_value( $value, string $key = '' ) {
		if ( '' !== $key && preg_match( '/(authorization|credential|secret|token|password|api[_-]?key|bearer)/i', $key ) ) {
			return '[redacted]';
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$redacted = array();
		foreach ( $value as $child_key => $child_value ) {
			$redacted[ $child_key ] = self::redact_value( $child_value, (string) $child_key );
		}

		return $redacted;
	}

	/**
	 * Load server definitions from the registry filter once per request.
	 *
	 * @return void
	 */
	private static function load(): void {
		if ( null !== self::$servers ) {
			return;
		}

		$registered    = function_exists( 'apply_filters' ) ? apply_filters( 'datamachine_mcp_servers', array() ) : array();
		self::$servers = array();

		if ( ! is_array( $registered ) ) {
			return;
		}

		foreach ( $registered as $server_id => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$server_id = self::normalize_id( (string) $server_id );
			if ( '' === $server_id ) {
				continue;
			}

			self::$servers[ $server_id ] = self::normalize_config( $server_id, $config );
		}
	}

	/**
	 * Normalize a server config.
	 *
	 * @param string $server_id Server id.
	 * @param array  $config    Raw config.
	 * @return array
	 */
	private static function normalize_config( string $server_id, array $config ): array {
		$config['server_id'] = $server_id;
		$config['transport'] = isset( $config['transport'] ) ? strtolower( (string) $config['transport'] ) : 'http';
		$config['headers']   = is_array( $config['headers'] ?? null ) ? $config['headers'] : array();
		$config['env']       = is_array( $config['env'] ?? null ) ? $config['env'] : array();
		$config['args']      = is_array( $config['args'] ?? null ) ? array_values( $config['args'] ) : array();

		ksort( $config, SORT_STRING );
		return $config;
	}

	/**
	 * Redact a normalized config.
	 *
	 * @param array $config Config.
	 * @return array
	 */
	private static function redact_config( array $config ): array {
		$redacted = self::redact_value( $config );
		return is_array( $redacted ) ? $redacted : array();
	}

	/**
	 * Normalize a server id into the shared registry vocabulary.
	 *
	 * @param string $server_id Raw server id.
	 * @return string
	 */
	private static function normalize_id( string $server_id ): string {
		$server_id = strtolower( trim( $server_id ) );
		if ( '' === $server_id || ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $server_id ) ) {
			return '';
		}

		return $server_id;
	}
}
