<?php
/**
 * Smoke coverage for jobs cleanup status matching (#2858).
 *
 * Verifies that count_old_jobs() / delete_old_jobs() match compound failed
 * statuses (`failed - x`, `failed: y`) and bare `failed`, that the completed
 * IN-optimization is unchanged, and that the age filter protects recent jobs.
 *
 * Runs via: php tests/jobs-cleanup-status-variants-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action() {
		// no-op: bootstrap-unit.php stubs add_action but not do_action.
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Core\Database\Jobs\Jobs;

/**
 * Minimal in-memory wpdb double that evaluates the COUNT/DELETE queries
 * issued by Jobs::count_old_jobs() / Jobs::delete_old_jobs() against a row
 * list, so the smoke test proves real matching behavior rather than only
 * SQL shape.
 */
final class DM_Jobs_Cleanup_Test_Wpdb {

	public string $prefix = 'wp_';
	public string $last_query = '';
	public array $rows = array();
	public string $table = '`wp_datamachine_jobs`';

	public function esc_like( string $value ): string {
		return str_replace( array( '\\', '%', '_' ), array( '\\\\', '\%', '\_' ), $value );
	}

	public function prepare( string $sql, ...$args ): string {
		// Approximate $wpdb->prepare() for the placeholders these methods use:
		// %i (identifier, backtick-wrapped), %s (quoted), %d (int), and the
		// variadic IN(...) placeholder list built by the callers.
		$offset  = 0;
		$arg_pos = 0;
		$out     = '';

		while ( false !== ( $at = strpos( $sql, '%', $offset ) ) ) {
			$out  .= substr( $sql, $offset, $at - $offset );
			$spec  = $sql[ $at + 1 ] ?? '';
			$arg   = $args[ $arg_pos ] ?? '';

			switch ( $spec ) {
				case 'i':
					$out .= '`' . $arg . '`';
					++$arg_pos;
					break;
				case 's':
					$out .= "'" . $arg . "'";
					++$arg_pos;
					break;
				case 'd':
					$out .= (string) (int) $arg;
					++$arg_pos;
					break;
				default:
					// Preserve unrecognized sequences (e.g. the literal `%s`
					// inside an already-built IN placeholder string is not a
					// $wpdb placeholder — it was consumed by implode above).
					$out .= '%' . $spec;
					break;
			}
			$offset = $at + 2;
		}
		$out .= substr( $sql, $offset );

		return $out;
	}

	public function get_var( string $sql ): ?string {
		$this->last_query = $sql;
		return (string) $this->count_matching( $sql );
	}

	public function query( string $sql ): int {
		$this->last_query = $sql;
		$matched = $this->matching_indexes( $sql );
		foreach ( $matched as $idx ) {
			unset( $this->rows[ $idx ] );
		}
		$this->rows = array_values( $this->rows );

		return count( $matched );
	}

	private function count_matching( string $sql ): int {
		return count( $this->matching_indexes( $sql ) );
	}

	private function matching_indexes( string $sql ): array {
		$criteria = $this->parse_where( $sql );
		$hits     = array();

		foreach ( $this->rows as $idx => $row ) {
			if ( $this->row_matches( $row, $criteria ) ) {
				$hits[] = $idx;
			}
		}

		return $hits;
	}

	private function parse_where( string $sql ): array {
		$criteria = array();

		if ( preg_match( "/status LIKE '([^']+)'/", $sql, $m ) ) {
			$criteria['like'] = $m[1];
		} elseif ( preg_match( '/status IN \(([^)]+)\)/', $sql, $m ) ) {
			$criteria['in'] = array_map(
				static function ( string $v ): string {
					return trim( $v, "' \"" );
				},
				explode( ',', $m[1] )
			);
		}

		if ( preg_match( "/created_at < '([^']+)'/", $sql, $m ) ) {
			$criteria['cutoff'] = $m[1];
		}

		return $criteria;
	}

	private function row_matches( array $row, array $criteria ): bool {
		$status = $row['status'] ?? '';

		if ( isset( $criteria['like'] ) ) {
			if ( ! $this->like_match( $status, $criteria['like'] ) ) {
				return false;
			}
		} elseif ( isset( $criteria['in'] ) ) {
			if ( ! in_array( $status, $criteria['in'], true ) ) {
				return false;
			}
		}

		if ( isset( $criteria['cutoff'] ) ) {
			if ( ( $row['created_at'] ?? '' ) >= $criteria['cutoff'] ) {
				return false;
			}
		}

		return true;
	}

	private function like_match( string $subject, string $pattern ): bool {
		// Convert a SQL LIKE pattern (% and _ wildcards) to a PHP regex.
		$regex = '';
		$len   = strlen( $pattern );
		for ( $i = 0; $i < $len; ++$i ) {
			$ch = $pattern[ $i ];
			if ( '\\' === $ch && isset( $pattern[ $i + 1 ] ) ) {
				$regex .= preg_quote( $pattern[ ++$i ], '#' );
				continue;
			}
			if ( '%' === $ch ) {
				$regex .= '.*';
			} elseif ( '_' === $ch ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $ch, '#' );
			}
		}

		return 1 === preg_match( '#^' . $regex . '$#', $subject );
	}
}

$failed = 0;
$total  = 0;

