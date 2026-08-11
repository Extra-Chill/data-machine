<?php
/**
 * Smoke coverage for bounded Action Scheduler log persistence (#3095).
 *
 * Run with: php tests/action-scheduler-log-persistence-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$filters = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $filters;
		$filters[ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		global $filters;
		if ( empty( $filters[ $hook ] ) ) {
			return $value;
		}

		ksort( $filters[ $hook ] );
		foreach ( $filters[ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registered_callback ) {
				list( $callback, $accepted_args ) = $registered_callback;
				$value = $callback( $value, ...array_slice( $args, 0, $accepted_args - 1 ) );
			}
		}

		return $value;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/ActionScheduler/LogPersistencePolicy.php';

use DataMachine\Core\ActionScheduler\LogPersistencePolicy;

$failed = 0;
$total  = 0;

function assert_log_persistence( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}" . ( '' !== $detail ? ": {$detail}" : '' ) . "\n";
}

echo "=== action-scheduler-log-persistence-policy-smoke ===\n";

LogPersistencePolicy::register();

assert_log_persistence(
	'registration is inert until a persistence integration applies the hook',
	1 === count( $filters['markdown_db_persistent_table_query'][100] ?? array() )
);

$source_query = 'SELECT * FROM `tenant_42_actionscheduler_logs` ORDER BY 1';
$unchanged    = apply_filters( 'markdown_db_persistent_table_query', $source_query, 'posts', 'tenant_42_posts', null );
assert_log_persistence( 'unrelated tables retain their supplied query', $source_query === $unchanged );

$bounded = apply_filters( 'markdown_db_persistent_table_query', $source_query, 'actionscheduler_logs', 'tenant_42_actionscheduler_logs', null );
assert_log_persistence(
	'custom-prefix log table is bounded through the supplied query',
	str_contains( $bounded, $source_query ) && str_contains( $bounded, 'LIMIT 10000' )
);
assert_log_persistence(
	'recent window and emitted snapshot have deterministic inverse ordering',
	str_contains( $bounded, 'ORDER BY log_date_gmt DESC, log_id DESC LIMIT 10000' )
		&& str_ends_with( $bounded, 'ORDER BY log_date_gmt ASC, log_id ASC' )
);

add_filter(
	'markdown_db_persistent_table_query',
	static fn( string $query ): string => $query . ' /* third-party-policy */',
	20
);
$composed = apply_filters( 'markdown_db_persistent_table_query', $source_query, 'actionscheduler_logs', 'custom_actionscheduler_logs', null );
assert_log_persistence(
	'earlier third-party query policies are preserved inside the bounded source',
	str_contains( $composed, '/* third-party-policy */ ) AS datamachine_action_scheduler_log_source' )
);

add_filter( 'datamachine_action_scheduler_log_persistence_row_budget', static fn() => '25', 10 );
assert_log_persistence( 'digit-string budget is accepted', 25 === LogPersistencePolicy::rowBudget() );
add_filter( 'datamachine_action_scheduler_log_persistence_row_budget', static fn() => '25; DROP TABLE posts', 20 );
assert_log_persistence( 'unsafe budget falls back to the safe default', 10000 === LogPersistencePolicy::rowBudget() );
$unsafe_budget_query = LogPersistencePolicy::filterPersistentTableQuery( $source_query, 'actionscheduler_logs', 'tenant_42_actionscheduler_logs', null );
assert_log_persistence(
	'unsafe budget is never interpolated into SQL',
	str_contains( $unsafe_budget_query, 'LIMIT 10000' ) && ! str_contains( $unsafe_budget_query, 'DROP TABLE' )
);
add_filter( 'datamachine_action_scheduler_log_persistence_row_budget', static fn() => 999999, 30 );
assert_log_persistence( 'budget has a bounded maximum', 100000 === LogPersistencePolicy::rowBudget() );

if ( $failed > 0 ) {
	echo "\naction-scheduler-log-persistence-policy-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\naction-scheduler-log-persistence-policy-smoke passed: {$total} assertions.\n";
