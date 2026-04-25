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

use DataMachine\Core\Database\Agents\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginSettings {

	public const DEFAULT_MAX_TURNS = 25;

	private static ?array $cache            = null;
	private static array $agent_model_cache = array();

	/**
	 * Get default queue tuning values.
	 *
	 * Five knobs across two layers:
	 *
	 *   Producer side (BatchScheduler — how DM creates child jobs):
	 *     - chunk_size:  child jobs created per scheduling cycle
	 *     - chunk_delay: seconds between scheduling cycles
	 *
	 *   Consumer side (Action Scheduler — how it drains them):
	 *     - concurrent_batches: parallel AS batches
	 *     - batch_size:         actions claimed per AS batch
	 *     - time_limit:         seconds per AS batch
	 *
	 * @return array{concurrent_batches:int,batch_size:int,time_limit:int,chunk_size:int,chunk_delay:int}
	 */
	public static function getDefaultQueueTuning(): array {
		return array(
			'concurrent_batches' => 3,
			'batch_size'         => 25,
			'time_limit'         => 60,
			'chunk_size'         => 10,
			'chunk_delay'        => 30,
		);
	}

	/**
	 * Get centralized plugin defaults used by backend and admin UI.
	 *
	 * @return array{max_turns:int,queue_tuning:array{concurrent_batches:int,batch_size:int,time_limit:int,chunk_size:int,chunk_delay:int}}
	 */
	public static function getDefaults(): array {
		return array(
			'max_turns'    => self::DEFAULT_MAX_TURNS,
			'queue_tuning' => self::getDefaultQueueTuning(),
		);
	}

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
	public static function get( string $key, mixed $default_value = null ): mixed {
		$settings = self::all();
		return $settings[ $key ] ?? $default_value;
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
	public static function resolve( string $key, mixed $default_value = null ): mixed {
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

		return $default_value;
	}

	/**
	 * Get provider and model for a specific execution mode.
	 *
	 * Resolution order:
	 * 1. Per-site mode-specific override from mode_models setting
	 * 2. Network mode-specific override from network mode_models
	 * 3. Per-site global default_provider / default_model
	 * 4. Network global default_provider / default_model
	 * 5. Empty strings
	 *
	 * @param string $mode Execution mode: 'chat', 'pipeline', 'system'.
	 * @return array{ provider: string, model: string }
	 */
	public static function getModelForMode( string $mode ): array {
		// Step 1: Check per-site mode-specific override.
		$site_mode_models = self::get( 'mode_models', array() );
		$site_mode_config = $site_mode_models[ $mode ] ?? array();

		$provider = ! empty( $site_mode_config['provider'] ) ? $site_mode_config['provider'] : '';
		$model    = ! empty( $site_mode_config['model'] ) ? $site_mode_config['model'] : '';

		// Step 2: Fall back to network mode-specific override.
		if ( empty( $provider ) || empty( $model ) ) {
			$network_mode_models = NetworkSettings::get( 'mode_models', array() );
			$network_mode_config = $network_mode_models[ $mode ] ?? array();

			if ( empty( $provider ) && ! empty( $network_mode_config['provider'] ) ) {
				$provider = $network_mode_config['provider'];
			}
			if ( empty( $model ) && ! empty( $network_mode_config['model'] ) ) {
				$model = $network_mode_config['model'];
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
	 * Resolve provider/model for an agent within an execution mode.
	 *
	 * Resolution order:
	 * 1. agent_config.mode_models[mode]
	 * 2. agent_config.default_provider/default_model
	 * 3. site/network mode-specific overrides
	 * 4. site/network global defaults
	 *
	 * @param int|null $agent_id Agent ID or null/0 for no agent-specific override.
	 * @param string   $mode     Execution mode.
	 * @return array{ provider: string, model: string }
	 */
	public static function resolveModelForAgentMode( ?int $agent_id, string $mode ): array {
		$agent_id  = (int) $agent_id;
		$cache_key = $agent_id . ':' . $mode;

		if ( isset( self::$agent_model_cache[ $cache_key ] ) ) {
			return self::$agent_model_cache[ $cache_key ];
		}

		if ( $agent_id > 0 ) {
			$agents_repo = new Agents();
			$agent       = $agents_repo->get_agent( $agent_id );
			$config      = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();

			$mode_models = is_array( $config['mode_models'] ?? null ) ? $config['mode_models'] : array();
			$mode_config = is_array( $mode_models[ $mode ] ?? null ) ? $mode_models[ $mode ] : array();

			$provider = sanitize_text_field( $mode_config['provider'] ?? '' );
			$model    = sanitize_text_field( $mode_config['model'] ?? '' );

			if ( empty( $provider ) ) {
				$provider = sanitize_text_field( $config['default_provider'] ?? '' );
			}

			if ( empty( $model ) ) {
				$model = sanitize_text_field( $config['default_model'] ?? '' );
			}

			if ( ! empty( $provider ) && ! empty( $model ) ) {
				self::$agent_model_cache[ $cache_key ] = array(
					'provider' => $provider,
					'model'    => $model,
				);

				return self::$agent_model_cache[ $cache_key ];
			}
		}

		self::$agent_model_cache[ $cache_key ] = self::getModelForMode( $mode );

		return self::$agent_model_cache[ $cache_key ];
	}

	/**
	 * Get the list of known execution modes.
	 *
	 * Delegates to AgentModeRegistry which provides the canonical list of
	 * registered modes. Core modes register in bootstrap.php;
	 * extensions register via the `datamachine_agent_modes` action.
	 *
	 * @since 0.68.0 Renamed from getContexts(). Delegates to AgentModeRegistry.
	 *
	 * @return array Array of mode definitions with id, label, and description.
	 */
	public static function getAgentModes(): array {
		return \DataMachine\Engine\AI\AgentModeRegistry::get_for_settings();
	}

	/**
	 * Clear the settings cache.
	 *
	 * @return void
	 */
	public static function clearCache(): void {
		self::$cache             = null;
		self::$agent_model_cache = array();
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