function dm_cleanup_assert( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

function dm_jobs_with( array $rows ): Jobs {
	global $wpdb;
	$wpdb = new DM_Jobs_Cleanup_Test_Wpdb();
	$wpdb->rows = $rows;

	return new Jobs();
}

echo "=== jobs-cleanup-status-variants-smoke ===\n";

/*
 * Build a representative row set mirroring the production evidence in #2858:
 *  - compound failures (the only real failure shape in production)
 *  - one bare `failed` row (defensive: must still match)
 *  - completed variants (IN optimization must stay intact)
 *  - a recent failed row that must be protected by the age filter
 *  - a non-terminal status that must never be touched
 */
$old_ts   = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
$recent_ts = gmdate( 'Y-m-d H:i:s', time() - ( 1 * DAY_IN_SECONDS ) );

$base_rows = array(
	array( 'status' => 'failed - packet_failure', 'created_at' => $old_ts ),
	array( 'status' => 'failed: Daily memory completion policy was not satisfied', 'created_at' => $old_ts ),
	array( 'status' => 'failed', 'created_at' => $old_ts ),
	array( 'status' => 'completed', 'created_at' => $old_ts ),
	array( 'status' => 'completed_no_items', 'created_at' => $old_ts ),
	array( 'status' => 'agent_skipped', 'created_at' => $old_ts ),
	array( 'status' => 'failed - recent', 'created_at' => $recent_ts ),
	array( 'status' => 'processing', 'created_at' => $old_ts ),
);

// --- count_old_jobs('failed') must see all 3 old failed rows (compound + bare) ---
$jobs = dm_jobs_with( $base_rows );
$count = $jobs->count_old_jobs( 'failed', 7 );
dm_cleanup_assert(
	'count_old_jobs(failed) matches compound + bare failed (3)',
	3 === $count,
	"expected 3, got {$count}; query: " . $GLOBALS['wpdb']->last_query
);
dm_cleanup_assert(
	'count_old_jobs(failed) uses LIKE (not IN) for the failed prefix',
	false !== strpos( $GLOBALS['wpdb']->last_query, "status LIKE 'failed%'" )
		&& false === strpos( $GLOBALS['wpdb']->last_query, "status IN ('failed')" )
);
dm_cleanup_assert(
	'count_old_jobs(failed) applies the age cutoff',
	false !== strpos( $GLOBALS['wpdb']->last_query, 'created_at <' )
);

// --- delete_old_jobs('failed') removes exactly the 3 old failed rows ---
$jobs = dm_jobs_with( $base_rows );
$deleted = $jobs->delete_old_jobs( 'failed', 7 );
dm_cleanup_assert(
	'delete_old_jobs(failed) deletes 3 old failed rows',
	3 === $deleted,
	"expected 3, got {$deleted}; query: " . $GLOBALS['wpdb']->last_query
);
dm_cleanup_assert(
	'delete_old_jobs(failed) uses LIKE for the failed prefix',
	false !== strpos( $GLOBALS['wpdb']->last_query, "status LIKE 'failed%'" )
);

// Recent failed job survived the age-gated delete.
$remaining_statuses = array_column( $GLOBALS['wpdb']->rows, 'status' );
dm_cleanup_assert(
	'recent failed job is NOT deleted (age filter protects it)',
	in_array( 'failed - recent', $remaining_statuses, true ),
	'remaining: ' . implode( ',', $remaining_statuses )
);

// Non-terminal statuses are never matched by a failed cleanup pass.
dm_cleanup_assert(
	'processing job is not touched by failed cleanup',
	in_array( 'processing', $remaining_statuses, true )
);
// Completed variants survive a failed cleanup pass.
dm_cleanup_assert(
	'completed variants are not touched by failed cleanup',
	in_array( 'completed', $remaining_statuses, true )
	&& in_array( 'completed_no_items', $remaining_statuses, true )
	&& in_array( 'agent_skipped', $remaining_statuses, true )
);

// --- completed path keeps the IN optimization and matches its 3 variants ---
$jobs = dm_jobs_with( $base_rows );
$count = $jobs->count_old_jobs( 'completed', 7 );
dm_cleanup_assert(
	'count_old_jobs(completed) matches 3 completed variants',
	3 === $count,
	"expected 3, got {$count}; query: " . $GLOBALS['wpdb']->last_query
);
dm_cleanup_assert(
	'count_old_jobs(completed) still uses the IN optimization',
	false !== strpos( $GLOBALS['wpdb']->last_query, "status IN ('completed','completed_no_items','agent_skipped')" )
);

$jobs = dm_jobs_with( $base_rows );
$deleted = $jobs->delete_old_jobs( 'completed', 7 );
dm_cleanup_assert(
	'delete_old_jobs(completed) deletes 3 completed rows',
	3 === $deleted,
	"expected 3, got {$deleted}; query: " . $GLOBALS['wpdb']->last_query
);
$remaining_statuses = array_column( $GLOBALS['wpdb']->rows, 'status' );
dm_cleanup_assert(
	'delete_old_jobs(completed) leaves failed rows untouched',
	in_array( 'failed', $remaining_statuses, true )
	&& in_array( 'failed - packet_failure', $remaining_statuses, true )
);

// --- empty/invalid inputs are rejected without touching rows ---
$jobs = dm_jobs_with( $base_rows );
dm_cleanup_assert( 'count_old_jobs rejects zero days (returns 0)', 0 === $jobs->count_old_jobs( 'failed', 0 ) );
dm_cleanup_assert( 'count_old_jobs rejects empty pattern (returns 0)', 0 === $jobs->count_old_jobs( '', 7 ) );
dm_cleanup_assert( 'delete_old_jobs rejects zero days (returns false)', false === $jobs->delete_old_jobs( 'failed', 0 ) );
dm_cleanup_assert( 'delete_old_jobs rejects empty pattern (returns false)', false === $jobs->delete_old_jobs( '', 7 ) );

if ( $failed > 0 ) {
	echo "\njobs-cleanup-status-variants-smoke FAILED: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\njobs-cleanup-status-variants-smoke PASSED: {$total} assertions.\n";
