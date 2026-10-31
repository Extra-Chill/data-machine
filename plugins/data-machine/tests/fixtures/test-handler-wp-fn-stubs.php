<?php
/**
 * Isolated WordPress function doubles for the pure-PHP test-handler smoke.
 *
 * These doubles must never shadow real global WordPress functions.
 */

namespace DataMachine\Abilities\Handler {
	if ( ! \function_exists( '\\wp_json_encode' ) && ! \function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}

	if ( ! \function_exists( '\\wp_generate_uuid4' ) && ! \function_exists( __NAMESPACE__ . '\\wp_generate_uuid4' ) ) {
		function wp_generate_uuid4(): string {
			return '00000000-0000-4000-8000-000000000000';
		}
	}
}

namespace DataMachine\Abilities {
	if ( ! \function_exists( '\\doing_action' ) && ! \function_exists( __NAMESPACE__ . '\\doing_action' ) ) {
		function doing_action( string $hook ): bool {
			return false;
		}
	}

	if ( ! \function_exists( '\\did_action' ) && ! \function_exists( __NAMESPACE__ . '\\did_action' ) ) {
		function did_action( string $hook ): int {
			return 0;
		}
	}

	if ( ! \function_exists( '\\add_action' ) && ! \function_exists( __NAMESPACE__ . '\\add_action' ) ) {
		function add_action( string $hook, callable $callback ): void {
		}
	}

	if ( ! \function_exists( '\\apply_filters' ) && ! \function_exists( __NAMESPACE__ . '\\apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			if ( 'datamachine_handlers' === $hook ) {
				return $GLOBALS['datamachine_test_handlers'] ?? $value;
			}
			return $value;
		}
	}
}
