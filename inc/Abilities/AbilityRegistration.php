<?php
/**
 * Shared Abilities API lifecycle helpers.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps Data Machine ability classes on the public Abilities API lifecycle.
 */
class AbilityRegistration {

	/**
	 * Tracks lazy full-runtime activation for ability execution callbacks.
	 *
	 * @var bool
	 */
	private static bool $runtime_activated = false;

	/**
	 * Register on the Abilities API lifecycle, including after lazy initialization.
	 *
	 * @param callable $register_callback Ability registration callback.
	 */
	public static function on_abilities_api_init( callable $register_callback ): void {
		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
			return;
		}

		if ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
			return;
		}

		/*
		 * Core's public registration helper only checks whether this lifecycle is
		 * in the current hook stack. Run only the newly loaded callback in that
		 * context instead of firing the one-shot action again, which would rerun
		 * every existing registration callback and trigger duplicate notices.
		 */
		global $wp_current_filter;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Match core's doing_action() context without replaying the global action.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$register_callback();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Wrap ability definitions so execution opens Data Machine's full runtime lazily.
	 *
	 * @param array<string, array<string, mixed>> $definitions Ability definitions keyed by ability name.
	 * @return array<string, array<string, mixed>> Wrapped definitions.
	 */
	public static function with_lazy_runtime( array $definitions ): array {
		foreach ( $definitions as $name => $args ) {
			if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
				$args['execute_callback'] = self::runtime_callback( $args['execute_callback'], $name );
			}

			$definitions[ $name ] = $args;
		}

		return $definitions;
	}

	/**
	 * Build an execute callback that activates the full runtime at first execution.
	 *
	 * @param callable $callback Ability execute callback.
	 * @param string   $ability_name Ability name for runtime activation diagnostics.
	 * @return callable Wrapped callback.
	 */
	public static function runtime_callback( callable $callback, string $ability_name ): callable {
		return static function ( ...$args ) use ( $callback, $ability_name ) {
			if ( ! self::$runtime_activated && function_exists( 'datamachine_activate_full_runtime' ) ) {
				self::$runtime_activated = true;
				datamachine_activate_full_runtime( 'ability:' . $ability_name );
			}

			return $callback( ...$args );
		};
	}
}
