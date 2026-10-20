<?php
/** Minimal output double for the access command integration test. */
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {}
}

class WP_CLI {
	public static function success( $message ): void {}
	public static function error( $message ): void {
		throw new RuntimeException( $message );
	}
}
