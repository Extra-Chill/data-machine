<?php
/**
 * Network Settings Accessor
 *
 * Centralized access point for datamachine_network_settings site option.
 * Stores network-wide defaults that individual sites can override via
 * PluginSettings. On single-site installs, get_site_option() behaves
 * identically to get_option(), so this is transparent.
 *
 * @package DataMachine\Core
 * @since 0.32.0
 */

namespace DataMachine\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NetworkSettings {

	/**
	 * Option name for network-wide settings.
	 */
	const OPTION_NAME = 'datamachine_network_settings';

	/**
	 * Keys that are valid at the network level.
	 * Only these keys can be stored/retrieved from network settings.
	 */
	const NETWORK_KEYS = array(
		'default_provider',
		'default_model',
		'agent_models',
	);

	private static ?array $cache = null;

	/**
	 * Get all network settings.
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = get_site_option( self::OPTION_NAME, array() );
		}
		return self::$cache;
	}

	/**
	 * Get a specific network setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if key not found.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		if ( ! in_array( $key, self::NETWORK_KEYS, true ) ) {
			return $default;
		}

		$settings = self::all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Update network settings (partial merge).
	 *
	 * Only keys listed in NETWORK_KEYS are accepted. Other keys are silently
	 * ignored so callers can safely pass a mixed bag of settings.
	 *
	 * @param array $values Key-value pairs to merge into network settings.
	 * @return bool True on success.
	 */
	public static function update( array $values ): bool {
		$current  = self::all();
		$filtered = array();

		foreach ( $values as $key => $value ) {
			if ( in_array( $key, self::NETWORK_KEYS, true ) ) {
				$filtered[ $key ] = $value;
			}
		}

		if ( empty( $filtered ) ) {
			return false;
		}

		$merged = array_merge( $current, $filtered );
		$result = update_site_option( self::OPTION_NAME, $merged );

		self::$cache = $merged;

		return $result;
	}

	/**
	 * Check if a key is a network-level setting.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function isNetworkKey( string $key ): bool {
		return in_array( $key, self::NETWORK_KEYS, true );
	}

	/**
	 * Clear the settings cache.
	 *
	 * @return void
	 */
	public static function clearCache(): void {
		self::$cache = null;
	}
}
