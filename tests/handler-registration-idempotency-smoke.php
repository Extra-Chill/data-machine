<?php
/**
 * Smoke test for handler registration idempotency.
 *
 * Run with: php tests/handler-registration-idempotency-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/smoke-wp-stubs.php';

$GLOBALS['handler_registration_idempotency_filters'] = array();
$GLOBALS['handler_registration_idempotency_actions'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['handler_registration_idempotency_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['handler_registration_idempotency_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['handler_registration_idempotency_filters'][ $hook ], SORT_NUMERIC );
		foreach ( $GLOBALS['handler_registration_idempotency_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted_args ] ) {
				$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['handler_registration_idempotency_actions'][ $hook ][] = $args;
	}
}

require_once __DIR__ . '/../inc/Core/Steps/HandlerRegistrationTrait.php';

use DataMachine\Core\Steps\HandlerRegistrationTrait;

class HandlerRegistrationIdempotencySmokeAuth {}
class HandlerRegistrationIdempotencySmokeSettings {}

class HandlerRegistrationIdempotencySmokeRegistration {
	use HandlerRegistrationTrait;

	public static function register(): void {
		self::registerHandler(
			'smoke_source',
			'fetch',
			self::class,
			'Smoke Source',
			'Smoke source handler',
			true,
			HandlerRegistrationIdempotencySmokeAuth::class,
			HandlerRegistrationIdempotencySmokeSettings::class,
			static fn(): array => array(),
			'smoke_auth',
			array( 'example' => true ),
			static fn(): bool => true
		);
	}
}

$passes = 0;
$fails  = 0;

$assert = static function ( string $label, bool $condition ) use ( &$passes, &$fails ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		++$passes;
		return;
	}

	echo "FAIL: {$label}\n";
	++$fails;
};

$count_filter_callbacks = static function ( string $hook ): int {
	$count = 0;

	foreach ( $GLOBALS['handler_registration_idempotency_filters'][ $hook ] ?? array() as $callbacks ) {
		$count += count( $callbacks );
	}

	return $count;
};

echo "\n[1] Repeated construction registers each handler hook once\n";
HandlerRegistrationIdempotencySmokeRegistration::register();
HandlerRegistrationIdempotencySmokeRegistration::register();

$assert( 'handler registry filter is installed once', 1 === $count_filter_callbacks( 'datamachine_handlers' ) );
$assert( 'auth provider filter is installed once', 1 === $count_filter_callbacks( 'datamachine_auth_providers' ) );
$assert( 'settings filter is installed once', 1 === $count_filter_callbacks( 'datamachine_handler_settings' ) );
$assert( 'tools filter is installed once', 1 === $count_filter_callbacks( 'datamachine_tools' ) );
$assert( 'validation filter is installed once', 1 === $count_filter_callbacks( 'datamachine_validate_handler_config' ) );
$assert( 'registration action fires once', 1 === count( $GLOBALS['handler_registration_idempotency_actions']['datamachine_handler_registered'] ?? array() ) );

echo "\n[2] Registered handler metadata remains available\n";
$handlers = apply_filters( 'datamachine_handlers', array(), 'fetch' );
$assert( 'handler metadata is keyed by slug', isset( $handlers['smoke_source'] ) );
$assert( 'handler metadata preserves type', 'fetch' === ( $handlers['smoke_source']['type'] ?? null ) );
$assert( 'handler metadata preserves auth provider key', 'smoke_auth' === ( $handlers['smoke_source']['auth_provider_key'] ?? null ) );

echo "\n=== Results ===\n";
echo "Passed: {$passes}\n";
echo "Failed: {$fails}\n";

if ( $fails > 0 ) {
	exit( 1 );
}
