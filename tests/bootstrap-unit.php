<?php
/**
 * Bootstrap for unit tests that don't require WordPress.
 *
 * Defines ABSPATH to prevent the early-exit guards in plugin files,
 * then loads the Composer autoloader.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		return 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		// no-op
	}
}

require_once __DIR__ . '/../vendor/autoload.php';
