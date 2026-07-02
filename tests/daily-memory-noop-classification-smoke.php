<?php
/**
 * Pure-PHP smoke test for DailyMemoryTask no-op vs genuine-failure
 * classification.
 *
 * Run with: php tests/daily-memory-noop-classification-smoke.php
 *
 * Covers DailyMemoryTask::isGenuineFailureResponse() — the single source of
 * truth for deciding, when a daily-memory conversation ends WITHOUT the
 * completion policy being satisfied, whether that is a real fault or a
 * legitimate "nothing memory-worthy this run" no-op.
 *
 * Background (issues #2783, #2827):
 *
 *   The conversation loop reports `metadata.datamachine.completed = false`
 *   both when the request actually failed (provider error, runtime exception,
 *   external interruption) AND when the model simply ran out of turns without
 *   producing a split the completion policy would accept. The latter is the
 *   common path for small, already-healthy MEMORY.md files. Pre-#2783 the task
 *   routed BOTH to failJob(), which:
 *
 *     1. Failed the `system` "Daily Memory" job every quiet day.
 *     2. Cascaded through the paired `pipeline_system_task` leg as
 *        `packet_failure` ("Step execution failed - empty data packet"),
 *        because the failed child job produced a failure result packet that
 *        StepExecutionResult::classify() rejected.
 *     3. Polluted error-rate metrics and the wake briefing (which counts jobs
 *        with status LIKE 'failed%' grouped by task_type).
 *
 *   #2783 added the guard so a no-op completeJob()s with skipped/no_change and
 *   only genuine faults still fail. Because the no-op sets skipped=true,
 *   SystemTaskStep marks success and emits a success result packet, so BOTH
 *   legs now complete. This test locks in the exact classification so a
 *   regression (e.g. a new substrate status accidentally treated as a
 *   failure, or the budget-exhaustion no-op accidentally treated as a fault)
 *   surfaces here.
 *
 * The classifier is pure and static, so we exercise the real production method
 * directly rather than mirroring its logic inline.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal WP shims so the class file (and its imports) can load without a
// full WordPress bootstrap. The classifier under test has no WP dependencies.
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		unset( $args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		unset( $hook );
		return $value;
	}
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes ): string {
		return $bytes . ' B';
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

// Only the status vocabulary and the task class are needed. Load the status
// constants and the task file directly; SystemTask (the parent) and the
// Agents API compaction classes are not touched by the pure classifier, but
// the class declaration must be loadable, so stub the parent minimally when
// the real one is unavailable.
require_once dirname( __DIR__ ) . '/inc/Engine/AI/DataMachineConversationStatus.php';

if ( ! class_exists( '\DataMachine\Engine\AI\System\Tasks\SystemTask' ) ) {
	// Declare a lightweight parent so DailyMemoryTask can be declared without
	// pulling the full engine. The classifier under test is static and never
	// touches parent behavior.
	eval(
		'namespace DataMachine\\Engine\\AI\\System\\Tasks; abstract class SystemTask {}'
	);
}
if ( ! interface_exists( '\DataMachine\Engine\AI\NaturalCompletionPolicyInterface' ) ) {
	eval(
		'namespace DataMachine\\Engine\\AI; interface NaturalCompletionPolicyInterface {}'
	);
}

// The task file `use`s several Agents API + Core classes at namespace scope.
// PHP resolves `use` lazily (only on actual reference), so unresolved imports
// do not fatal at include time as long as the referenced symbols are never
// used by the code path we exercise. isGenuineFailureResponse() references
// only DataMachineConversationStatus, already loaded above.
require_once dirname( __DIR__ ) . '/inc/Engine/AI/System/Tasks/DailyMemoryTask.php';

use DataMachine\Engine\AI\System\Tasks\DailyMemoryTask;
use DataMachine\Engine\AI\DataMachineConversationStatus;

$failures = array();
$passes   = 0;

function assert_bool( bool $expected, bool $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		$passes++;
		echo "  \xE2\x9C\x93 {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  \xE2\x9C\x97 {$name}\n";
	echo '    expected: ' . ( $expected ? 'true' : 'false' ) . "\n";
	echo '    actual:   ' . ( $actual ? 'true' : 'false' ) . "\n";
}

echo "daily memory no-op classification smoke\n";
echo "---------------------------------------\n";

// ── NO-OP cases (must NOT fail the job) ───────────────────────────────────

echo "\n[no-op] budget exhausted, no error (the common quiet-day path):\n";
assert_bool(
	false,
	DailyMemoryTask::isGenuineFailureResponse(
		array( 'status' => DataMachineConversationStatus::BUDGET_EXCEEDED, 'turn_count' => 3 ),
		''
	),
	'budget_exceeded with no error is a no-op',
	$failures,
	$passes
);

echo "\n[no-op] empty/absent status, no error:\n";
assert_bool(
	false,
	DailyMemoryTask::isGenuineFailureResponse( array( 'turn_count' => 3 ), '' ),
	'absent status is a no-op',
	$failures,
	$passes
);
assert_bool(
	false,
	DailyMemoryTask::isGenuineFailureResponse( array( 'status' => '' ), '' ),
	'empty status string is a no-op',
	$failures,
	$passes
);

echo "\n[no-op] max_turns_reached diagnostic status, no error:\n";
assert_bool(
	false,
	DailyMemoryTask::isGenuineFailureResponse(
		array( 'status' => DataMachineConversationStatus::MAX_TURNS_REACHED ),
		''
	),
	'max_turns_reached with no error is a no-op',
	$failures,
	$passes
);

echo "\n[no-op] runtime_tool_pending status, no error:\n";
assert_bool(
	false,
	DailyMemoryTask::isGenuineFailureResponse(
		array( 'status' => DataMachineConversationStatus::RUNTIME_TOOL_PENDING ),
		''
	),
	'runtime_tool_pending with no error is a no-op',
	$failures,
	$passes
);

// ── GENUINE FAILURE cases (must fail the job) ─────────────────────────────

echo "\n[fail] non-empty error string:\n";
assert_bool(
	true,
	DailyMemoryTask::isGenuineFailureResponse(
		array( 'status' => DataMachineConversationStatus::BUDGET_EXCEEDED ),
		'provider request timed out'
	),
	'non-empty error string fails even with budget_exceeded status',
	$failures,
	$passes
);

echo "\n[fail] error_code present:\n";
assert_bool(
	true,
	DailyMemoryTask::isGenuineFailureResponse(
		array( 'status' => '', 'error_code' => 'provider_error' ),
		''
	),
	'error_code fails the job',
	$failures,
	$passes
);

echo "\n[fail] hard failure status: failed:\n";
assert_bool(
	true,
	DailyMemoryTask::isGenuineFailureResponse(
		array( 'status' => DataMachineConversationStatus::FAILED ),
		''
	),
	'failed status is a genuine failure',
	$failures,
	$passes
);

echo "\n[fail] hard failure status: interrupted (external interrupt, not budget):\n";
assert_bool(
	true,
	DailyMemoryTask::isGenuineFailureResponse(
		array( 'status' => DataMachineConversationStatus::INTERRUPTED ),
		''
	),
	'interrupted status is a genuine failure',
	$failures,
	$passes
);

echo "\n[fail] legacy 'error' status literal:\n";
assert_bool(
	true,
	DailyMemoryTask::isGenuineFailureResponse( array( 'status' => 'error' ), '' ),
	"'error' status is a genuine failure",
	$failures,
	$passes
);

// ── Invariant: budget_exceeded is the divider ─────────────────────────────
// The distinction that makes the whole fix correct: turn-budget exhaustion is
// a no-op, external interruption is a failure. If a future refactor collapses
// these two the daily-memory job starts failing every quiet day again.
echo "\n[invariant] budget_exceeded != interrupted:\n";
$budget_is_failure      = DailyMemoryTask::isGenuineFailureResponse( array( 'status' => DataMachineConversationStatus::BUDGET_EXCEEDED ), '' );
$interrupted_is_failure = DailyMemoryTask::isGenuineFailureResponse( array( 'status' => DataMachineConversationStatus::INTERRUPTED ), '' );
assert_bool(
	true,
	( false === $budget_is_failure ) && ( true === $interrupted_is_failure ),
	'budget_exceeded is a no-op while interrupted is a failure',
	$failures,
	$passes
);

echo "\n---------------------------------------\n";
$total = $passes + count( $failures );
echo "{$passes} / {$total} passed\n";

if ( ! empty( $failures ) ) {
	echo "Failures:\n";
	foreach ( $failures as $name ) {
		echo "  - {$name}\n";
	}
	exit( 1 );
}

echo "All checks passed.\n";
exit( 0 );
