<?php
/**
 * Plugin Settings Accessor
 *
 * Centralized access point for datamachine_settings option (per-site).
 * Provides caching, type-safe getters, and a resolve() cascade that
 * falls back to NetworkSettings for network-level keys.
 *
 * @package DataMachine\Core
 * @since 0.2.10
 */

namespace DataMachine\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginSettings {

	private static ?array $cache = null;

	/**
	 * Get all plugin settings (per-site only, no cascade).
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = get_option( 'datamachine_settings', array() );
		}
		return self::$cache;
	}

	/**
	 * Get a specific per-site setting value (no cascade).
	 *
	 * @param string $key     Setting key
	 * @param mixed  $default Default value if key not found
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$settings = self::all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Resolve a setting with network fallback.
	 *
	 * Resolution order for network-eligible keys:
	 * 1. Per-site value (if non-empty)
	 * 2. Network default (if non-empty)
	 * 3. Provided default
	 *
	 * For non-network keys, behaves identically to get().
	 *
	 * @since 0.32.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if neither site nor network has it.
	 * @return mixed
	 */
	public static function resolve( string $key, mixed $default = null ): mixed {
		// Check per-site first.
		$site_value = self::get( $key );

		if ( self::isNonEmpty( $site_value ) ) {
			return $site_value;
		}

		// Fall back to network for eligible keys.
		if ( NetworkSettings::isNetworkKey( $key ) ) {
			$network_value = NetworkSettings::get( $key );

			if ( self::isNonEmpty( $network_value ) ) {
				return $network_value;
			}
		}

		return $default;
	}

	/**
	 * Get provider and model for a specific agent type.
	 *
	 * Resolution order:
	 * 1. Per-site agent-specific override from agent_models setting
	 * 2. Network agent-specific override from network agent_models
	 * 3. Per-site global default_provider / default_model
	 * 4. Network global default_provider / default_model
	 * 5. Empty strings
	 *
	 * @param string $agent_type Agent type: 'chat', 'pipeline', 'system'.
	 * @return array{ provider: string, model: string }
	 */
	public static function getAgentModel( string $agent_type ): array {
		// Step 1: Check per-site agent-specific override.
		$site_agent_models = self::get( 'agent_models', array() );
		$site_agent_config = $site_agent_models[ $agent_type ] ?? array();

		$provider = ! empty( $site_agent_config['provider'] ) ? $site_agent_config['provider'] : '';
		$model    = ! empty( $site_agent_config['model'] ) ? $site_agent_config['model'] : '';

		// Step 2: Fall back to network agent-specific override.
		if ( empty( $provider ) || empty( $model ) ) {
			$network_agent_models = NetworkSettings::get( 'agent_models', array() );
			$network_agent_config = $network_agent_models[ $agent_type ] ?? array();

			if ( empty( $provider ) && ! empty( $network_agent_config['provider'] ) ) {
				$provider = $network_agent_config['provider'];
			}
			if ( empty( $model ) && ! empty( $network_agent_config['model'] ) ) {
				$model = $network_agent_config['model'];
			}
		}

		// Step 3-4: Fall back to global defaults (site → network).
		if ( empty( $provider ) ) {
			$provider = self::resolve( 'default_provider', '' );
		}
		if ( empty( $model ) ) {
			$model = self::resolve( 'default_model', '' );
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Get the list of known agent types.
	 *
	 * @return array Array of agent type definitions with id, label, and description.
	 */
	public static function getAgentTypes(): array {
		return array(
			array(
				'id'          => 'chat',
				'label'       => __( 'Chat Agent', 'data-machine' ),
				'description' => __( 'Interactive chat conversations. Benefits from capable models for complex reasoning.', 'data-machine' ),
			),
			array(
				'id'          => 'pipeline',
				'label'       => __( 'Pipeline Agent', 'data-machine' ),
				'description' => __( 'Structured workflow execution. Operates within defined steps — efficient models work well.', 'data-machine' ),
			),
			array(
				'id'          => 'system',
				'label'       => __( 'System Agent', 'data-machine' ),
				'description' => __( 'Background tasks like alt text generation and issue creation.', 'data-machine' ),
			),
		);
	}

	/**
	 * Clear the settings cache.
	 *
	 * @return void
	 */
	public static function clearCache(): void {
		self::$cache = null;
	}

	/**
	 * Check if a value is considered "non-empty" for cascade purposes.
	 *
	 * Empty strings and empty arrays are treated as "not set", allowing
	 * the cascade to continue to the next level.
	 *
	 * @since 0.32.0
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	private static function isNonEmpty( mixed $value ): bool {
		if ( null === $value ) {
			return false;
		}
		if ( '' === $value ) {
			return false;
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return false;
		}
		return true;
	}
}
