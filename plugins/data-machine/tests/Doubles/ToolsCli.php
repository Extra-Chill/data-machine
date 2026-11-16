<?php
/** Output double for standalone tools command tests. */
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
class WP_CLI {
	public static array $messages = array();

	public static function log( $message ): void {
		self::$messages[] = $message;
	}

	public static function success( $message ): void {
		self::$messages[] = $message;
	}

	public static function error( $message ): void {
		throw new RuntimeException( $message );
	}
}
