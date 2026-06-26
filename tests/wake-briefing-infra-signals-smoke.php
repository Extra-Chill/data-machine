<?php
/**
 * Smoke coverage for the WakeBriefing infrastructure-layer signal gatherers
 * (#2799): PHP fatals (debug.log), disk pressure, and Action Scheduler bloat.
 *
 * Run with: php tests/wake-briefing-infra-signals-smoke.php
 *
 * Each gatherer obeys the same ruthless-terseness contract as the existing
 * three: ONE grouped markdown line when over its threshold, '' otherwise. This
 * test drives all three private gatherers via reflection against a fake $wpdb,
 * a real temp debug.log, and filterable disk thresholds — asserting each emits
 * a line when over the bar and empty when under, and that all three fail soft.
 *
 * Dependency-free like the other smoke tests: a tiny in-memory filter registry,
 * stubbed WP constants/functions, and a hand-rolled fake $wpdb.
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

namespace {

	use DataMachine\Engine\AI\System\Tasks\WakeBriefingTask;

	$root = dirname( __DIR__ );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/' );
	}
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	// In-memory filter registry so apply_filters() returns overrides.
	$GLOBALS['__wake_filters'] = array();

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$rest ) {
			if ( array_key_exists( $hook, $GLOBALS['__wake_filters'] ) ) {
				return $GLOBALS['__wake_filters'][ $hook ];
			}
			return $value;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ) {}
	}

	function wake_set_filter( string $hook, $value ): void {
		$GLOBALS['__wake_filters'][ $hook ] = $value;
	}
	function wake_clear_filters(): void {
		$GLOBALS['__wake_filters'] = array();
	}

	$failed = 0;
	$total  = 0;

	function wake_assert( string $name, bool $condition, string $detail = '' ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}
		++$failed;
		echo "  [FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}

	echo "=== wake-briefing-infra-signals-smoke ===\n";

	// SystemTask is the abstract parent; load it then the task itself.
	require_once $root . '/inc/Engine/AI/System/Tasks/SystemTask.php';
	require_once $root . '/inc/Engine/AI/System/Tasks/WakeBriefingTask.php';

	// WakeBriefingTask's constructor may need dependencies; we only exercise
	// private methods via reflection on a bare instance, so instantiate without
	// invoking the constructor.
	$ref  = new ReflectionClass( WakeBriefingTask::class );
	$task = $ref->newInstanceWithoutConstructor();

	$invoke = function ( string $method, array $args = array() ) use ( $ref, $task ) {
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $task, ...$args );
	};

	// -----------------------------------------------------------------------
	// 1. PHP fatals from debug.log.
	// -----------------------------------------------------------------------

	$log = tempnam( sys_get_temp_dir(), 'wake-debug-' );

	$now    = time();
	$recent = gmdate( 'd-M-Y H:i:s', $now - 600 ) . ' UTC';
	$old    = gmdate( 'd-M-Y H:i:s', $now - ( 72 * 3600 ) ) . ' UTC';
	$since  = gmdate( 'Y-m-d H:i:s', $now - ( 24 * 3600 ) );

	// Two in-window fatals sharing one signature, one with a distinct signature,
	// a multi-line trace folded into one, an out-of-window fatal (ignored), and
	// a warning (ignored — fatals only).
	$lines = array(
		"[{$recent}] PHP Fatal error:  Uncaught Error: Call to a member function claim_actions() on null in /var/www/wp-content/plugins/x/foo.php on line 42",
		'Stack trace:',
		'#0 /var/www/wp-content/plugins/x/foo.php(99): bar()',
		"[{$recent}] PHP Fatal error:  Uncaught Error: Call to a member function claim_actions() on null in /var/www/wp-content/plugins/x/foo.php on line 42",
		"[{$recent}] PHP Parse error:  syntax error, unexpected token in /var/www/wp-content/plugins/y/baz.php on line 7",
		"[{$old}] PHP Fatal error:  Old out-of-window fatal in /var/www/old.php on line 1",
		"[{$recent}] PHP Warning:  Undefined variable in /var/www/warn.php on line 5",
	);
	file_put_contents( $log, implode( "\n", $lines ) . "\n" );

	// WP_DEBUG_LOG is undefined in this harness, so resolveDebugLogPath() falls
	// back to the `error_log` ini target — point it at our temp log.
	$prev_error_log = ini_get( 'error_log' );
	ini_set( 'error_log', $log );

	$fatal_line = $invoke( 'getPhpFatals', array( $since ) );
	wake_assert(
		'fatals: emits a grouped line counting only in-window fatals (3)',
		is_string( $fatal_line ) && str_contains( $fatal_line, '3 PHP fatal(s)' ),
		"got: {$fatal_line}"
	);
	wake_assert(
		'fatals: top signature is the ×2 claim_actions group',
		str_contains( $fatal_line, '×2' ) && str_contains( $fatal_line, 'claim_actions' ),
		"got: {$fatal_line}"
	);
	wake_assert(
		'fatals: ignores warnings (no Undefined variable in output)',
		! str_contains( $fatal_line, 'Undefined variable' ),
		"got: {$fatal_line}"
	);

	// Under threshold: window starts AFTER all entries => empty.
	$future_since = gmdate( 'Y-m-d H:i:s', $now + 3600 );
	$fatal_empty  = $invoke( 'getPhpFatals', array( $future_since ) );
	wake_assert(
		'fatals: empty when no fatals fall inside the window',
		'' === $fatal_empty,
		"got: {$fatal_empty}"
	);

	// Fail-soft: unreadable/absent log => ''.
	ini_set( 'error_log', '/nonexistent/definitely/not/here.log' );
	$fatal_missing = $invoke( 'getPhpFatals', array( $since ) );
	wake_assert(
		'fatals: fail-soft empty string when debug.log is absent',
		'' === $fatal_missing
	);
	ini_set( 'error_log', false === $prev_error_log ? '' : $prev_error_log );
	@unlink( $log );

	// -----------------------------------------------------------------------
	// 2. Disk pressure (real filesystem, filter-driven thresholds).
	// -----------------------------------------------------------------------

	wake_clear_filters();

	// Force a trigger: demand 100% free (impossible) => always over the bar.
	wake_set_filter( 'datamachine_wake_briefing_disk_min_free_pct', 1.0 );
	wake_set_filter( 'datamachine_wake_briefing_disk_min_free_bytes', 0.0 );
	$disk_line = $invoke( 'getDiskPressure' );
	wake_assert(
		'disk: emits a "% full" line when free space is under the pct bar',
		is_string( $disk_line ) && str_contains( $disk_line, 'Disk ' ) && str_contains( $disk_line, 'full' ),
		"got: {$disk_line}"
	);

	// Force healthy: demand 0% free and 0 free bytes => never over the bar.
	wake_clear_filters();
	wake_set_filter( 'datamachine_wake_briefing_disk_min_free_pct', 0.0 );
	wake_set_filter( 'datamachine_wake_briefing_disk_min_free_bytes', 0.0 );
	$disk_empty = $invoke( 'getDiskPressure' );
	wake_assert(
		'disk: empty when free space clears both thresholds',
		'' === $disk_empty,
		"got: {$disk_empty}"
	);

	// -----------------------------------------------------------------------
	// 3. Action Scheduler bloat (fake $wpdb information_schema read).
	// -----------------------------------------------------------------------

	wake_clear_filters();

	$fake_wpdb = new class() {
		public string $prefix = 'wp_';

		/** @var array<string, array{n:int, bytes:int}> table_name => stats */
		public array $stats = array();

		public function prepare( string $query, ...$args ): array {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array(
				'sql'  => $query,
				'args' => $args,
			);
		}

		public function get_row( $prepared, $output = ARRAY_A ) {
			$table = (string) end( $prepared['args'] );
			if ( ! isset( $this->stats[ $table ] ) ) {
				return null;
			}
			return array(
				'n'     => $this->stats[ $table ]['n'],
				'bytes' => $this->stats[ $table ]['bytes'],
			);
		}
	};
	$GLOBALS['wpdb'] = $fake_wpdb;

	// Over-threshold: actions table is a 28.1M-row / 23.9GB runaway.
	$fake_wpdb->stats = array(
		'wp_actionscheduler_actions' => array(
			'n'     => 28100000,
			'bytes' => 23900000000,
		),
		'wp_actionscheduler_logs'    => array(
			'n'     => 5000,
			'bytes' => 1000000,
		),
	);
	$as_line = $invoke( 'getActionSchedulerBloat' );
	wake_assert(
		'AS bloat: emits a line naming the offending actions table',
		is_string( $as_line ) && str_contains( $as_line, 'Action Scheduler bloat' )
			&& str_contains( $as_line, 'wp_actionscheduler_actions' ),
		"got: {$as_line}"
	);
	wake_assert(
		'AS bloat: humanizes counts/bytes (28.1M rows / 23.9GB)',
		str_contains( $as_line, '28.1M rows' ) && str_contains( $as_line, '23.9GB' ),
		"got: {$as_line}"
	);
	wake_assert(
		'AS bloat: does NOT name the within-bounds logs table',
		! str_contains( $as_line, 'actionscheduler_logs' ),
		"got: {$as_line}"
	);

	// Under-threshold: both tables small => empty.
	$fake_wpdb->stats = array(
		'wp_actionscheduler_actions' => array(
			'n'     => 1000,
			'bytes' => 500000,
		),
		'wp_actionscheduler_logs'    => array(
			'n'     => 2000,
			'bytes' => 700000,
		),
	);
	$as_empty = $invoke( 'getActionSchedulerBloat' );
	wake_assert(
		'AS bloat: empty when both tables are within bounds',
		'' === $as_empty,
		"got: {$as_empty}"
	);

	// Row ceiling alone (rows over, bytes under) still triggers via filter.
	wake_set_filter( 'datamachine_wake_briefing_as_max_rows', 500 );
	$as_rowtrip = $invoke( 'getActionSchedulerBloat' );
	wake_assert(
		'AS bloat: row ceiling is filterable and trips independently of bytes',
		is_string( $as_rowtrip ) && str_contains( $as_rowtrip, 'Action Scheduler bloat' ),
		"got: {$as_rowtrip}"
	);

	// Fail-soft: missing table rows => empty.
	wake_clear_filters();
	$fake_wpdb->stats = array();
	$as_missing       = $invoke( 'getActionSchedulerBloat' );
	wake_assert(
		'AS bloat: fail-soft empty string when information_schema returns nothing',
		'' === $as_missing
	);

	// -----------------------------------------------------------------------

	if ( $failed > 0 ) {
		echo "\nwake-briefing-infra-signals-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nwake-briefing-infra-signals-smoke passed: {$total} assertions.\n";
}
