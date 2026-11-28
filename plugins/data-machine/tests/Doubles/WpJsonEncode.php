<?php
/**
 * Global wp_json_encode() double for standalone unit tests.
 *
 * @package DataMachine\Tests
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal wp_json_encode() stand-in.
	 *
	 * @param mixed $value Value to encode.
	 * @param int   $flags json_encode() flags.
	 * @return string|false
	 */
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
