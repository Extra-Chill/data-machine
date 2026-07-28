<?php
/**
 * Regression coverage for portable index removal.
 *
 * Run with: php tests/base-repository-index-drop-smoke.php
 */

$mode = $argv[1] ?? null;

if ( null === $mode ) {
	$failures = array();
	foreach ( array( 'mysql', 'sqlite' ) as $child_mode ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $child_mode );
		$output  = array();
		exec( $command, $output, $status );
		if ( 0 !== $status ) {
			$failures[] = "FAIL: {$child_mode} child\n" . implode( "\n", $output );
		}
	}

	if ( $failures ) {
		echo implode( "\n", $failures ) . "\n";
		exit( 1 );
	}

	echo "BaseRepository index drop smoke complete: MySQL and SQLite child processes passed.\n";
	exit( 0 );
}

if ( ! in_array( $mode, array( 'mysql', 'sqlite' ), true ) ) {
	echo "FAIL: expected mysql or sqlite mode.\n";
	exit( 1 );
}

if ( 'sqlite' === $mode ) {
	define( 'DATABASE_TYPE', 'sqlite' );
}
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

class wpdb {}

function esc_html( string $text ): string {
	return $text;
}

require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';

use DataMachine\Core\Database\BaseRepository;

class IndexDropSmokeWpdb extends wpdb {
	public $last_error = '';
	public $indexes = array();
	public $prepared = array();
	public $queries = array();
	public $fail_drop = false;

	public function prepare( $query, ...$args ): string {
		$this->prepared[] = array( $query, $args );
		foreach ( $args as $argument ) {
			$query = preg_replace( '/%i/', '`' . str_replace( '`', '``', (string) $argument ) . '`', $query, 1 );
		}
		return $query;
	}

	public function get_results( $query = null, $output = null ): array {
		unset( $query, $output );
		return $this->indexes;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		if ( $this->fail_drop ) {
			$this->last_error = 'driver drop failed';
			return false;
		}
		if ( defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE && str_contains( $query, 'ALTER TABLE' ) && str_contains( $query, 'DROP INDEX' ) ) {
			$this->last_error = 'SQLite ALTER parser was used';
			return false;
		}
		return 1;
	}
}

$failures = array();
$assert = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = "FAIL: {$label}";
	}
};

$reflection = new ReflectionMethod( BaseRepository::class, 'is_sqlite' );
$assert( 0 === $reflection->getNumberOfParameters(), 'is_sqlite retains its no-argument contract' );

$wpdb          = new IndexDropSmokeWpdb();
$wpdb->indexes = array( array( 'Key_name' => 'legacy`slug' ) );
$assert( BaseRepository::drop_index( 'wp_records', 'legacy`slug', $wpdb ), 'existing index is dropped' );

$expected = 'mysql' === $mode
	? 'ALTER TABLE `wp_records` DROP INDEX `legacy``slug`'
	: 'DROP INDEX `legacy``slug` ON `wp_records`';
$assert( array( $expected ) === $wpdb->queries, "{$mode} uses the expected prepared drop statement" );
$assert( array( array( 'SHOW INDEX FROM %i', array( 'wp_records' ) ), $mode === 'mysql' ? array( 'ALTER TABLE %i DROP INDEX %i', array( 'wp_records', 'legacy`slug' ) ) : array( 'DROP INDEX %i ON %i', array( 'legacy`slug', 'wp_records' ) ) ) === $wpdb->prepared, "{$mode} prepares introspection and identifiers" );

$wpdb          = new IndexDropSmokeWpdb();
$wpdb->indexes = array();
$assert( BaseRepository::drop_index( 'wp_records', 'missing', $wpdb ), 'missing index is a no-op' );
$assert( array() === $wpdb->queries, 'missing index issues no drop query' );

$wpdb            = new IndexDropSmokeWpdb();
$wpdb->indexes   = array( array( 'Key_name' => 'legacy_slug' ) );
$wpdb->fail_drop = true;
try {
	BaseRepository::drop_index( 'wp_records', 'legacy_slug', $wpdb );
	$assert( false, 'drop failures surface as exceptions' );
} catch ( RuntimeException $error ) {
	$assert( str_contains( $error->getMessage(), 'driver drop failed' ), 'drop failure includes the database error' );
}

if ( $failures ) {
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}

echo "{$mode} index drop assertions passed.\n";
