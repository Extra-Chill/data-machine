<?php
/**
 * Iteration Budget Registry
 *
 * Registry for named bounded-iteration budgets. Each registration
 * declares the budget's ceiling resolution rules (default, site-setting
 * key, clamp bounds) so consumers can instantiate a fresh
 * {@see IterationBudget} at runtime without duplicating config-lookup
 * boilerplate.
 *
 * Ceiling resolution order (per {@see create()}):
 *   1. $ceiling_override argument — caller-supplied value, always wins.
 *   2. PluginSettings option named by config['setting'], if set.
 *   3. config['default'].
 * Then clamped to [config['min'], config['max']].
 *
 * Side-effect free: registration mutates a static map, instantiation
 * reads options and returns a new value object. Safe to call from any
 * context. Idempotent across duplicate registrations.
 *
 * @package DataMachine\Engine\AI
 * @since 0.71.0
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\IterationBudget;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

final class IterationBudgetRegistry {

	/**
	 * Registered budget configurations, keyed by budget name.
	 *
	 * @var array<string, array{default:int, min:int, max:int, setting:string}>
	 */
	private static array $registered = array();

	/**
	 * Register a named budget configuration.
	 *
	 * @param string $name   Budget name. Unique per registry.
	 * @param array  $config Configuration:
	 *                       - default (int, required): ceiling when no override or setting.
	 *                       - min (int, optional): floor after clamp. Default 1.
	 *                       - max (int, optional): ceiling after clamp. Default 100.
	 *                       - setting (string, optional): PluginSettings key for per-site override.
	 */
	public static function register( string $name, array $config ): void {
		if ( '' === $name ) {
			return;
		}

		$defaults = array(
			'default' => 10,
			'min'     => 1,
			'max'     => 100,
			'setting' => '',
		);

		self::$registered[ $name ] = array_merge( $defaults, $config );
	}

	/**
	 * Whether a budget is registered.
	 *
	 * @param string $name Budget name.
	 * @return bool
	 */
	public static function is_registered( string $name ): bool {
		return isset( self::$registered[ $name ] );
	}

	/**
	 * Get a registered budget's config (read-only).
	 *
	 * @param string $name Budget name.
	 * @return array|null
	 */
	public static function get_config( string $name ): ?array {
		return self::$registered[ $name ] ?? null;
	}

	/**
	 * All registered budget names.
	 *
	 * @return string[]
	 */
	public static function registered_names(): array {
		return array_keys( self::$registered );
	}

	/**
	 * Create a fresh budget for a named registration.
	 *
	 * Resolves the ceiling through the documented fallback chain and
	 * returns a new {@see IterationBudget} seeded at $current.
	 *
	 * Unregistered names return a permissive budget so callers using a
	 * name-that-might-be-registered don't need conditional logic — the
	 * budget simply uses a safe default. This is intentional: registries
	 * exist to share config, not to gate correctness.
	 *
	 * @param string   $name             Budget name.
	 * @param int      $current          Starting counter value (default 0).
	 * @param int|null $ceiling_override Optional caller-provided ceiling that
	 *                                   bypasses the setting/default lookup but
	 *                                   is still clamped to registered bounds.
	 * @return IterationBudget
	 */
	public static function create(
		string $name,
		int $current = 0,
		?int $ceiling_override = null
	): IterationBudget {
		$config = self::$registered[ $name ] ?? array(
			'default' => 10,
			'min'     => 1,
			'max'     => 100,
			'setting' => '',
		);

		if ( null !== $ceiling_override ) {
			$ceiling = (int) $ceiling_override;
		} elseif ( '' !== $config['setting'] && class_exists( PluginSettings::class ) ) {
			$ceiling = (int) PluginSettings::get( $config['setting'], $config['default'] );
		} else {
			$ceiling = (int) $config['default'];
		}

		$ceiling = max( (int) $config['min'], min( (int) $config['max'], $ceiling ) );

		return new IterationBudget( $name, $ceiling, $current );
	}
}
