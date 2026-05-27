<?php
/**
 * Pure-PHP smoke test for the generic content-format conversion filter.
 *
 * Run with: php tests/content-format-convert-filter-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

function assert_content_format_filter( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}

	echo "  FAIL: {$name}\n";
	++$failed;
}

$GLOBALS['__content_format_filter_hooks'] = array();

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['__content_format_filter_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['__content_format_filter_hooks'][ $hook ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['__content_format_filter_hooks'][ $hook ] );
	foreach ( $GLOBALS['__content_format_filter_hooks'][ $hook ] as $callbacks ) {
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

function bfb_convert( string $content, string $from, string $to ) {
	return "[{$from}:{$to}]{$content}";
}

class WP_Error {
}

require_once dirname( __DIR__ ) . '/inc/Core/Content/ContentFormat.php';

use DataMachine\Core\Content\ContentFormat;

ContentFormat::register();

$filtered = apply_filters(
	'datamachine_content_format_convert',
	null,
	'# Hello',
	'markdown',
	'blocks',
	array( 'post_type' => 'page' )
);

assert_content_format_filter( 'generic-filter-converts-with-bfb', '[markdown:blocks]# Hello' === $filtered );

add_filter(
	'datamachine_content_format_convert',
	static function ( $converted, string $content, string $from, string $to ): string {
		unset( $converted, $content, $from, $to );
		return 'custom-conversion';
	},
	5,
	4
);

$custom = ContentFormat::convert( '# Custom', 'markdown', 'blocks' );
assert_content_format_filter( 'earlier-filter-can-override-default-conversion', 'custom-conversion' === $custom );

echo "\nContent format conversion filter smoke: {$total} assertions, {$failed} failures.\n";

exit( min( 1, $failed ) );
