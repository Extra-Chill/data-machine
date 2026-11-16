<?php
/**
 * Pure-PHP smoke test for source-rejected processed-items CLI query helpers.
 *
 * Run with: php tests/processed-items-source-rejected-cli-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function log( $msg ) {}
		public static function error( $msg ) {}
		public static function success( $msg ) {}
		public static function confirm( $msg ) {}
	}
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {}
}

if ( ! class_exists( 'DataMachine\\Cli\\BaseCommand' ) ) {
	eval( 'namespace DataMachine\\Cli; class BaseCommand extends \\WP_CLI_Command {}' );
}

if ( ! class_exists( 'DataMachine\\Abilities\\ProcessedItemsAbilities' ) ) {
	eval( 'namespace DataMachine\\Abilities; class ProcessedItemsAbilities {}' );
}

if ( ! class_exists( 'DataMachine\\Core\\Database\\ProcessedItems\\ProcessedItems' ) ) {
	eval( 'namespace DataMachine\\Core\\Database\\ProcessedItems; class ProcessedItems { public const STATUS_PROCESSED = "processed"; public function get_table_name(): string { return "wp_datamachine_processed_items"; } }' );
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public function esc_like( string $value ): string {
			return $value;
		}
	}
}
$wpdb = new wpdb();

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Cli/Commands/ProcessedItemsCommand.php';

use DataMachine\Cli\Commands\ProcessedItemsCommand;

function datamachine_source_rejected_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

$cmd         = new ProcessedItemsCommand();
$query_parts = new ReflectionMethod( ProcessedItemsCommand::class, 'build_source_rejected_query_parts' );
$describe    = new ReflectionMethod( ProcessedItemsCommand::class, 'describe_source_rejected_filters' );
if ( PHP_VERSION_ID < 80100 ) {
	$query_parts->setAccessible( true );
	$describe->setAccessible( true );
}

echo "Test 1: default query matches persisted source-rejection evidence\n";
$result = $query_parts->invoke( $cmd, array(), 'wp_datamachine_processed_items', 'wp_datamachine_jobs' );
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'pi.status = %s' ), 'filters processed item status' );
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'j.status = %s' ), 'still matches legacy job status' );
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'j.status_reason = %s' ), 'matches legacy structured status_reason column' );
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'j.engine_data REGEXP %s' ), 'matches rejection reason evidence' );
datamachine_source_rejected_assert( ! in_array( 'completed_no_items', $result['values'], true ), 'does not match completed_no_items by status alone' );
datamachine_source_rejected_assert(
	array(
		'wp_datamachine_processed_items',
		'wp_datamachine_jobs',
		'processed',
		'agent_skipped - source-rejected',
		'agent_skipped',
		'source-rejected',
		'%"job_status_reason":"source-rejected"%',
		'%"result":"source_rejected"%',
		'"source_rejection_reason":"[^"]',
	) === $result['values'],
	'default values cover legacy storage and modern step-result evidence'
);
datamachine_source_rejected_assert( 1 === preg_match( '/' . $result['values'][8] . '/', '{"step_results":{"1_2":{"source_rejection_reason":"irrelevant_location"}}}' ), 'reason pattern matches non-empty reason' );
datamachine_source_rejected_assert( 0 === preg_match( '/' . $result['values'][8] . '/', '{"step_results":{"1_2":{"source_rejection_reason":""}}}' ), 'reason pattern ignores empty reason' );

echo "Test 1b: explicit --job-status overrides evidence matching\n";
$result = $query_parts->invoke( $cmd, array( 'job-status' => 'completed_no_items' ), 'wp_datamachine_processed_items', 'wp_datamachine_jobs' );
datamachine_source_rejected_assert( array( 'wp_datamachine_processed_items', 'wp_datamachine_jobs', 'processed', 'completed_no_items' ) === $result['values'], 'override matches only the requested status' );
datamachine_source_rejected_assert( ! str_contains( $result['where_sql'], 'REGEXP' ), 'override skips evidence predicate' );

echo "Test 1c: --reason narrows to a rejection reason substring\n";
$result  = $query_parts->invoke( $cmd, array( 'reason' => 'location' ), 'wp_datamachine_processed_items', 'wp_datamachine_jobs' );
$pattern = end( $result['values'] );
datamachine_source_rejected_assert( '"source_rejection_reason":"[^"]*location' === $pattern, 'reason substring is anchored to the reason value' );
datamachine_source_rejected_assert( 1 === preg_match( '/' . $pattern . '/', '{"source_rejection_reason":"irrelevant_location"}' ), 'reason filter matches substring' );
datamachine_source_rejected_assert( 0 === preg_match( '/' . $pattern . '/', '{"source_rejection_reason":"non_music_event","note":"location"}' ), 'reason filter does not span other keys' );
$result  = $query_parts->invoke( $cmd, array( 'reason' => 'a.b (c)' ), 'wp_datamachine_processed_items', 'wp_datamachine_jobs' );
$pattern = end( $result['values'] );
datamachine_source_rejected_assert( '"source_rejection_reason":"[^"]*a\\.b \\(c\\)' === $pattern, 'reason metacharacters are escaped' );

echo "Test 2: scope filters are added generically\n";
$result = $query_parts->invoke(
	$cmd,
	array(
		'pipeline'    => '12',
		'flow'        => '34',
		'source-type' => 'mcp',
		'after'       => '2026-05-01',
		'before'      => '2026-05-21 12:00:00',
	),
	'wp_datamachine_processed_items',
	'wp_datamachine_jobs'
);
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'j.pipeline_id = %s' ), 'adds pipeline filter' );
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'j.flow_id = %s' ), 'adds flow filter' );
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'pi.source_type = %s' ), 'adds source type filter' );
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'pi.processed_timestamp >= %s' ), 'adds lower date bound' );
datamachine_source_rejected_assert( str_contains( $result['where_sql'], 'pi.processed_timestamp <= %s' ), 'adds upper date bound' );
datamachine_source_rejected_assert( in_array( 'mcp', $result['values'], true ), 'keeps source type generic' );

echo "Test 3: clear output describes blast-radius filters\n";
$description = $describe->invoke(
	$cmd,
	array(
		'pipeline'    => '12',
		'source-type' => 'mcp',
		'after'       => '2026-05-01',
	)
);
datamachine_source_rejected_assert( str_contains( $description, 'evidence=source-rejection' ), 'describes default evidence matching' );
datamachine_source_rejected_assert( str_contains( $description, 'pipeline=12' ), 'describes pipeline scope' );
datamachine_source_rejected_assert( str_contains( $description, 'source-type=mcp' ), 'describes source-type scope' );

echo "\nAll source-rejected CLI smoke checks passed.\n";
