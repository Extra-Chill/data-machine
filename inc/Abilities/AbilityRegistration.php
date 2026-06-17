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
	 * Register now during wp_abilities_api_init, or hook before it fires.
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
		}
	}
}
