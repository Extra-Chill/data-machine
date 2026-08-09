<?php
/** Behavioral smoke test for bounded pathless batch action lookup. */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

function maybe_unserialize( mixed $value ): mixed {
	if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
		return $value;
	}
	return unserialize( trim( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Compatibility fixture.
}

function is_serialized( mixed $value ): bool {
	return is_string( $value ) && (bool) preg_match( '/^[aOsibdN]:/', trim( $value ) );
}

function current_time( string $type, bool $gmt = false ): string {
	return '2026-08-09 12:00:00';
}

final class PathlessRecoveryWpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $responses = array();
	public array $queries = array();
	public array $result_counts = array();

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_results( string $query ): ?array {
		$this->queries[] = $query;
		$response        = array_shift( $this->responses );
		if ( is_callable( $response ) ) {
			$response = $response( $query );
		}
		if ( null === $response ) {
			$this->last_error = 'simulated query failure';
			return null;
		}
		$this->last_error = '';
		$this->result_counts[] = count( $response );
		return $response;
	}
}

function pathless_action( string $args, string $status = 'pending', string $started = '2026-08-09 11:59:00' ): object {
	return (object) array(
		'args'               => $args,
		'status'             => $status,
		'scheduled_date_gmt' => $started,
		'last_attempt_gmt'   => 'in-progress' === $status ? $started : '0000-00-00 00:00:00',
	);
}

function pathless_assert( bool $condition, string $message ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		return;
	}
	++$failures;
	echo "[FAIL] {$message}\n";
}

require_once __DIR__ . '/../inc/Core/ActionScheduler/PathlessBatchRecovery.php';

$failures = 0;
$passes   = 0;
$lookup   = new ReflectionMethod( \DataMachine\Core\ActionScheduler\PathlessBatchRecovery::class, 'hasActiveAction' );
$engine   = array( 'batch_state' => array( 'offset' => 40 ) );

global $wpdb;
$wpdb            = new PathlessRecoveryWpdb();
$wpdb->responses = array( array( pathless_action( '{"parent_job_id":7,"offset":40}' ) ) );
pathless_assert( true === $lookup->invoke( null, 7, $engine, 1 ), 'canonical indexed action is active' );
pathless_assert( 1 === count( $wpdb->queries ), 'canonical match avoids compatibility queries' );
pathless_assert( str_contains( $wpdb->queries[0], 'args = ' ) && str_contains( $wpdb->queries[0], 'LIMIT 101' ), 'canonical query is exact and bounded' );

$wpdb            = new PathlessRecoveryWpdb();
$wpdb->responses = array(
	array(),
	array( pathless_action( '[{"parent_job_id":7,"offset":40}]' ) ),
);
pathless_assert( true === $lookup->invoke( null, 7, $engine, 1 ), 'nested JSON compatibility action is active' );

$wpdb            = new PathlessRecoveryWpdb();
$wpdb->responses = array(
	array(),
	array( pathless_action( serialize( array( array( 'parent_job_id' => 7, 'offset' => 40 ) ) ) ) ),
);
pathless_assert( true === $lookup->invoke( null, 7, $engine, 1 ), 'serialized compatibility action is active' );

$unrelated       = array_fill( 0, 10000, pathless_action( '{"parent_job_id":999,"offset":0}' ) );
$wpdb            = new PathlessRecoveryWpdb();
$wpdb->responses = array(
	array(),
	static fn(): array => array_slice( $unrelated, 0, 101 ),
);
pathless_assert( true === $lookup->invoke( null, 7, $engine, 1 ), 'truncated unrelated population fails closed' );
pathless_assert( 10000 === count( $unrelated ), 'fixture represents a large unrelated action population' );
pathless_assert( 101 === $wpdb->result_counts[1], 'compatibility result cardinality is limit plus sentinel' );
pathless_assert( 2 === count( $wpdb->queries ), 'truncation stops further scheduler queries' );
pathless_assert( str_contains( $wpdb->queries[1], 'ORDER BY scheduled_date_gmt DESC LIMIT 101' ), 'compatibility query follows the hook/status/date index with a bound' );

$wpdb            = new PathlessRecoveryWpdb();
$wpdb->responses = array( null );
pathless_assert( true === $lookup->invoke( null, 7, $engine, 1 ), 'query failure fails closed' );

$wpdb            = new PathlessRecoveryWpdb();
$wpdb->responses = array( array(), array(), array() );
pathless_assert( false === $lookup->invoke( null, 7, $engine, 1 ), 'exhausted exact and compatibility evidence proves absence' );
pathless_assert( 3 === count( $wpdb->queries ), 'absence requires every bounded status query to be exhausted' );

echo "Pathless batch recovery bounds: {$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
