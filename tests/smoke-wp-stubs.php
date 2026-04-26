<?php
/**
 * Shared WordPress function stubs for pure-PHP smoke tests.
 *
 * Include this file from tests that need tiny WP API stand-ins but do not
 * bootstrap WordPress.
 *
 * @package DataMachine\Tests
 */

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $action = '' ) {
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $action = '' ) {
		return 1; // Pretend wp_abilities_api_init already fired.
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		// no-op
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( ...$args ) {
		// no-op
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
