<?php
/**
 * Pure-PHP smoke test for JobsCommand::undo fan-out support.
 *
 * Run with: php tests/jobs-command-undo-fanout-smoke.php
 *
 * Verifies the empty-effects branching logic in JobsCommand::undo.
 *
 * Pre-fix: a parent job with empty engine_data['effects'] short-
 * circuited as 'no effects recorded' BEFORE calling SystemTask::undo,
 * making fan-out parents undoable only via their child job IDs (which
 * users do not see in the CLI output and have no clean way to discover).
 *
 * Post-fix: SystemTask::undo is always called. Its result envelope is
 * inspected — empty reverted/skipped/failed means a true no-op (logged
 * + skipped); any non-empty bucket is rendered through the existing
 * counter and per-effect logging path.
 *
 * The full live path requires WordPress + WP-CLI + DB, so this smoke
 * isolates the dispatch + envelope-inspection logic in a harness that
 * mirrors the production code byte-for-byte.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, $gmt = 0 ): string {
		return '2026-04-26 00:00:00';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0 ) {
		return json_encode( $data, $options );
	}
}

// ─── Test doubles ────────────────────────────────────────────────────

/**
 * Captures WP_CLI log/warning/error output for assertion.
 */
class Cli_Capture {
	public static array $log     = array();
	public static array $warning = array();

	public static function reset(): void {
		self::$log     = array();
		self::$warning = array();
	}

	public static function log( string $msg ): void {
		self::$log[] = $msg;
	}

	public static function warning( string $msg ): void {
		self::$warning[] = $msg;
	}
}

/**
 * Test-double SystemTask. Returns a canned undo envelope and records
 * whether undo() was called — the central gate this smoke covers.
 */
class Fake_System_Task {
	public bool $supports_undo;
	public array $envelope;
	public bool $undo_called = false;
	public int $undo_jid     = 0;

	public function __construct( bool $supports_undo, array $envelope ) {
		$this->supports_undo = $supports_undo;
		$this->envelope      = $envelope;
	}

	public function supportsUndo(): bool {
		return $this->supports_undo;
	}

	public function undo( int $jid, array $engine_data ): array {
		$this->undo_called = true;
		$this->undo_jid    = $jid;
		return $this->envelope;
	}
}

/**
 * Test-double Jobs database. Returns canned children for get_children.
 */
class Fake_Jobs_Db {
	public array $children_by_parent = array();
	public array $stored_engine_data = array();

	public function get_children( int $parent_job_id ): array {
		return $this->children_by_parent[ $parent_job_id ] ?? array();
	}

	public function store_engine_data( int $jid, array $data ): bool {
		$this->stored_engine_data[ $jid ] = $data;
		return true;
	}
}

// ─── Harness mirror of JobsCommand::undo per-job loop body ──────────

/**
 * Mirror of the post-fix per-job loop body. Returns counters as a tuple
 * plus the envelope produced for the engine_data write — letting the
 * smoke verify both sides of the new flow.
 *
 * Matches the production code path byte-for-byte except for the WP_CLI
 * indirection (uses Cli_Capture instead).
 */
