<?php
/**
 * Isolated registration harness: capture the real public email ability schemas.
 *
 * Loads the real AbilityRegistration and EmailAbilities with just enough
 * WordPress stubs to run registration, recording each input schema in
 * $GLOBALS['datamachine_email_schemas'].
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation passthrough.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	/**
	 * Report the abilities init action as running so registration happens immediately.
	 *
	 * @param string $hook_name Hook name.
	 * @return bool
	 */
	function doing_action( string $hook_name ): bool {
		return 'wp_abilities_api_init' === $hook_name;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Capture registered ability input schemas.
	 *
	 * @param string $name       Ability name.
	 * @param array  $definition Ability definition.
	 */
	function wp_register_ability( string $name, array $definition ): void {
		$GLOBALS['datamachine_email_schemas'][ $name ] = $definition['input_schema'];
	}
}

require_once ABSPATH . 'inc/Abilities/AbilityRegistration.php';
require_once ABSPATH . 'inc/Abilities/Email/EmailAbilities.php';
new \DataMachine\Abilities\Email\EmailAbilities();
