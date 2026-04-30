<?php
/**
 * Shared pure-PHP harness for Agents API module smokes.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['__agents_api_smoke_actions'] = array();
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
	require_once __DIR__ . '/../agents-api/agents-api.php';
}

function agents_api_smoke_finish( string $label, array $failures, int $passes ): void {
	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " {$label} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} {$label} assertions passed.\n";
}
