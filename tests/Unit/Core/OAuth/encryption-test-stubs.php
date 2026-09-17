<?php
/**
 * Global function stubs for BaseAuthProviderEncryptionTest.
 *
 * Defines wp_salt(), do_action(), and apply_filters() in the global namespace
 * so that BaseAuthProvider's encrypt/decrypt methods work in pure-unit tests.
 *
 * Must be loaded AFTER bootstrap-unit.php and BEFORE the test class.
 *
 * @package DataMachine\Tests\Unit\Core\OAuth
 */

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Stub wp_salt() that delegates to a static method for test control.
	 */
	function wp_salt( string $scheme = 'auth' ): string {
		return DataMachine\Tests\Unit\Core\OAuth\BaseAuthProviderEncryptionTest::getSalt();
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		// no-op
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal translation stub — decryption failure messages use __().
	 */
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub for the pure-unit decryption failure path.
	 */
	class WP_Error {
		private string $code    = '';
		private string $message = '';

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
