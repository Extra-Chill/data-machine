<?php
/**
 * Smoke coverage for terminal-job engine_data shedding retention (#2622).
 *
 * Run with: php tests/retention-engine-data-shedding-smoke.php
 *
 * Pins the bloat fix: retention nulls the heavy engine_data blob on TERMINAL
 * jobs older than a short window while KEEPING the row, leaves fresh /
 * non-terminal jobs untouched, batches the UPDATE by id (bounded LIMIT,
 * iteration + wall-clock caps), reuses the opt-in OPTIMIZE path, and the
 * dry-run/count path reads without mutating. Also pins that the handler_slug
 * slice is promoted out of engine_data (so handler summaries survive the
 * shed) by exercising Jobs::extract_handler_slug via reflection.
 *
 * The functional half drives RetentionCleanup against an in-memory fake $wpdb
 * so the batching loop executes for real.
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

	// Filter override registry. Works in both the pure-PHP path (shimmed
	// apply_filters reads it) and under real WordPress (a single real
	// add_filter per hook returns the latest registered override). Keeping
	// the registry keyed by hook lets eds_set_filter() change the value
	// repeatedly without stacking duplicate WP callbacks.
	$GLOBALS['__retention_filters'] = array();

	$using_real_wp = function_exists( 'apply_filters' ) && function_exists( 'add_filter' );

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
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
		}
	}

	$GLOBALS['__retention_real_wp']       = $using_real_wp;
	$GLOBALS['__retention_bound_filters'] = array();

	function eds_set_filter( string $hook, $value ): void {
		$GLOBALS['__retention_filters'][ $hook ] = $value;

		// Under real WordPress, bind one persistent callback per hook that
		// always returns the current registry value (so repeated set calls
		// just update the registry, never stack callbacks).
		if ( ! empty( $GLOBALS['__retention_real_wp'] ) && empty( $GLOBALS['__retention_bound_filters'][ $hook ] ) ) {
			add_filter(
				$hook,
				static function () use ( $hook ) {
					return $GLOBALS['__retention_filters'][ $hook ];
				},
				99
			);
			$GLOBALS['__retention_bound_filters'][ $hook ] = true;
		}
	}

	function assert_eds( string $name, bool $condition, string $detail = '' ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}

		++$failed;
		echo "  [FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}

	echo "=== retention-engine-data-shedding-smoke ===\n";

	// -----------------------------------------------------------------------
	// 1. Source-string assertions (structural guarantees).
	// -----------------------------------------------------------------------

	$cleanup  = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php' ) ?: '';
	$command  = file_get_contents( $root . '/inc/Cli/Commands/RetentionCommand.php' ) ?: '';
	$provider = file_get_contents( $root . '/inc/Engine/AI/System/SystemAgentServiceProvider.php' ) ?: '';
	$jobs     = file_get_contents( $root . '/inc/Core/Database/Jobs/Jobs.php' ) ?: '';
	$task     = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionEngineDataTask.php' ) ?: '';

	assert_eds(
		'window is filterable via datamachine_engine_data_terminal_max_age_days',
		str_contains( $cleanup, "apply_filters( 'datamachine_engine_data_terminal_max_age_days'" )
	);
	assert_eds(
		'shed UPDATE nulls engine_data via id-subquery with LIMIT (batched)',
		str_contains( $cleanup, 'UPDATE %i SET engine_data = NULL WHERE job_id IN (' )
			&& str_contains( $cleanup, 'LIMIT %d' )
	);
	assert_eds(
		'shed targets terminal rows via completed_at + non-empty engine_data',
		str_contains( $cleanup, "completed_at IS NOT NULL AND completed_at < %s AND engine_data IS NOT NULL AND engine_data != ''" )
	);
	assert_eds(
		'shed reuses shared batch / iteration / runtime caps (#2617)',
		str_contains( $cleanup, 'self::actionSchedulerBatchSize()' )
			&& str_contains( $cleanup, 'self::actionSchedulerMaxIterations()' )
			&& str_contains( $cleanup, 'self::actionSchedulerMaxRuntimeSeconds()' )
	);
	assert_eds(
		'shed reuses the generic opt-in OPTIMIZE path (no second mechanism)',
		str_contains( $cleanup, 'self::maybeOptimizeTables(' )
			&& ! str_contains( $cleanup, 'maybeOptimizeActionSchedulerTables' )
	);
	assert_eds(
		'cleanup defines TASK_ENGINE_DATA',
		str_contains( $cleanup, 'const TASK_ENGINE_DATA' )
	);
	assert_eds(
		'task class supports manual system run',
		str_contains( $task, "'supports_run'    => true" )
	);
	assert_eds(
		'provider imports + registers + schedules RetentionEngineDataTask',
		str_contains( $provider, 'RetentionEngineDataTask;' )
			&& str_contains( $provider, '= RetentionEngineDataTask::class;' )
			&& substr_count( $provider, 'RetentionCleanup::TASK_ENGINE_DATA' ) >= 2
	);
	assert_eds(
		'CLI surfaces engine_data shedding detail (eligible + reclaimable)',
		str_contains( $command, 'report_engine_data_detail' )
			&& str_contains( $command, 'countEngineDataReclaimableBytes' )
			&& str_contains( $command, 'Terminal engine_data' )
	);
	assert_eds(
		'Jobs promotes handler_slug column + populates it from engine_data',
		str_contains( $jobs, "ADD COLUMN handler_slug varchar(100)" )
			&& str_contains( $jobs, 'extract_handler_slug' )
			&& str_contains( $jobs, "\$update_data['handler_slug']" )
	);
	assert_eds(
		'handler summary reads the promoted column, not the engine_data blob',
		str_contains( $jobs, 'SELECT j.handler_slug AS handler_slug, COUNT(*) AS count' )
			&& ! str_contains( $jobs, 'REGEXP_SUBSTR(j.engine_data' )
			&& ! str_contains( $jobs, "REGEXP_SUBSTR(\n" )
	);

	// -----------------------------------------------------------------------
	// 2. Functional assertions (drive the real batching loop).
	// -----------------------------------------------------------------------

	require_once __DIR__ . '/fixtures/retention-batching-stubs.php';
	require_once $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';
	require_once $root . '/inc/Core/Database/Jobs/Jobs.php';

	// handler_slug extraction matches the legacy REGEXP_SUBSTR slice.
	$ref = new \ReflectionMethod( \DataMachine\Core\Database\Jobs\Jobs::class, 'extract_handler_slug' );
	$ref->setAccessible( true );
	assert_eds(
		'extract_handler_slug pulls the first handler_slug from the blob',
		'rss' === $ref->invoke( null, '{"x":1,"handler_slug":"rss","more":"y"}' )
	);
	assert_eds(
		'extract_handler_slug returns empty when no handler_slug present',
		'' === $ref->invoke( null, '{"x":1,"task_type":"foo"}' )
	);

	$fake_wpdb = new class() {
		public string $prefix = 'wp_';

		/** @var array<int, array{job_id:int, completed_at:?string, engine_data:?string}> */
		public array $jobs = array();

		public int $update_queries = 0;
		public int $optimize_calls = 0;

		/** @var array<int, int> */
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

		public function get_var( $prepared ): int {
			$sql    = $prepared['sql'];
			$args   = $prepared['args'];
			$cutoff = $this->extract_cutoff( $args );

			$matching = $this->matching_jobs( $cutoff );

			if ( str_contains( $sql, 'SUM(LENGTH' ) ) {
				$bytes = 0;
				foreach ( $matching as $row ) {
					$bytes += strlen( (string) $row['engine_data'] );
				}
				return $bytes;
			}

			return count( $matching );
		}

		public function query( $prepared ): int {
			$sql  = $prepared['sql'];
			$args = $prepared['args'];

			if ( str_contains( $sql, 'OPTIMIZE TABLE' ) ) {
				++$this->optimize_calls;
				return 0;
			}

			++$this->update_queries;
			$cutoff              = $this->extract_cutoff( $args );
			$limit               = (int) end( $args );
			$this->batch_sizes[] = $limit;

			$matching = array_slice( $this->matching_jobs( $cutoff ), 0, $limit, true );
			foreach ( array_keys( $matching ) as $job_id ) {
				$this->jobs[ $job_id ]['engine_data'] = null;
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

		private function matching_jobs( string $cutoff ): array {
			$out = array();
			foreach ( $this->jobs as $id => $row ) {
				if ( null === $row['completed_at'] ) {
					continue;
				}
				if ( $row['completed_at'] >= $cutoff ) {
					continue;
				}
				if ( null === $row['engine_data'] || '' === $row['engine_data'] ) {
					continue;
				}
				$out[ $id ] = $row;
			}
			return $out;
		}
	};

	// Seed: 2500 terminal jobs older than the 1-day window (blob present), 5
	// terminal jobs within the window (must keep blob), 10 in-flight jobs with
	// no completed_at (must keep blob).
	$now      = time();
	$two_days = gmdate( 'Y-m-d H:i:s', $now - ( 2 * DAY_IN_SECONDS ) );
	$one_hour = gmdate( 'Y-m-d H:i:s', $now - 3600 );
	$jid      = 1;
	$blob     = str_repeat( 'x', 4096 );

	for ( $i = 0; $i < 2500; $i++ ) {
		$fake_wpdb->jobs[ $jid ] = array(
			'job_id'       => $jid,
			'completed_at' => $two_days,
			'engine_data'  => $blob,
		);
		++$jid;
	}
	for ( $i = 0; $i < 5; $i++ ) {
		$fake_wpdb->jobs[ $jid ] = array(
			'job_id'       => $jid,
			'completed_at' => $one_hour,
			'engine_data'  => $blob,
		);
		++$jid;
	}
	for ( $i = 0; $i < 10; $i++ ) {
		$fake_wpdb->jobs[ $jid ] = array(
			'job_id'       => $jid,
			'completed_at' => null,
			'engine_data'  => $blob,
		);
		++$jid;
	}

	$GLOBALS['wpdb'] = $fake_wpdb;

	// Dry-run path: count + reclaimable bytes must not mutate.
	$eligible_before = RetentionCleanup::countEngineDataTerminalJobs();
	$reclaimable     = RetentionCleanup::countEngineDataReclaimableBytes();
	assert_eds(
		'count covers only old terminal jobs with a blob (2500)',
		2500 === $eligible_before,
		"got {$eligible_before}"
	);
	assert_eds(
		'reclaimable bytes sum the eligible blobs (2500 * 4096)',
		( 2500 * 4096 ) === $reclaimable,
		"got {$reclaimable}"
	);
	assert_eds(
		'count/dry-run does not mutate rows',
		0 === $fake_wpdb->update_queries
	);

	// Force the batching loop to iterate (clamp tiny batch to 1000 floor).
	eds_set_filter( 'datamachine_retention_batch_size', 1 );
	$result = RetentionCleanup::cleanupEngineData();

	assert_eds(
		'shed looped more than twice (multiple bounded UPDATEs)',
		$fake_wpdb->update_queries > 2,
		"update_queries={$fake_wpdb->update_queries}"
	);
	assert_eds(
		'every UPDATE was bounded by the batch size (<=1000)',
		! empty( $fake_wpdb->batch_sizes ) && max( $fake_wpdb->batch_sizes ) <= 1000
	);
	assert_eds(
		'result reports jobs updated + batch metadata',
		2500 === $result['updated']
			&& 1000 === $result['batch_size']
			&& isset( $result['iterations'] )
			&& false === $result['hit_limit'],
		"updated={$result['updated']}"
	);

	$shed   = array_filter( $fake_wpdb->jobs, static fn( $r ) => null === $r['engine_data'] );
	$kept   = array_filter( $fake_wpdb->jobs, static fn( $r ) => null !== $r['engine_data'] );
	$rows   = count( $fake_wpdb->jobs );
	assert_eds(
		'all old terminal jobs had engine_data shed (2500)',
		2500 === count( $shed )
	);
	assert_eds(
		'fresh + in-flight jobs kept engine_data (15)',
		15 === count( $kept )
	);
	assert_eds(
		'shedding keeps the row (no jobs deleted)',
		2515 === $rows,
		"rows={$rows}"
	);

	// OPTIMIZE is opt-in: default off => no rebuild.
	assert_eds(
		'OPTIMIZE TABLE not run when filter is off (default)',
		0 === $fake_wpdb->optimize_calls && array() === $result['optimized']
	);

	// Disabled window => no-op.
	$fake_wpdb->update_queries = 0;
	eds_set_filter( 'datamachine_engine_data_terminal_max_age_days', 0 );
	$disabled = RetentionCleanup::cleanupEngineData();
	assert_eds(
		'window of 0 disables shedding entirely',
		0 === $disabled['updated']
			&& 0 === $fake_wpdb->update_queries
			&& 0 === RetentionCleanup::countEngineDataTerminalJobs()
	);

	if ( $failed > 0 ) {
		echo "\nretention-engine-data-shedding-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nretention-engine-data-shedding-smoke passed: {$total} assertions.\n";
}
