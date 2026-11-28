<?php
/**
 * REST coordinate sanitizer contract smoke test.
 *
 * Verifies that all coordinate route sanitizers accept the three arguments
 * supplied by WP_REST_Request::sanitize_params() while preserving float casts.
 *
 * Run directly:
 *   php tests/rest-route-coordinate-sanitizer-smoke.php
 *
 * @package DataMachineEvents\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['dme_registered_routes'] = array();

	function register_rest_route( string $namespace, string $route, array $args ): void {
		$GLOBALS['dme_registered_routes'][ $namespace . $route ] = $args;
	}

	function add_action(): void {}

	function sanitize_text_field( $value ) {
		return $value;
	}

	function sanitize_key( $value ) {
		return $value;
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}

	function rest_sanitize_boolean( $value ): bool {
		return (bool) $value;
	}
}

namespace DataMachineEvents\Api\Controllers {
	final class Calendar {}
	final class Venues {}
	final class EventIcs {}
	final class Filters {}
	final class Geocoding {}
	final class VenueMap {}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Api/Routes.php';

	\DataMachineEvents\Api\register_routes();

	$pass = 0;
	$fail = 0;

	function check( string $label, bool $condition ): void {
		global $pass, $fail;
		if ( $condition ) {
			++$pass;
		} else {
			++$fail;
			echo "FAIL: {$label}\n";
		}
	}

	$expected = array(
		'/events/venues'  => array( 'lat', 'lng' ),
		'/events/filters' => array( 'lat', 'lng' ),
	);

	foreach ( $expected as $route => $fields ) {
		$args = $GLOBALS['dme_registered_routes'][ 'datamachine/v1' . $route ]['args'] ?? array();
		foreach ( $fields as $field ) {
			$callback = $args[ $field ]['sanitize_callback'] ?? null;
			check( "{$route}.{$field} has callable sanitizer", is_callable( $callback ) );
			if ( is_callable( $callback ) ) {
				$result = $callback( '12.3456', new \stdClass(), $field );
				check( "{$route}.{$field} preserves float conversion", is_float( $result ) && 12.3456 === $result );
			}
		}
	}

	printf( "\n%d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
