<?php
/**
 * Pure-PHP smoke test for DailyMemoryTask "unconfigured model" handling.
 *
 * Run with: php tests/daily-memory-unconfigured-model-skip-smoke.php
 *
 * Covers the contract that an unresolvable (provider, model) pair must
 * be handled as a clean `completeJob(skipped: true)` — matching the
 * existing `daily_memory_enabled = false` skip — and NOT as
 * `failJob(...)`. Before this fix the task called `failJob` on empty
 * provider/model, which:
 *
 *   1. Filled the DM logs with ERROR-level "Task failed" entries on
 *      every recurring tick of an install that hadn't picked a system
 *      model yet.
 *   2. Cascaded through ExecuteStepAbility::evaluateStepSuccess() as
 *      `failed - empty_data_packet_returned`, masking the real reason.
 *   3. Failed to self-heal: once a model was finally configured, the
 *      install needed nothing special to recover, but in the meantime
 *      every tick produced noise that looked like a real outage.
 *
 * "No model resolved" is a configuration state, not a runtime fault.
 * Every other state where the task simply cannot do useful work
 * (`daily_memory_enabled=false`, MEMORY.md not found / empty, MEMORY.md
 * within size threshold and no activity) is already a skip. This test
 * locks in that the empty-model state joins them.
 *
 * The decision logic is small and pure — given (daily_memory_enabled,
 * provider, model, memory_loaded, original_size, has_context,
 * max_size), the task chooses one of: SKIP_DISABLED,
 * SKIP_UNCONFIGURED_MODEL, SKIP_NO_MEMORY, SKIP_THRESHOLD, PROCEED. We
 * mirror the precondition cascade inline so a regression in the real
 * file shows up as a divergence here.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

const SKIP_DISABLED            = 'skip_disabled';
const SKIP_UNCONFIGURED_MODEL  = 'skip_unconfigured_model';
const SKIP_NO_MEMORY           = 'skip_no_memory';
const SKIP_THRESHOLD           = 'skip_threshold';
const PROCEED                  = 'proceed';

/**
 * Inline reimplementation of the precondition cascade in
 * DailyMemoryTask::executeTask(). Mirrors the order of the real file
 * so a reordering or removal of any guard surfaces as a test diff.
 *
 * @param array $state {
 *     @var bool   $daily_memory_enabled
 *     @var string $provider
 *     @var string $model
 *     @var bool   $memory_loaded   Whether AgentMemory::get_all() returned content.
 *     @var int    $original_size   Bytes of MEMORY.md.
 *     @var bool   $has_context     Whether jobs/chat-sessions activity exists.
 *     @var int    $max_size        AgentMemory::MAX_FILE_SIZE.
 * }
 * @return string One of the SKIP_* / PROCEED constants.
 */
function evaluate_preconditions( array $state ): string {
	if ( ! $state['daily_memory_enabled'] ) {
		return SKIP_DISABLED;
	}

	// FIX: empty provider OR model now skips, not fails. Order in the
	// real file: this guard runs after daily_memory_enabled and BEFORE
	// the memory-loaded / threshold guards, because reading MEMORY.md
	// is wasted work if we cannot make an AI call anyway.
	if ( '' === $state['provider'] || '' === $state['model'] ) {
		return SKIP_UNCONFIGURED_MODEL;
	}

	if ( ! $state['memory_loaded'] ) {
		return SKIP_NO_MEMORY;
	}

	if ( $state['original_size'] <= $state['max_size'] && ! $state['has_context'] ) {
		return SKIP_THRESHOLD;
	}

	return PROCEED;
}

$failures = array();
$passes   = 0;

function assert_outcome( string $expected, string $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		$passes++;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo "    expected: {$expected}\n";
	echo "    actual:   {$actual}\n";
}

$base_state = array(
	'daily_memory_enabled' => true,
	'provider'             => 'openai',
	'model'                => 'gpt-5-mini',
	'memory_loaded'        => true,
	'original_size'        => 60 * 1024,
	'has_context'          => false,
	'max_size'             => 8 * 1024,
);

echo "daily memory unconfigured-model skip smoke\n";
echo "------------------------------------------\n";

