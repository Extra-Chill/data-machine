<?php
/**
 * Pure-PHP smoke test for ProcessedItemsCommand::parse_flow_step_id().
 *
 * Run with: php tests/processed-items-flow-step-parse-smoke.php
 *
 * Covers the deterministic prefix/suffix parser that powers
 * `wp datamachine processed-items audit --pipeline=N`. The full audit
 * query is exercised against MySQL/SQLite via live runs; this harness
 * just locks down the parser shape so SUBSTRING_INDEX never sneaks back.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Stub the WP-CLI base class hierarchy enough to load ProcessedItemsCommand.
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

// Stub plugin classes that ProcessedItemsCommand references at file load time.
if ( ! class_exists( 'DataMachine\\Cli\\BaseCommand' ) ) {
	eval( 'namespace DataMachine\\Cli; class BaseCommand extends \\WP_CLI_Command {}' );
}

if ( ! class_exists( 'DataMachine\\Abilities\\ProcessedItemsAbilities' ) ) {
	eval( 'namespace DataMachine\\Abilities; class ProcessedItemsAbilities {}' );
}

if ( ! class_exists( 'DataMachine\\Core\\Database\\ProcessedItems\\ProcessedItems' ) ) {
	eval( 'namespace DataMachine\\Core\\Database\\ProcessedItems; class ProcessedItems { public function get_table_name(): string { return "wp_datamachine_processed_items"; } }' );
}

require_once __DIR__ . '/../inc/Cli/Commands/ProcessedItemsCommand.php';

use DataMachine\Cli\Commands\ProcessedItemsCommand;

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

$method = new ReflectionMethod( ProcessedItemsCommand::class, 'parse_flow_step_id' );
if ( PHP_VERSION_ID < 80100 ) {
	$method->setAccessible( true );
}
$cmd = new ProcessedItemsCommand();

$parse = static function ( string $input ) use ( $method, $cmd ) {
	return $method->invoke( $cmd, $input );
};

echo "Test 1: canonical {pipeline_id}_{uuid}_{flow_id} shape\n";
$result = $parse( '2_0978b49e-a5a1-46e6-ae3a-0bf62ccea60d_2' );
dm_assert( is_array( $result ), 'returns an array for canonical input' );
dm_assert( 2 === $result['pipeline_id'], 'pipeline_id parsed from prefix' );
dm_assert( 2 === $result['flow_id'], 'flow_id parsed from suffix' );

echo "Test 2: multi-digit pipeline + flow ids\n";
$result = $parse( '42_aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee_137' );
dm_assert( 42 === $result['pipeline_id'], 'multi-digit pipeline_id' );
dm_assert( 137 === $result['flow_id'], 'multi-digit flow_id' );

echo "Test 3: pipeline_id and flow_id can match without aliasing the uuid\n";
$result = $parse( '7_xxxx-yyyy-zzzz_7' );
dm_assert( 7 === $result['pipeline_id'], 'leading 7' );
dm_assert( 7 === $result['flow_id'], 'trailing 7 — different segment' );

echo "Test 4: malformed inputs return null\n";
dm_assert( null === $parse( '' ), 'empty string' );
dm_assert( null === $parse( '2' ), 'no underscore' );
dm_assert( null === $parse( '2_uuid' ), 'single underscore (no flow_id segment)' );
dm_assert( null === $parse( 'abc_uuid_2' ), 'non-numeric pipeline_id' );
dm_assert( null === $parse( '2_uuid_abc' ), 'non-numeric flow_id' );
dm_assert( null === $parse( '_uuid_2' ), 'empty pipeline_id segment' );
dm_assert( null === $parse( '2_uuid_' ), 'empty flow_id segment' );

echo "\nAll smoke checks passed.\n";
