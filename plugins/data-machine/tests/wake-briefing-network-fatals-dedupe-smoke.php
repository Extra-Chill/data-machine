<?php
/**
 * Smoke coverage for the network-briefing PHP-fatals dedup fix (#3522):
 * gatherNetworkSignals() must scan the shared wp-content/debug.log exactly
 * once and hoist the result into a single "**network-wide**" line, instead
 * of repeating it once per site under the switch_to_blog() loop.
 *
 * Run with: php tests/wake-briefing-network-fatals-dedupe-smoke.php
 *
 * On an 11-site network, WordPress multisite shares one debug.log file
 * across every site (WP_CONTENT_DIR and resolveDebugLogPath() are both
 * network-wide, not per-blog). Before this fix, gatherNetworkSignals()
 * called gatherSiteSignals() — which unconditionally scanned debug.log —
 * once per switch_to_blog() iteration, so the identical fatal signature
 * appeared on every single per-site line. This test builds a small
 * 3-site fixture (two quiet sites plus one site carrying genuinely
 * site-specific facts, mirroring the events.extrachill.com shape from the
 * issue) and asserts:
 *
 *   1. The fatal is hoisted into exactly one "**network-wide**" line.
 *   2. That line appears first, ahead of the per-site lines.
 *   3. No per-site line repeats the fatal text.
 *   4. A signature genuinely unique to one site (stuck jobs, repeated
 *      failures, grouped errors) still renders only on that site's line —
 *      hoisting the network-global fact must not swallow real per-site
 *      signal.
 *   5. Single-site scope (gatherSiteSignals() called directly, the
 *      non-network path) is unaffected: fatals still render there by
 *      default, since no duplication exists outside the network loop.
 *
 * Dependency-free like the other smoke tests: in-memory site/blog stubs, a
 * real temp debug.log, and a hand-rolled fake $wpdb keyed by the currently
 * "switched to" blog id.
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

	// -----------------------------------------------------------------------
	// Multisite stubs: three sites, a switch_to_blog() stack, and a
	// network-wide home_url() lookup so siteLabel() resolves per blog.
	// -----------------------------------------------------------------------

	$GLOBALS['__wake_blog_stack'] = array( 1 );
	$GLOBALS['__wake_site_urls']  = array(
		1 => 'https://extrachill.com',
		2 => 'https://community.extrachill.com',
		7 => 'https://events.extrachill.com',
	);

	function get_current_blog_id(): int {
		return end( $GLOBALS['__wake_blog_stack'] );
	}

	function switch_to_blog( int $blog_id ): bool {
		$GLOBALS['__wake_blog_stack'][] = $blog_id;
		return true;
	}

	function restore_current_blog(): bool {
		if ( count( $GLOBALS['__wake_blog_stack'] ) > 1 ) {
			array_pop( $GLOBALS['__wake_blog_stack'] );
		}
		return true;
	}

	function get_sites( array $args = array() ): array {
		unset( $args );
		return array_keys( $GLOBALS['__wake_site_urls'] );
	}

	function home_url( string $path = '' ): string {
		return $GLOBALS['__wake_site_urls'][ get_current_blog_id() ] . $path;
	}

	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}

	function is_multisite(): bool {
		return true;
	}

	// In-memory filter registry so apply_filters() returns overrides.
	$GLOBALS['__wake_filters'] = array();

	function apply_filters( string $hook, $value, ...$rest ) {
		if ( array_key_exists( $hook, $GLOBALS['__wake_filters'] ) ) {
			return $GLOBALS['__wake_filters'][ $hook ];
		}
		return $value;
	}
	function do_action( ...$args ) {}

	function wake_set_filter( string $hook, $value ): void {
		$GLOBALS['__wake_filters'][ $hook ] = $value;
	}

	// Force disk pressure permanently quiet — this fixture is about fatals.
	wake_set_filter( 'datamachine_wake_briefing_disk_min_free_pct', 0.0 );
	wake_set_filter( 'datamachine_wake_briefing_disk_min_free_bytes', 0.0 );

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

	echo "=== wake-briefing-network-fatals-dedupe-smoke ===\n";

	require_once $root . '/inc/Engine/AI/System/Tasks/SystemTask.php';
	require_once $root . '/inc/Engine/AI/System/Tasks/WakeBriefingTask.php';

	// -----------------------------------------------------------------------
	// Fake $wpdb: only blog 7 (events.extrachill.com) has genuinely
	// per-site facts — stuck jobs, a repeatedly-failing task type, and
	// grouped errors — mirroring the shape reported in issue #3522.
	// Blogs 1 and 2 are otherwise quiet aside from the shared fatal.
	// -----------------------------------------------------------------------

	$GLOBALS['wpdb'] = new class() {
		public string $prefix = 'wp_';

		public function prepare( string $query, ...$args ): array {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array(
				'sql'  => $query,
				'args' => $args,
			);
		}

		public function get_results( $prepared, $output = ARRAY_A ) {
			if ( 7 !== get_current_blog_id() ) {
				return array();
			}
			if ( str_contains( $prepared['sql'], 'GROUP BY task_type' ) ) {
				return array(
					array(
						'task_type' => 'unknown',
						'n'         => 128,
					),
				);
			}
			if ( str_contains( $prepared['sql'], 'GROUP BY message' ) ) {
				return array(
					array(
						'message' => 'Job marked as failed',
						'n'       => 134,
					),
				);
			}
			return array();
		}

		public function get_var( $prepared = null ) {
			if ( 7 === get_current_blog_id() ) {
				return '15';
			}
			return '0';
		}

		public function get_row( $prepared, $output = ARRAY_A ) {
			return null;
		}
	};

	// -----------------------------------------------------------------------
	// Shared debug.log: one PHP fatal signature, structurally identical no
	// matter which blog is "current" when it is read.
	// -----------------------------------------------------------------------

	$log    = tempnam( sys_get_temp_dir(), 'wake-network-debug-' );
	$now    = time();
	$recent = gmdate( 'd-M-Y H:i:s', $now - 600 ) . ' UTC';
	$since  = gmdate( 'Y-m-d H:i:s', $now - ( 24 * 3600 ) );

	$fatal_body = array_fill(
		0,
		8,
		"[{$recent}] PHP Fatal error:  Cannot redeclare function ec_link_page_owner_compatibility() in /var/www/wp-content/plugins/extrachill-link-pages/owner-reference.php on line 12"
	);
	file_put_contents( $log, implode( "\n", $fatal_body ) . "\n" );

	$prev_error_log = ini_get( 'error_log' );
	ini_set( 'error_log', $log );

	$ref  = new ReflectionClass( WakeBriefingTask::class );
	$task = $ref->newInstanceWithoutConstructor();

	$invoke = function ( string $method, array $args = array() ) use ( $ref, $task ) {
		$m = $ref->getMethod( $method );
		return $m->invoke( $task, ...$args );
	};

	// -----------------------------------------------------------------------
	// 1-4: gatherNetworkSignals() across the 3-site fixture.
	// -----------------------------------------------------------------------

	$lines = $invoke( 'gatherNetworkSignals', array( $since ) );

	$network_wide_lines = array_values(
		array_filter( $lines, static fn( $l ) => str_starts_with( (string) $l, '**network-wide**' ) )
	);
	wake_assert(
		'network-wide: exactly one hoisted line for the shared fatal',
		1 === count( $network_wide_lines ),
		'got: ' . json_encode( $lines )
	);

	$network_line = (string) ( $network_wide_lines[0] ?? '' );
	wake_assert(
		'network-wide: names the fatal count and signature',
		str_contains( $network_line, '8 PHP fatal(s)' )
			&& str_contains( $network_line, 'ec_link_page_owner_compatibility' ),
		"got: {$network_line}"
	);

	wake_assert(
		'network-wide: hoisted line renders before per-site lines',
		! empty( $lines ) && '**network-wide**' === substr( (string) $lines[0], 0, 16 ),
		'got: ' . json_encode( $lines )
	);

	$site_lines = array_values(
		array_filter( $lines, static fn( $l ) => ! str_starts_with( (string) $l, '**network-wide**' ) )
	);
	wake_assert(
		'per-site: no site line repeats the PHP-fatal text',
		0 === count( array_filter( $site_lines, static fn( $l ) => str_contains( (string) $l, 'PHP fatal' ) ) ),
		'got: ' . json_encode( $site_lines )
	);

	wake_assert(
		'per-site: exactly one site line (only events.extrachill.com has facts)',
		1 === count( $site_lines ),
		'got: ' . json_encode( $site_lines )
	);

	$events_line = (string) ( $site_lines[0] ?? '' );
	wake_assert(
		'per-site: the genuinely site-specific facts still render on their own site line',
		str_starts_with( $events_line, '**events.extrachill.com**' )
			&& str_contains( $events_line, '15 job(s) stuck in processing' )
			&& str_contains( $events_line, 'unknown' )
			&& str_contains( $events_line, '128' )
			&& str_contains( $events_line, '134 error(s) logged' ),
		"got: {$events_line}"
	);

	// -----------------------------------------------------------------------
	// 5. Single-site scope is unaffected: gatherSiteSignals() with its
	//    default $scan_fatals=true still includes the fatal directly (no
	//    network-wide hoist applies outside the multisite loop).
	// -----------------------------------------------------------------------

	$GLOBALS['__wake_blog_stack'] = array( 1 );
	$site_scope_signals           = $invoke( 'gatherSiteSignals', array( $since ) );
	wake_assert(
		'single-site scope: fatals still render directly (regression guard)',
		1 === count( array_filter( $site_scope_signals, static fn( $l ) => str_contains( (string) $l, '8 PHP fatal(s)' ) ) ),
		'got: ' . json_encode( $site_scope_signals )
	);

	// Explicit opt-out still works standalone (what gatherNetworkSignals()
	// relies on internally).
	$scan_disabled = $invoke( 'gatherSiteSignals', array( $since, false ) );
	wake_assert(
		'gatherSiteSignals($since, false): suppresses the fatals scan entirely',
		0 === count( array_filter( $scan_disabled, static fn( $l ) => str_contains( (string) $l, 'PHP fatal' ) ) ),
		'got: ' . json_encode( $scan_disabled )
	);

	ini_set( 'error_log', false === $prev_error_log ? '' : $prev_error_log );
	@unlink( $log );

	// -----------------------------------------------------------------------

	if ( $failed > 0 ) {
		echo "\nwake-briefing-network-fatals-dedupe-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nwake-briefing-network-fatals-dedupe-smoke passed: {$total} assertions.\n";
}
