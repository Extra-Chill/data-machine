<?php
/**
 * Shared pure-PHP harness for Agents API module smokes.
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/agents-api-loader.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['__agents_api_smoke_actions'] = array();
$GLOBALS['__agents_api_smoke_filters'] = array();
$GLOBALS['__agents_api_smoke_wrong']   = array();
$GLOBALS['__agents_api_smoke_current'] = array();
$GLOBALS['__agents_api_smoke_done']    = array();

function sanitize_title( string $value ): string {
	$value = strtolower( $value );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( (string) $value, '-' );
}

function sanitize_file_name( string $value ): string {
	return basename( $value );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $accepted_args );
	$GLOBALS['__agents_api_smoke_actions'][ $hook ][ $priority ][] = $callback;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['__agents_api_smoke_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['__agents_api_smoke_filters'][ $hook ] ?? array();
	ksort( $callbacks );

	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $registration ) {
			$value = call_user_func_array( $registration[0], array_slice( array_merge( array( $value ), $args ), 0, $registration[1] ) );
		}
	}

	return $value;
}

function do_action( string $hook, ...$args ): void {
	$GLOBALS['__agents_api_smoke_current'][] = $hook;
	$callbacks = $GLOBALS['__agents_api_smoke_actions'][ $hook ] ?? array();
	ksort( $callbacks );

	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}

	array_pop( $GLOBALS['__agents_api_smoke_current'] );
	$GLOBALS['__agents_api_smoke_done'][ $hook ] = ( $GLOBALS['__agents_api_smoke_done'][ $hook ] ?? 0 ) + 1;
}

function doing_action( string $hook ): bool {
	return in_array( $hook, $GLOBALS['__agents_api_smoke_current'], true );
}

function did_action( string $hook ): int {
	return (int) ( $GLOBALS['__agents_api_smoke_done'][ $hook ] ?? 0 );
}

function esc_html( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function _x( string $text, string $context, string $domain = 'default' ): string {
	unset( $context, $domain );
	return $text;
}

function post_type_exists( string $post_type ): bool {
	return isset( $GLOBALS['__agents_api_smoke_post_types'][ $post_type ] );
}

function register_post_type( string $post_type, array $args = array() ): object {
	$GLOBALS['__agents_api_smoke_post_types'][ $post_type ] = $args;
	return (object) array( 'name' => $post_type );
}

function taxonomy_exists( string $taxonomy ): bool {
	return isset( $GLOBALS['__agents_api_smoke_taxonomies'][ $taxonomy ] );
}

function register_taxonomy( string $taxonomy, $object_type, array $args = array() ): object {
	$GLOBALS['__agents_api_smoke_taxonomies'][ $taxonomy ] = array(
		'object_type' => $object_type,
		'args'        => $args,
	);
	return (object) array( 'name' => $taxonomy );
}

function _doing_it_wrong( string $function_name, string $message, string $version ): void {
	$GLOBALS['__agents_api_smoke_wrong'][] = array(
		'function' => $function_name,
		'message'  => $message,
		'version'  => $version,
	);
}

function agents_api_smoke_assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

function agents_api_smoke_require_module(): void {
	datamachine_tests_require_agents_api();
}

function agents_api_smoke_finish( string $label, array $failures, int $passes ): void {
	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " {$label} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} {$label} assertions passed.\n";
}
