<?php
/**
 * Smoke test for JSON-safe /handlers responses.
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/smoke-wp-stubs.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

$GLOBALS['handlers_api_smoke_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['handlers_api_smoke_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['handlers_api_smoke_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['handlers_api_smoke_filters'][ $hook ], SORT_NUMERIC );
		foreach ( $GLOBALS['handlers_api_smoke_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted_args ] ) {
				$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}

		return $value;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function get_param( string $key ) {
			unset( $key );
			return null;
		}
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) {
		return new class( $data ) {
			private $data;

			public function __construct( $data ) {
				$this->data = $data;
			}

			public function get_data() {
				return $this->data;
			}
		};
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ): string {
		return (string) json_encode( $value );
	}
}

require_once __DIR__ . '/../inc/Abilities/HandlerAbilities.php';
require_once __DIR__ . '/../inc/Abilities/AuthAbilities.php';
require_once __DIR__ . '/../inc/Api/Handlers.php';

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Api\Handlers;

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

$warnings = array();
set_error_handler(
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		$warnings[] = array( $errno, $errstr );
		return true;
	}
);

add_filter(
	'datamachine_handlers',
	static function ( array $handlers ): array {
		$handlers['minimal'] = array(
			'type'  => 'fetch',
			'label' => 'Minimal Handler',
		);
		$handlers['not_array'] = 'bad handler registration';
		return $handlers;
	},
	10,
	2
);

HandlerAbilities::clearCache();
AuthAbilities::clearCache();

$response = Handlers::handle_get_handlers( new WP_REST_Request() );
restore_error_handler();

$data     = $response->get_data();
$handlers = $data['data'] ?? array();

$assert( 'response succeeds', true === ( $data['success'] ?? false ) );
$assert( 'minimal handler remains present', isset( $handlers['minimal'] ) );
$assert( 'missing requires_auth defaults false', false === ( $handlers['minimal']['requires_auth'] ?? null ) );
$assert( 'missing auth_provider_key defaults null', array_key_exists( 'auth_provider_key', $handlers['minimal'] ) && null === $handlers['minimal']['auth_provider_key'] );
$assert( 'malformed non-array handler is omitted', ! isset( $handlers['not_array'] ) );
$assert( 'handler enrichment emitted no PHP warnings', array() === $warnings );
$assert( 'response is JSON encodable', '' !== wp_json_encode( $data ) );

echo "\n=== Results ===\n";
echo "Passed: {$passes}\n";
echo "Failed: {$fails}\n";

if ( $fails > 0 ) {
	exit( 1 );
}