function run_undo_loop_body(
	array $job,
	Fake_System_Task $task,
	Fake_Jobs_Db $jobs_db,
	bool $dry_run,
	bool $force,
	int &$total_reverted,
	int &$total_skipped,
	int &$total_failed
): void {
	$jid         = $job['job_id'] ?? 0;
	$engine_data = $job['engine_data'] ?? array();
	$jtype       = $engine_data['task_type'] ?? '';

	if ( ! $force && ! empty( $engine_data['undo'] ) ) {
		Cli_Capture::log( sprintf( '  Job #%d: already undone (use --force to re-undo).', $jid ) );
		++$total_skipped;
		return;
	}

	if ( ! $task->supportsUndo() ) {
		Cli_Capture::log( sprintf( '  Job #%d: task type "%s" does not support undo.', $jid, $jtype ) );
		++$total_skipped;
		return;
	}

	if ( $dry_run ) {
		$preview_effects = $engine_data['effects'] ?? array();
		if ( empty( $preview_effects ) ) {
			foreach ( $jobs_db->get_children( (int) $jid ) as $child ) {
				$child_data    = is_array( $child['engine_data'] ?? null ) ? $child['engine_data'] : array();
				$child_effects = $child_data['effects'] ?? array();
				$preview_effects = array_merge( $preview_effects, $child_effects );
			}
		}

		if ( empty( $preview_effects ) ) {
			Cli_Capture::log( sprintf( '  Job #%d (%s): no effects to undo.', $jid, $jtype ) );
			++$total_skipped;
			return;
		}

		Cli_Capture::log( sprintf( '  Job #%d (%s): would undo %d effect(s):', $jid, $jtype, count( $preview_effects ) ) );
		foreach ( $preview_effects as $effect ) {
			$type   = $effect['type'] ?? 'unknown';
			$target = $effect['target'] ?? array();
			Cli_Capture::log( sprintf( '    - %s → %s', $type, wp_json_encode( $target ) ) );
		}
		return;
	}

	Cli_Capture::log( sprintf( '  Job #%d (%s): undoing...', $jid, $jtype ) );
	$result = $task->undo( $jid, $engine_data );

	$reverted = is_array( $result['reverted'] ?? null ) ? $result['reverted'] : array();
	$skipped  = is_array( $result['skipped'] ?? null ) ? $result['skipped'] : array();
	$failed   = is_array( $result['failed'] ?? null ) ? $result['failed'] : array();

	if ( empty( $reverted ) && empty( $skipped ) && empty( $failed ) ) {
		Cli_Capture::log( sprintf( '  Job #%d (%s): no effects to undo.', $jid, $jtype ) );
		++$total_skipped;
		return;
	}

	foreach ( $reverted as $r ) {
		Cli_Capture::log( sprintf( '    ✓ %s reverted', $r['type'] ?? 'unknown' ) );
	}
	foreach ( $skipped as $s ) {
		Cli_Capture::log( sprintf( '    - %s skipped: %s', $s['type'] ?? 'unknown', $s['reason'] ?? '' ) );
	}
	foreach ( $failed as $f ) {
		Cli_Capture::warning( sprintf( '    ✗ %s failed: %s', $f['type'] ?? 'unknown', $f['reason'] ?? '' ) );
	}

	$total_reverted += count( $reverted );
	$total_skipped  += count( $skipped );
	$total_failed   += count( $failed );

	$engine_data['undo'] = array(
		'undone_at'        => current_time( 'mysql' ),
		'effects_reverted' => count( $reverted ),
		'effects_skipped'  => count( $skipped ),
		'effects_failed'   => count( $failed ),
	);
	$jobs_db->store_engine_data( $jid, $engine_data );
}

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "=== jobs-command-undo-fanout-smoke ===\n";

// -----------------------------------------------------------------
echo "\n[1] fan-out parent (empty own effects) — undo IS called, children's effects revert\n";
Cli_Capture::reset();
$parent = array(
	'job_id'      => 64,
	'engine_data' => array(
		'task_type' => 'wiki_maintain',
		'effects'   => array(),
	),
);
$task    = new Fake_System_Task( true, array(
	'success'  => true,
	'reverted' => array(
		array( 'type' => 'post_meta_set' ),
		array( 'type' => 'post_field_set' ),
	),
	'skipped'  => array(),
	'failed'   => array(),
) );
$jobs_db = new Fake_Jobs_Db();
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $parent, $task, $jobs_db, false, false, $reverted, $skipped, $failed );

dm_assert( $task->undo_called, 'task->undo() called even though parent has no own effects (the bug fix)' );
dm_assert( 64 === $task->undo_jid, 'undo received parent jid' );
dm_assert( 2 === $reverted, 'children effects counted as reverted' );
dm_assert( 0 === $skipped, 'no skipped' );
dm_assert( 0 === $failed, 'no failed' );
dm_assert( isset( $jobs_db->stored_engine_data[64]['undo'] ), 'undo metadata stamped on parent' );
dm_assert( 2 === $jobs_db->stored_engine_data[64]['undo']['effects_reverted'], 'undo metadata records 2 reverted' );

