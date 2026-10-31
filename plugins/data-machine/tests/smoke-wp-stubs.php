<?php
/**
 * Shared WordPress API stubs for pure-PHP smoke tests.
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

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['smoke_wp_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		$priorities = $GLOBALS['smoke_wp_filters'][ $hook ] ?? array();
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as list( $callback, $accepted_args ) ) {
				$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		// No persistent option store in the default smoke environment.
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
	class WP_Abilities_Registry {
		public static function get_instance() {
			static $instance;
			return $instance ??= new self();
		}

		public function get_registered() {
			return array();
		}
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
