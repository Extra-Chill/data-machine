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

eval( 'namespace DataMachine\\Core\\Database\\Jobs {
	function get_option( string $key, $default = false ) { return $GLOBALS["dm_status_options"][ $key ] ?? $default; }
	function update_option( string $key, $value, bool $autoload = false ): bool { $GLOBALS["dm_status_options"][ $key ] = $value; return true; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_cache_delete( $key, string $group = "" ): bool { $GLOBALS["dm_status_cache_deletes"][] = array( $key, $group ); return true; }
}' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {}
}

class JobStatusMigrationWpdb extends wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public array $rows = array();
	public array $selected_batches = array();

	public function __construct() {}

	public function prepare( $query, ...$args ) {
		return array( $query, $args );
	}

	public function get_results( $query = null, $output = OBJECT ) {
		$args = $query[1];
		$cursor = (int) $args[1];
		$canonical = array_slice( $args, 2, -1 );
		$limit = (int) end( $args );
		$rows = array_filter(
			$this->rows,
			static fn( array $row ): bool => $row['job_id'] > $cursor && ! in_array( $row['status'], $canonical, true )
		);
		usort( $rows, static fn( array $a, array $b ): int => $a['job_id'] <=> $b['job_id'] );
		$batch = array_slice( array_values( $rows ), 0, $limit );
		$this->selected_batches[] = array_column( $batch, 'job_id' );
		return $batch;
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$args      = $query[1];
		$canonical = 1 === count( $args ) && is_array( $args[0] ) ? array_slice( $args[0], 1 ) : array_slice( $args, 1 );
		return count( array_filter( $this->rows, static fn( array $row ): bool => ! in_array( $row['status'], $canonical, true ) ) );
	}

	public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
		$job_id = (int) end( $query[1] );
		return $this->rows[ $job_id ] ?? null;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$job_id = (int) $where['job_id'];
		if ( ! isset( $this->rows[ $job_id ] ) || $this->rows[ $job_id ]['status'] !== $where['status'] ) {
			return 0;
		}
		$this->rows[ $job_id ] = array_merge( $this->rows[ $job_id ], $data );
		return 1;
	}

	public function query( $query ) {
		return 1;
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';

if ( ! class_exists( 'DataMachine\\Core\\Database\\Jobs\\Jobs' ) ) {
	// Keep the fallback aligned with the owner without duplicating its raw slug literal.
	eval( 'namespace DataMachine\\Core\\Database\\Jobs; class Jobs { public const TABLE_NAME = "datamachine_" . "jobs"; }' );
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

$wpdb = new JobStatusMigrationWpdb();
$wpdb->rows = array(
	1 => array( 'job_id' => 1, 'status' => 'failed - provider timeout', 'engine_data' => '{"retry":{"attempt":2}}' ),
	500000 => array( 'job_id' => 500000, 'status' => 'completed', 'engine_data' => '{}' ),
	1000000 => array( 'job_id' => 1000000, 'status' => 'pending_runtime_tool', 'engine_data' => '{"runtime_tool_request":{"id":"r1"}}' ),
);
$migration = new JobStatusMigration( $wpdb );
$dry_run = $migration->inspect();
$assert( 2 === $dry_run['remaining'] && 'migration_required' === $dry_run['status'], 'dry run reports remaining rows' );
$assert( 'failed - provider timeout' === $wpdb->rows[1]['status'], 'dry run does not mutate rows' );

$first = $migration->apply( 1 );
$engine = json_decode( $wpdb->rows[1]['engine_data'], true );
$assert( 'failed' === $wpdb->rows[1]['status'], 'first bounded batch stores base status' );
$assert( 'provider timeout' === $engine['job_status_reason'], 'first batch preserves reason beside existing metadata' );
$assert( array( 1 ) === $wpdb->selected_batches[0], 'first batch selects only the sparse noncanonical candidate' );
$assert( 1 === $first['migrated'] && 'in_progress' === $first['status'], 'first bounded candidate batch exposes resumable progress' );

$second = $migration->apply( 1 );
$runtime_engine = json_decode( $wpdb->rows[1000000]['engine_data'], true );
$assert( 'waiting' === $wpdb->rows[1000000]['status'], 'second batch normalizes explicit legacy state across a huge ID gap' );
$assert( 'runtime_tool_request' === $runtime_engine['job_status_reason'], 'mapped legacy reason is persisted' );
$assert( array( 1000000 ) === $wpdb->selected_batches[1], 'second batch skips the canonical row across the sparse ID gap' );
$assert( 2 === $second['scanned'], 'scanned evidence counts candidates rather than canonical rows' );

$complete = $migration->apply( 1 );
$assert( $complete['complete'] && JobStatusMigration::isComplete(), 'completion is durable after final verification' );

$wpdb->rows[500000] = array( 'job_id' => 500000, 'status' => 'failed: late legacy insert', 'engine_data' => '{}' );
$late = $migration->apply( 1 );
$assert( ! $late['complete'] && 1 === $late['remaining'], 'final verification detects a late candidate behind the cursor' );
$assert( 0 === $late['cursor'], 'residue behind the cursor rewinds persisted progress' );
$assert( ! JobStatusMigration::isComplete(), 'late residue clears durable completion' );

$resumed = $migration->apply( 1 );
$assert( 'failed' === $wpdb->rows[500000]['status'], 'rewound cursor migrates the late legacy row' );
$assert( 3 === $resumed['migrated'] && 3 === $resumed['scanned'], 'resume preserves cumulative v1 migration evidence' );

$again = $migration->apply( 1 );
$assert( 0 === $again['remaining'] && $again['complete'], 'repeated apply remains idempotent' );
$assert( 3 === count( $GLOBALS['dm_status_cache_deletes'] ), 'each migrated engine snapshot invalidates cache' );

$GLOBALS['dm_status_options'] = array();
$invalid_wpdb = new JobStatusMigrationWpdb();
$invalid_wpdb->rows = array(
	1 => array( 'job_id' => 1, 'status' => 'failed - reason', 'engine_data' => '{not-json' ),
);
$invalid = ( new JobStatusMigration( $invalid_wpdb ) )->apply( 1 );
$assert( 'failed - reason' === $invalid_wpdb->rows[1]['status'], 'malformed engine data fails without changing status' );
$assert( '{not-json' === $invalid_wpdb->rows[1]['engine_data'], 'malformed engine data is never replaced' );
$assert( 1 === $invalid['errors'], 'malformed engine data exposes a migration error' );

$GLOBALS['dm_status_options'] = array();
$unknown_wpdb = new JobStatusMigrationWpdb();
$unknown_wpdb->rows = array(
	10 => array( 'job_id' => 10, 'status' => 'mystery_status', 'engine_data' => '{}' ),
);
$unknown = ( new JobStatusMigration( $unknown_wpdb ) )->apply( 2 );
$assert( 'mystery_status' === $unknown_wpdb->rows[10]['status'], 'unknown status is never rewritten' );
$assert( 1 === $unknown['unknown'] && 1 === $unknown['remaining'], 'unknown status preserves migration evidence' );
$assert( 0 === $unknown['cursor'], 'unknown residue rewinds the cursor for operator remediation' );

if ( $failures ) {
	echo 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n";
	exit( 1 );
}

echo "Job status normalization smoke complete: all assertions passed.\n";