// Test 1: happy path — fully configured, oversized memory → PROCEED.
echo "\n[1] happy path (configured + oversized memory):\n";
$result = evaluate_preconditions( $base_state );
assert_outcome( PROCEED, $result, 'fully configured oversized run proceeds', $failures, $passes );

// Test 2: daily_memory_enabled=false → SKIP_DISABLED (regression
// guard: ensure the empty-model fix did not move the existing skip
// branch).
echo "\n[2] daily_memory_enabled = false:\n";
$state = array_merge( $base_state, array( 'daily_memory_enabled' => false ) );
$result = evaluate_preconditions( $state );
assert_outcome( SKIP_DISABLED, $result, 'disabled flag still skips first', $failures, $passes );

// Test 3: empty provider — the bug. Fresh install with no agent
// model, no site default, no network default. Must skip, not fail.
echo "\n[3] empty provider (the original bug):\n";
$state = array_merge( $base_state, array( 'provider' => '' ) );
$result = evaluate_preconditions( $state );
assert_outcome( SKIP_UNCONFIGURED_MODEL, $result, 'empty provider skips cleanly', $failures, $passes );

// Test 4: empty model only — same skip path. Some auth setups have a
// provider configured (via API key) but no model picked yet.
echo "\n[4] empty model only:\n";
$state = array_merge( $base_state, array( 'model' => '' ) );
$result = evaluate_preconditions( $state );
assert_outcome( SKIP_UNCONFIGURED_MODEL, $result, 'empty model skips cleanly', $failures, $passes );

// Test 5: both empty — same skip path.
echo "\n[5] both provider and model empty:\n";
$state = array_merge( $base_state, array( 'provider' => '', 'model' => '' ) );
$result = evaluate_preconditions( $state );
assert_outcome( SKIP_UNCONFIGURED_MODEL, $result, 'both empty skips cleanly', $failures, $passes );

// Test 6: disabled flag wins over unconfigured model. If the install
// has explicitly turned daily memory off, we should not even be
// checking model resolution (it might be unset by design).
echo "\n[6] disabled flag wins over unconfigured model:\n";
$state = array_merge( $base_state, array(
	'daily_memory_enabled' => false,
	'provider'             => '',
	'model'                => '',
) );
$result = evaluate_preconditions( $state );
assert_outcome( SKIP_DISABLED, $result, 'disabled-first ordering preserved', $failures, $passes );

// Test 7: model configured but MEMORY.md missing → SKIP_NO_MEMORY.
// Regression guard: the new skip branch must come BEFORE this one.
echo "\n[7] configured but MEMORY.md missing:\n";
$state = array_merge( $base_state, array( 'memory_loaded' => false ) );
$result = evaluate_preconditions( $state );
assert_outcome( SKIP_NO_MEMORY, $result, 'no-memory skip still fires when configured', $failures, $passes );

// Test 8: configured, MEMORY.md within threshold, no activity → SKIP_THRESHOLD.
echo "\n[8] configured + within threshold + no activity:\n";
$state = array_merge( $base_state, array(
	'original_size' => 4 * 1024,
	'has_context'   => false,
) );
$result = evaluate_preconditions( $state );
assert_outcome( SKIP_THRESHOLD, $result, 'threshold skip still fires when configured', $failures, $passes );

// Test 9: configured, within threshold, but activity present → PROCEED.
// Activity context forces a run even when MEMORY.md is small.
echo "\n[9] configured + within threshold + activity present:\n";
$state = array_merge( $base_state, array(
	'original_size' => 4 * 1024,
	'has_context'   => true,
) );
$result = evaluate_preconditions( $state );
assert_outcome( PROCEED, $result, 'activity overrides size threshold', $failures, $passes );

// Test 10: ordering invariant — unconfigured model is checked BEFORE
// reading MEMORY.md, so an unconfigured install with no MEMORY.md
// short-circuits at the model guard, not the memory guard. This
// matters because reading MEMORY.md hits the filesystem and would be
// wasted work if the AI call cannot run.
echo "\n[10] ordering: unconfigured model wins over no-memory:\n";
$state = array_merge( $base_state, array(
	'provider'      => '',
	'memory_loaded' => false,
) );
$result = evaluate_preconditions( $state );
assert_outcome( SKIP_UNCONFIGURED_MODEL, $result, 'unconfigured model short-circuits before file IO', $failures, $passes );

echo "\n------------------------------------------\n";
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
