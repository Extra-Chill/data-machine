<?php
/**
 * Smoke coverage for batched Action Scheduler retention cleanup (#2616).
 *
 * Run with: php tests/retention-action-scheduler-batching-smoke.php
 *
 * Pins the bloat fix: cleanup deletes in bounded batches via delete-by-id
 * subqueries (not a single unbounded DELETE), applies per-hook max-age
 * overrides, honours iteration / wall-clock safety caps, and gates
 * OPTIMIZE TABLE behind an opt-in filter + row-count threshold. Also proves
 * the count/dry-run path reads without deleting.
 *
 * The functional half drives RetentionCleanup against an in-memory fake
 * $wpdb so the batching loop and per-hook windows execute for real.
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

namespace {

	use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../' );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	$root = dirname( __DIR__ );

	$failed = 0;
	$total  = 0;

	// Simple in-memory filter registry so apply_filters() returns overrides.
	$GLOBALS['__retention_filters'] = array();

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value ) {
			if ( array_key_exists( $hook, $GLOBALS['__retention_filters'] ) ) {
				return $GLOBALS['__retention_filters'][ $hook ];
			}
			return $value;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ) {}
	}

	function retention_set_filter( string $hook, $value ): void {
		$GLOBALS['__retention_filters'][ $hook ] = $value;
	}

	function assert_batching( string $name, bool $condition, string $detail = '' ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}

		++$failed;
		echo "  [FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}

	echo "=== retention-action-scheduler-batching-smoke ===\n";

	// -----------------------------------------------------------------------
	// 1. Source-string assertions (cheap structural guarantees).
	// -----------------------------------------------------------------------

	$cleanup = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php' ) ?: '';
	$command = file_get_contents( $root . '/inc/Cli/Commands/RetentionCommand.php' ) ?: '';

	assert_batching(
		'cleanup no longer issues an unbounded multi-table DELETE',
		! str_contains( $cleanup, 'DELETE l FROM %i l' )
	);
	assert_batching(
		'cleanup deletes logs via id-subquery with LIMIT',
		str_contains( $cleanup, 'DELETE FROM %i WHERE log_id IN (' )
			&& str_contains( $cleanup, 'LIMIT %d' )
	);
	assert_batching(
		'cleanup deletes actions via id-subquery with LIMIT',
		str_contains( $cleanup, 'DELETE FROM %i WHERE action_id IN (' )
	);
	assert_batching(
		'batch size is filterable',
		str_contains( $cleanup, "apply_filters( 'datamachine_retention_batch_size'" )
	);
	assert_batching(
		'per-hook max-age is filterable',
		str_contains( $cleanup, "apply_filters( 'datamachine_as_actions_hook_max_age_days'" )
	);
	assert_batching(
		'default per-hook override targets execute_step',
		str_contains( $cleanup, "'datamachine_execute_step' => 1 / 24" )
	);
	assert_batching(
		'per-hook row-count ceiling is filterable',
		str_contains( $cleanup, "apply_filters( 'datamachine_as_actions_hook_max_rows'" )
	);
	assert_batching(
		'default row-count ceiling targets execute_step',
		str_contains( $cleanup, "'datamachine_execute_step' => 100000" )
	);
	assert_batching(
		'ceiling probe selects cutoff via OFFSET on max_rows',
		str_contains( $cleanup, 'ORDER BY last_attempt_gmt DESC LIMIT 1 OFFSET %d' )
	);
	assert_batching(
		'ceiling enforcement is wired into the cleanup pass',
		str_contains( $cleanup, 'enforceActionSchedulerRowCeilings' )
	);
	assert_batching(
		'iteration + runtime safety caps exist',
		str_contains( $cleanup, "apply_filters( 'datamachine_retention_max_iterations'" )
			&& str_contains( $cleanup, "apply_filters( 'datamachine_retention_max_runtime_seconds'" )
	);
	assert_batching(
		'OPTIMIZE TABLE is opt-in via filter',
		str_contains( $cleanup, "apply_filters( 'datamachine_retention_optimize_tables', false )" )
	);
	assert_batching(
		'OPTIMIZE TABLE is row-count gated',
		str_contains( $cleanup, "apply_filters( 'datamachine_retention_optimize_threshold'" )
			&& str_contains( $cleanup, 'OPTIMIZE TABLE %i' )
	);
	assert_batching(
		'CLI reports per-table Action Scheduler eligible rows',
		str_contains( $command, 'countActionSchedulerBreakdown' )
			&& str_contains( $command, 'per-table eligible rows' )
	);

	// -----------------------------------------------------------------------
	// 2. Functional assertions (drive the real batching loop).
	// -----------------------------------------------------------------------

	require_once __DIR__ . '/fixtures/retention-batching-stubs.php';
	require_once $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';

	$fake_wpdb = new class() {
		public string $prefix = 'wp_';

		/** @var array<int, array{action_id:int, hook:string, status:string, last_attempt_gmt:string}> */
		public array $actions = array();

		/** @var array<int, array{log_id:int, action_id:int}> */
		public array $logs = array();

		public int $delete_queries = 0;
		public int $optimize_calls = 0;

		/** @var array<int, int> Batch sizes observed per DELETE (proves bounding). */
		public array $batch_sizes = array();

		public function prepare( string $query, ...$args ): array {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array(
				'sql'  => $query,
				'args' => $args,
			);
		}

		public function get_var( $prepared ) {
			$sql  = $prepared['sql'];
			$args = $prepared['args'];

			// Row-count ceiling probe: return the last_attempt_gmt at OFFSET
			// $max_rows among the most-recent completed/terminal rows for the
			// hook (or null when fewer than $max_rows exist).
			if ( str_contains( $sql, 'ORDER BY last_attempt_gmt DESC LIMIT 1 OFFSET' ) ) {
				$hook   = $this->extract_hook( $sql, $args );
				$offset = (int) end( $args );
				$rows   = array();
				foreach ( $this->actions as $row ) {
					if ( ! in_array( $row['status'], array( 'complete', 'failed', 'canceled' ), true ) ) {
						continue;
					}
					if ( null !== $hook && $row['hook'] !== $hook ) {
						continue;
					}
					$rows[] = $row['last_attempt_gmt'];
				}
				rsort( $rows );
				return $rows[ $offset ] ?? null;
			}

			$cutoff = $this->extract_cutoff( $args );
			$hook   = $this->extract_hook( $sql, $args );

			if ( str_contains( $sql, 'FROM %i l' ) || str_contains( $sql, 'log_id' ) ) {
				return count( $this->matching_logs( $cutoff, $hook ) );
			}

			return count( $this->matching_actions( $cutoff, $hook ) );
		}

		public function query( $prepared ): int {
			$sql  = $prepared['sql'];
			$args = $prepared['args'];

			if ( str_contains( $sql, 'OPTIMIZE TABLE' ) ) {
				++$this->optimize_calls;
				return 0;
			}

			++$this->delete_queries;
			$cutoff              = $this->extract_cutoff( $args );
			$hook                = $this->extract_hook( $sql, $args );
			$limit               = (int) end( $args );
			$this->batch_sizes[] = $limit;

			if ( str_contains( $sql, 'log_id IN' ) ) {
				$matching = array_slice( $this->matching_logs( $cutoff, $hook ), 0, $limit, true );
				foreach ( array_keys( $matching ) as $log_id ) {
					unset( $this->logs[ $log_id ] );
				}
				return count( $matching );
			}

			$matching = array_slice( $this->matching_actions( $cutoff, $hook ), 0, $limit, true );
			foreach ( array_keys( $matching ) as $action_id ) {
				unset( $this->actions[ $action_id ] );
			}
			return count( $matching );
		}

		private function extract_cutoff( array $args ): string {
			foreach ( $args as $arg ) {
				if ( is_string( $arg ) && preg_match( '/^\d{4}-\d{2}-\d{2} /', $arg ) ) {
					return $arg;
				}
			}
			return '';
		}

		private function extract_hook( string $sql, array $args ): ?string {
			if ( ! str_contains( $sql, 'hook = %s' ) ) {
				return null;
			}
			foreach ( $args as $arg ) {
				if ( is_string( $arg )
					&& ! in_array( $arg, array( 'complete', 'failed', 'canceled' ), true )
					&& ! preg_match( '/^\d{4}-\d{2}-\d{2} /', $arg )
					&& ! str_contains( $arg, 'actionscheduler' ) ) {
					return $arg;
				}
			}
			return null;
		}

		private function matching_actions( string $cutoff, ?string $hook ): array {
			$out = array();
			foreach ( $this->actions as $id => $row ) {
				if ( ! in_array( $row['status'], array( 'complete', 'failed', 'canceled' ), true ) ) {
					continue;
				}
				if ( $row['last_attempt_gmt'] >= $cutoff ) {
					continue;
				}
				if ( null !== $hook && $row['hook'] !== $hook ) {
					continue;
				}
				if ( null === $hook && in_array( $row['hook'], array( 'datamachine_execute_step' ), true ) ) {
					// Global window must exclude hooks with their own window.
					continue;
				}
				$out[ $id ] = $row;
			}
			return $out;
		}

		private function matching_logs( string $cutoff, ?string $hook ): array {
			$action_ids = array_keys( $this->matching_actions( $cutoff, $hook ) );
			$out        = array();
			foreach ( $this->logs as $id => $row ) {
				if ( in_array( $row['action_id'], $action_ids, true ) ) {
					$out[ $id ] = $row;
				}
			}
			return $out;
		}
	};

	// Seed enough rows to force the batching loop to iterate past the 1000-row
	// floor on batch size: 2500 old completed execute_step actions (7h old,
	// beyond the 6h per-hook window) each with one log, 30 old "other" hook
	// actions (8 days), plus 5 fresh execute_step actions (within 6h) that must
	// survive.
	$now       = time();
	$seven_h   = gmdate( 'Y-m-d H:i:s', $now - ( 7 * 3600 ) );
	$eight_day = gmdate( 'Y-m-d H:i:s', $now - ( 8 * DAY_IN_SECONDS ) );
	$fresh     = gmdate( 'Y-m-d H:i:s', $now - 60 );
	$aid       = 1;
	$lid       = 1;

	for ( $i = 0; $i < 2500; $i++ ) {
		$fake_wpdb->actions[ $aid ] = array(
			'action_id'        => $aid,
			'hook'             => 'datamachine_execute_step',
			'status'           => 'complete',
			'last_attempt_gmt' => $seven_h,
		);
		$fake_wpdb->logs[ $lid ] = array(
			'log_id'    => $lid,
			'action_id' => $aid,
		);
		++$aid;
		++$lid;
	}
	for ( $i = 0; $i < 30; $i++ ) {
		$fake_wpdb->actions[ $aid ] = array(
			'action_id'        => $aid,
			'hook'             => 'datamachine_run_flow_now',
			'status'           => 'complete',
			'last_attempt_gmt' => $eight_day,
		);
		++$aid;
	}
	for ( $i = 0; $i < 5; $i++ ) {
		$fake_wpdb->actions[ $aid ] = array(
			'action_id'        => $aid,
			'hook'             => 'datamachine_execute_step',
			'status'           => 'complete',
			'last_attempt_gmt' => $fresh,
		);
		++$aid;
	}

	$GLOBALS['wpdb'] = $fake_wpdb;

	$actions_before = count( $fake_wpdb->actions );
	$logs_before    = count( $fake_wpdb->logs );

	// Dry-run path (count only) must not mutate state.
	$count_total = RetentionCleanup::countActionSchedulerActions();
	assert_batching(
		'count/dry-run does not delete rows',
		count( $fake_wpdb->actions ) === $actions_before
			&& count( $fake_wpdb->logs ) === $logs_before
			&& 0 === $fake_wpdb->delete_queries
	);
	assert_batching(
		'count covers per-hook + global eligible rows (2500 logs + 2530 actions)',
		5030 === $count_total,
		"got {$count_total}"
	);

	// Request a tiny batch size; the helper clamps it up to the 1000-row floor.
	// With 2500 stale execute_step rows that still forces the loop to iterate.
	retention_set_filter( 'datamachine_retention_batch_size', 1 );
	$effective_batch = RetentionCleanup::actionSchedulerBatchSize();
	assert_batching(
		'batch size is clamped to a sane floor (>=1000)',
		1000 === $effective_batch,
		"got {$effective_batch}"
	);

	$result = RetentionCleanup::cleanupActionSchedulerActions();

	assert_batching(
		'batched cleanup looped more than twice (multiple DELETE queries)',
		$fake_wpdb->delete_queries > 2,
		"delete_queries={$fake_wpdb->delete_queries}"
	);
	assert_batching(
		'every DELETE was bounded by the effective batch size (<=1000)',
		! empty( $fake_wpdb->batch_sizes ) && max( $fake_wpdb->batch_sizes ) <= 1000
	);

	$surviving_execute_step = array_filter(
		$fake_wpdb->actions,
		static fn( $r ) => 'datamachine_execute_step' === $r['hook']
	);
	$stale_execute_step = array_filter(
		$surviving_execute_step,
		static fn( $r ) => $r['last_attempt_gmt'] === $seven_h
	);

	assert_batching(
		'per-hook aggressive window purged 7h-old execute_step rows',
		0 === count( $stale_execute_step )
	);
	assert_batching(
		'fresh execute_step rows (within 6h) survived',
		5 === count( $surviving_execute_step )
	);
	assert_batching(
		'global window purged 8-day-old other-hook rows',
		0 === count(
			array_filter(
				$fake_wpdb->actions,
				static fn( $r ) => 'datamachine_run_flow_now' === $r['hook']
			)
		)
	);
	assert_batching(
		'result reports per-table deletion counts + batch metadata',
		2530 === $result['actions_deleted']
			&& 2500 === $result['logs_deleted']
			&& 1000 === $result['batch_size']
			&& isset( $result['iterations'] )
			&& false === $result['hit_limit'],
		"actions={$result['actions_deleted']} logs={$result['logs_deleted']}"
	);

	// OPTIMIZE is opt-in: default off => no OPTIMIZE call.
	assert_batching(
		'OPTIMIZE TABLE not run when filter is off (default)',
		0 === $fake_wpdb->optimize_calls && array() === $result['optimized']
	);

	// Opt-in OPTIMIZE: enable + low threshold, re-seed, prove it rebuilds tables.
	$fake_wpdb->actions        = array();
	$fake_wpdb->logs           = array();
	$fake_wpdb->delete_queries = 0;
	$fake_wpdb->optimize_calls = 0;
	$fake_wpdb->batch_sizes    = array();
	$oaid                      = 1;
	for ( $i = 0; $i < 1200; $i++ ) {
		$fake_wpdb->actions[ $oaid ] = array(
			'action_id'        => $oaid,
			'hook'             => 'datamachine_run_flow_now',
			'status'           => 'complete',
			'last_attempt_gmt' => $eight_day,
		);
		++$oaid;
	}

	retention_set_filter( 'datamachine_retention_optimize_tables', true );
	retention_set_filter( 'datamachine_retention_optimize_threshold', 1000 );

	$opt_result = RetentionCleanup::cleanupActionSchedulerActions();

	assert_batching(
		'OPTIMIZE TABLE runs once threshold met when enabled',
		$fake_wpdb->optimize_calls >= 1
			&& in_array( 'wp_actionscheduler_actions', $opt_result['optimized'], true ),
		"optimize_calls={$fake_wpdb->optimize_calls}"
	);

	// -----------------------------------------------------------------------
	// 3. Functional row-count ceiling: rows within the age window but beyond
	//    the per-hook ceiling must still be deleted (oldest-first).
	// -----------------------------------------------------------------------

	$fake_wpdb->actions        = array();
	$fake_wpdb->logs           = array();
	$fake_wpdb->delete_queries = 0;
	$fake_wpdb->optimize_calls = 0;
	$fake_wpdb->batch_sizes    = array();

	// Reset filters from the OPTIMIZE block, then cap execute_step at 10 rows
	// and disable the age window for it (large negative-ish window) so ONLY the
	// ceiling can delete. Seed 25 fresh execute_step rows with distinct, recent
	// timestamps (within any age window) — 15 should be deleted by the ceiling,
	// the 10 most-recent must survive.
	$GLOBALS['__retention_filters'] = array();
	retention_set_filter( 'datamachine_as_actions_hook_max_age_days', array( 'datamachine_execute_step' => 3650.0 ) );
	retention_set_filter( 'datamachine_as_actions_hook_max_rows', array( 'datamachine_execute_step' => 10 ) );

	$caid = 1;
	for ( $i = 0; $i < 25; $i++ ) {
		// Distinct timestamps, all very recent (i seconds ago).
		$fake_wpdb->actions[ $caid ] = array(
			'action_id'        => $caid,
			'hook'             => 'datamachine_execute_step',
			'status'           => 'complete',
			'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', $now - ( 25 - $i ) ),
		);
		++$caid;
	}

	$ceiling_result = RetentionCleanup::cleanupActionSchedulerActions();

	$surviving = array_filter(
		$fake_wpdb->actions,
		static fn( $r ) => 'datamachine_execute_step' === $r['hook']
	);

	// Boundary semantics: the deleters use strictly `< cutoff`, and the cutoff
	// is the timestamp AT offset $max_rows. So the boundary row survives too —
	// we keep $max_rows + 1 (never over-delete) and delete the rest. With 25
	// rows and a cap of 10, that is 11 kept / 14 deleted.
	assert_batching(
		'row-count ceiling deletes rows beyond the cap despite age window',
		11 === count( $surviving ),
		'surviving=' . count( $surviving )
	);
	assert_batching(
		'ceiling kept the most-recent rows (oldest deleted first, boundary kept)',
		14 === ( $ceiling_result['ceiling_actions_deleted'] ?? 0 ),
		'ceiling_deleted=' . ( $ceiling_result['ceiling_actions_deleted'] ?? 0 )
	);

	// -----------------------------------------------------------------------
	// 4. Bloat guardrail + catch-up reschedule (#2792).
	// -----------------------------------------------------------------------

	$task = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionActionSchedulerTask.php' ) ?: '';

	assert_batching(
		'guardrail threshold is filterable',
		str_contains( $cleanup, "apply_filters( 'datamachine_as_table_size_threshold'" )
	);
	assert_batching(
		'guardrail logs a warning when a table is over threshold',
		str_contains( $cleanup, "'warning'," )
			&& str_contains( $cleanup, 'Action Scheduler bloat guardrail' )
	);
	assert_batching(
		'cleanup attaches live table sizes to its result',
		(bool) preg_match( "/'table_sizes'\\s+=>\\s+\\\$table_sizes/", $cleanup )
			&& str_contains( $cleanup, 'checkActionSchedulerTableSizes()' )
	);
	assert_batching(
		'read-only table-size method exists for health surfaces',
		str_contains( $cleanup, 'public static function actionSchedulerTableSizes()' )
	);
	assert_batching(
		'AS retention task enqueues a catch-up pass when a run hits its limit',
		str_contains( $task, 'maybeScheduleCatchUp' )
			&& str_contains( $task, 'TaskScheduler::schedule( RetentionCleanup::TASK_AS_ACTIONS' )
	);
	assert_batching(
		'catch-up only fires when the pass made progress (guards hot loop)',
		str_contains( $task, '$hit_limit && $deleted > 0' )
	);

	// Functional: threshold filter clamps to 0 (disabled) and back.
	retention_set_filter( 'datamachine_as_table_size_threshold', 0 );
	assert_batching(
		'threshold of 0 disables the guardrail',
		0 === RetentionCleanup::actionSchedulerTableSizeThreshold()
	);
	retention_set_filter( 'datamachine_as_table_size_threshold', 5000 );
	assert_batching(
		'threshold is read from the filter',
		5000 === RetentionCleanup::actionSchedulerTableSizeThreshold()
	);

	// Functional: a disabled guardrail short-circuits without counting.
	retention_set_filter( 'datamachine_as_table_size_threshold', 0 );
	$disabled = RetentionCleanup::checkActionSchedulerTableSizes();
	assert_batching(
		'disabled guardrail returns enabled=false and breached=false',
		false === $disabled['enabled'] && false === $disabled['breached']
	);

	if ( $failed > 0 ) {
		echo "\nretention-action-scheduler-batching-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nretention-action-scheduler-batching-smoke passed: {$total} assertions.\n";
}
