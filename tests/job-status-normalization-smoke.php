<?php
/** Regression coverage for canonical status storage and bounded migration. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['dm_status_options'] = array();
$GLOBALS['dm_status_cache_deletes'] = array();

function get_option( string $key, $default = false ) {
	return $GLOBALS['dm_status_options'][ $key ] ?? $default;
}
function update_option( string $key, $value, bool $autoload = false ): bool {
	$GLOBALS['dm_status_options'][ $key ] = $value;
	return true;
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function wp_cache_delete( $key, string $group = '' ): bool {
	$GLOBALS['dm_status_cache_deletes'][] = array( $key, $group );
	return true;
}

class wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $rows = array();

	public function prepare( string $query, ...$args ): array {
		return array( $query, $args );
	}

	public function get_results( $query, $output = null ): array {
		$args = $query[1];
		$cursor = (int) $args[1];
		$limit = (int) $args[2];
		$rows = array_filter( $this->rows, static fn( array $row ): bool => $row['job_id'] > $cursor );
		usort( $rows, static fn( array $a, array $b ): int => $a['job_id'] <=> $b['job_id'] );
		return array_slice( array_values( $rows ), 0, $limit );
	}

	public function get_var( $query ) {
		$args      = $query[1];
		$canonical = 1 === count( $args ) && is_array( $args[0] ) ? array_slice( $args[0], 1 ) : array_slice( $args, 1 );
		return count( array_filter( $this->rows, static fn( array $row ): bool => ! in_array( $row['status'], $canonical, true ) ) );
	}

	public function get_row( $query, $output = null ) {
		$job_id = (int) end( $query[1] );
		return $this->rows[ $job_id ] ?? null;
	}

	public function update( $table, array $data, array $where, array $formats = array(), array $where_formats = array() ) {
		$job_id = (int) $where['job_id'];
		if ( ! isset( $this->rows[ $job_id ] ) || $this->rows[ $job_id ]['status'] !== $where['status'] ) {
			return 0;
		}
		$this->rows[ $job_id ] = array_merge( $this->rows[ $job_id ], $data );
		return 1;
	}

	public function query( string $query ) {
		return 1;
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';

if ( ! class_exists( 'DataMachine\\Core\\Database\\Jobs\\Jobs' ) ) {
	eval( 'namespace DataMachine\\Core\\Database\\Jobs; class Jobs { public const TABLE_NAME = "datamachine_jobs"; }' );
}

require_once __DIR__ . '/../inc/Core/Database/Jobs/JobStatusMigration.php';

use DataMachine\Core\JobStatus;
use DataMachine\Core\Database\Jobs\JobStatusMigration;

$failures = array();
$assert = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$compound = JobStatus::fromString( 'failed - provider timeout - retry exhausted' );
$assert( JobStatus::FAILED === $compound->getBaseStatus(), 'compound base is parsed' );
$assert( 'provider timeout - retry exhausted' === $compound->getReason(), 'compound reason is lossless' );
$colon = JobStatus::fromString( 'failed: policy rejected' );
$assert( 'policy rejected' === $colon->getReason(), 'legacy colon reason is parsed' );
$assert( ! JobStatus::fromString( 'failedly' )->isCanonical(), 'prefix collisions are rejected' );
$runtime = JobStatus::fromString( 'pending_runtime_tool' );
$assert( JobStatus::WAITING === $runtime->getBaseStatus() && 'runtime_tool_request' === $runtime->getReason(), 'legacy runtime-tool state maps explicitly' );

$wpdb = new wpdb();
$wpdb->rows = array(
	1 => array( 'job_id' => 1, 'status' => 'failed - provider timeout', 'engine_data' => '{"retry":{"attempt":2}}' ),
	2 => array( 'job_id' => 2, 'status' => 'completed', 'engine_data' => '{}' ),
	3 => array( 'job_id' => 3, 'status' => 'pending_runtime_tool', 'engine_data' => '{"runtime_tool_request":{"id":"r1"}}' ),
);
$migration = new JobStatusMigration( $wpdb );
$dry_run = $migration->inspect();
$assert( 2 === $dry_run['remaining'] && 'migration_required' === $dry_run['status'], 'dry run reports remaining rows' );
$assert( 'failed - provider timeout' === $wpdb->rows[1]['status'], 'dry run does not mutate rows' );

$first = $migration->apply( 2 );
$engine = json_decode( $wpdb->rows[1]['engine_data'], true );
$assert( 'failed' === $wpdb->rows[1]['status'], 'first bounded batch stores base status' );
$assert( 'provider timeout' === $engine['job_status_reason'], 'first batch preserves reason beside existing metadata' );
$assert( 1 === $first['migrated'] && 'in_progress' === $first['status'], 'first bounded window exposes resumable progress' );

$second = $migration->apply( 2 );
$runtime_engine = json_decode( $wpdb->rows[3]['engine_data'], true );
$assert( 'waiting' === $wpdb->rows[3]['status'], 'second batch normalizes explicit legacy state' );
$assert( 'runtime_tool_request' === $runtime_engine['job_status_reason'], 'mapped legacy reason is persisted' );
$assert( $second['complete'] && JobStatusMigration::isComplete(), 'completion is durable after verification' );

$again = $migration->apply( 2 );
$assert( 0 === $again['remaining'] && 2 === $again['migrated'], 'repeated apply is idempotent' );
$assert( 2 === count( $GLOBALS['dm_status_cache_deletes'] ), 'each migrated engine snapshot invalidates cache' );

$GLOBALS['dm_status_options'] = array();
$invalid_wpdb = new wpdb();
$invalid_wpdb->rows = array(
	1 => array( 'job_id' => 1, 'status' => 'failed - reason', 'engine_data' => '{not-json' ),
);
$invalid = ( new JobStatusMigration( $invalid_wpdb ) )->apply( 1 );
$assert( 'failed - reason' === $invalid_wpdb->rows[1]['status'], 'malformed engine data fails without changing status' );
$assert( '{not-json' === $invalid_wpdb->rows[1]['engine_data'], 'malformed engine data is never replaced' );
$assert( 1 === $invalid['errors'], 'malformed engine data exposes a migration error' );

if ( $failures ) {
	echo 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n";
	exit( 1 );
}

echo "Job status normalization smoke complete: all assertions passed.\n";
