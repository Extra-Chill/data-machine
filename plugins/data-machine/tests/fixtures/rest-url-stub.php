<?php
/**
 * Shared rest_url() stub for pure-PHP smoke tests.
 *
 * @package DataMachine\Tests
 */

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