// -----------------------------------------------------------------
echo "\n[2] true no-op (no own effects, no child effects) — logged + skipped, no engine_data write\n";
Cli_Capture::reset();
$parent = array(
	'job_id'      => 70,
	'engine_data' => array( 'task_type' => 'noop_task' ),
);
// Task::undo returns the structured no-effects envelope SystemTask::undo
// emits when there is genuinely nothing to revert.
$task = new Fake_System_Task( true, array(
	'success'  => false,
	'error'    => 'No effects recorded for this job',
	'reverted' => array(),
	'skipped'  => array(),
	'failed'   => array(),
) );
$jobs_db = new Fake_Jobs_Db();
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $parent, $task, $jobs_db, false, false, $reverted, $skipped, $failed );

dm_assert( $task->undo_called, 'task->undo() still called (no premature short-circuit)' );
dm_assert( 0 === $reverted && 0 === $failed, 'no reverted, no failed' );
dm_assert( 1 === $skipped, 'true no-op increments skipped counter' );
dm_assert( ! isset( $jobs_db->stored_engine_data[70] ), 'no engine_data stamp on a true no-op (no undo metadata write)' );

$found_noop_log = false;
foreach ( Cli_Capture::$log as $line ) {
	if ( str_contains( $line, 'no effects to undo' ) ) {
		$found_noop_log = true;
		break;
	}
}
dm_assert( $found_noop_log, 'no-op surfaced as "no effects to undo" log line' );

// -----------------------------------------------------------------
echo "\n[3] leaf job with own effects — undo called, normal counter flow\n";
Cli_Capture::reset();
$leaf = array(
	'job_id'      => 50,
	'engine_data' => array(
		'task_type' => 'alt_text',
		'effects'   => array(
			array( 'type' => 'post_meta_set', 'target' => array( 'post_id' => 1 ) ),
		),
	),
);
$task = new Fake_System_Task( true, array(
	'success'  => true,
	'reverted' => array( array( 'type' => 'post_meta_set' ) ),
	'skipped'  => array(),
	'failed'   => array(),
) );
$jobs_db  = new Fake_Jobs_Db();
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $leaf, $task, $jobs_db, false, false, $reverted, $skipped, $failed );

dm_assert( $task->undo_called, 'leaf undo called' );
dm_assert( 1 === $reverted, 'leaf effect counted' );
dm_assert( 0 === $skipped && 0 === $failed, 'no skipped/failed' );

// -----------------------------------------------------------------
echo "\n[4] dry-run on fan-out parent — preview enumerates children's effects\n";
Cli_Capture::reset();
$parent = array(
	'job_id'      => 80,
	'engine_data' => array(
		'task_type' => 'wiki_maintain',
		'effects'   => array(),
	),
);
$task = new Fake_System_Task( true, array() ); // dry-run shouldn't call undo
$jobs_db = new Fake_Jobs_Db();
$jobs_db->children_by_parent[80] = array(
	array(
		'job_id'      => 81,
		'engine_data' => array(
			'effects' => array(
				array( 'type' => 'post_meta_set', 'target' => array( 'post_id' => 100 ) ),
				array( 'type' => 'post_field_set', 'target' => array( 'post_id' => 100 ) ),
			),
		),
	),
	array(
		'job_id'      => 82,
		'engine_data' => array(
			'effects' => array(
				array( 'type' => 'attachment_created', 'target' => array( 'post_id' => 101 ) ),
			),
		),
	),
);
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $parent, $task, $jobs_db, true, false, $reverted, $skipped, $failed );

dm_assert( ! $task->undo_called, 'dry-run does NOT call undo' );

$found_count_line = false;
foreach ( Cli_Capture::$log as $line ) {
	if ( str_contains( $line, 'would undo 3 effect(s)' ) ) {
		$found_count_line = true;
		break;
	}
}
dm_assert( $found_count_line, 'dry-run preview reports 3 effects (aggregated from 2 children)' );

$found_attachment_line = false;
foreach ( Cli_Capture::$log as $line ) {
	if ( str_contains( $line, 'attachment_created' ) ) {
		$found_attachment_line = true;
		break;
	}
}
dm_assert( $found_attachment_line, 'dry-run lists per-effect type from child #82' );

