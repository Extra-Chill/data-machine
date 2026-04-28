<?php
/**
 * Pure-PHP smoke test for ContentFormat conversion policy.
 *
 * Run with: php tests/content-format-helper-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

function assert_content_format( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

$GLOBALS['__content_format_filters'] = array();

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['__content_format_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['__content_format_filters'][ $hook ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['__content_format_filters'][ $hook ] );
	foreach ( $GLOBALS['__content_format_filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $registered_callback ) {
			list( $callback, $accepted_args ) = $registered_callback;
			$value                            = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function sanitize_key( $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

class WP_Error {

	private string $message;

	public function __construct( string $code = '', string $message = '' ) {
		unset( $code );
		$this->message = $message;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/Content/ContentFormat.php';

use DataMachine\Core\Content\ContentFormat;

assert_content_format( 'default-format-is-blocks', 'blocks' === ContentFormat::storedFormat( 'post' ) );

add_filter(
	'datamachine_post_content_format',
	static function ( string $format, string $post_type ): string {
		return 'wiki' === $post_type ? 'markdown' : $format;
	},
	10,
	2
);

assert_content_format( 'filtered-format-is-markdown', 'markdown' === ContentFormat::storedFormat( 'wiki' ) );
assert_content_format( 'same-format-is-no-op-without-bfb', 'Hello' === ContentFormat::convert( 'Hello', 'markdown', 'markdown' ) );

$missing = ContentFormat::convert( '# Hello', 'markdown', 'blocks' );
assert_content_format( 'missing-bfb-returns-wp-error', is_wp_error( $missing ) );
$missing_message = is_wp_error( $missing ) ? $missing->get_error_message() : '';
assert_content_format( 'missing-bfb-error-is-clear', false !== strpos( $missing_message, 'Block Format Bridge is required' ) );

echo "\nContentFormat helper smoke: {$total} assertions, {$failed} failures.\n";

exit( min( 1, $failed ) );
