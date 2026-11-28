<?php
/**
 * WP_Error stub for BaseAuthProviderEncryptionTest.
 *
 * Kept separate from encryption-test-stubs.php so that file remains purely
 * global function stubs, per Universal.Files.SeparateFunctionsFromOO.
 *
 * Must be loaded AFTER bootstrap-unit.php and BEFORE the test class.
 *
 * @package DataMachine\Tests\Unit\Core\OAuth
 */

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