// -----------------------------------------------------------------
echo "\n[5] dry-run on fan-out parent with no children — logged as no-op, skipped++\n";
Cli_Capture::reset();
$parent = array(
	'job_id'      => 90,
	'engine_data' => array( 'task_type' => 'wiki_maintain' ),
);
$task    = new Fake_System_Task( true, array() );
$jobs_db = new Fake_Jobs_Db(); // no children
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $parent, $task, $jobs_db, true, false, $reverted, $skipped, $failed );

dm_assert( ! $task->undo_called, 'dry-run does not call undo' );
dm_assert( 1 === $skipped, 'dry-run no-op increments skipped' );

// -----------------------------------------------------------------
echo "\n[6] dry-run on leaf with own effects — preview reads engine_data['effects'] directly\n";
Cli_Capture::reset();
$leaf = array(
	'job_id'      => 95,
	'engine_data' => array(
		'task_type' => 'alt_text',
		'effects'   => array(
			array( 'type' => 'post_meta_set', 'target' => array( 'post_id' => 5 ) ),
		),
	),
);
$task    = new Fake_System_Task( true, array() );
$jobs_db = new Fake_Jobs_Db(); // children should NOT be consulted
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $leaf, $task, $jobs_db, true, false, $reverted, $skipped, $failed );

dm_assert( ! $task->undo_called, 'dry-run does not call undo' );
$found_count_line = false;
foreach ( Cli_Capture::$log as $line ) {
	if ( str_contains( $line, 'would undo 1 effect(s)' ) ) {
		$found_count_line = true;
		break;
	}
}
dm_assert( $found_count_line, 'dry-run leaf reports 1 effect from own engine_data (children path skipped)' );

// -----------------------------------------------------------------
echo "\n[7] task with already-undone marker — skipped without calling undo\n";
Cli_Capture::reset();
$parent = array(
	'job_id'      => 200,
	'engine_data' => array(
		'task_type' => 'wiki_maintain',
		'undo'      => array( 'undone_at' => '2026-04-25 00:00:00' ),
	),
);
$task    = new Fake_System_Task( true, array() );
$jobs_db = new Fake_Jobs_Db();
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $parent, $task, $jobs_db, false, false, $reverted, $skipped, $failed );

dm_assert( ! $task->undo_called, 'undo NOT called on already-undone parent (no force)' );
dm_assert( 1 === $skipped, 'already-undone increments skipped' );

// -----------------------------------------------------------------
echo "\n[8] --force overrides already-undone marker — undo IS called\n";
Cli_Capture::reset();
$task = new Fake_System_Task( true, array(
	'success'  => true,
	'reverted' => array( array( 'type' => 'post_meta_set' ) ),
	'skipped'  => array(),
	'failed'   => array(),
) );
$jobs_db = new Fake_Jobs_Db();
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $parent, $task, $jobs_db, false, true, $reverted, $skipped, $failed );

dm_assert( $task->undo_called, '--force calls undo on already-undone parent' );
dm_assert( 1 === $reverted, '--force re-undo counts reverted' );

// -----------------------------------------------------------------
echo "\n[9] envelope with mixed buckets (reverted + skipped + failed)\n";
Cli_Capture::reset();
$parent = array(
	'job_id'      => 300,
	'engine_data' => array( 'task_type' => 'wiki_maintain' ),
);
$task = new Fake_System_Task( true, array(
	'success'  => false,
	'reverted' => array( array( 'type' => 'post_meta_set' ) ),
	'skipped'  => array( array( 'type' => 'post_field_set', 'reason' => 'modified externally' ) ),
	'failed'   => array( array( 'type' => 'attachment_created', 'reason' => 'gone' ) ),
) );
$jobs_db  = new Fake_Jobs_Db();
$reverted = $skipped = $failed = 0;

run_undo_loop_body( $parent, $task, $jobs_db, false, false, $reverted, $skipped, $failed );

dm_assert( $task->undo_called, 'undo called' );
dm_assert( 1 === $reverted, '1 reverted' );
dm_assert( 1 === $skipped, '1 skipped' );
dm_assert( 1 === $failed, '1 failed' );
dm_assert( 1 === count( Cli_Capture::$warning ), 'failed effect surfaces as a CLI warning' );

echo "\n=== jobs-command-undo-fanout-smoke: ALL PASS ===\n";
