<?php
/**
 * Pure-PHP smoke test for CreateFlowAbility / CreatePipelineAbility resolving
 * the owning agent when no explicit agent_id is supplied
 * (Extra-Chill/data-machine #2481).
 *
 * Run with: php tests/create-flow-resolves-owning-agent-smoke.php
 *
 * The bug: agent-first scoping (#735) added `agent_id bigint DEFAULT NULL` to
 * the flows/pipelines tables and made agent-scoped reads filter
 * `WHERE agent_id = %d`, but the WRITE path only persisted agent_id when the
 * caller passed one explicitly (`null !== $agent_id && $agent_id > 0`). Any
 * flow/pipeline created without an explicit agent_id landed as NULL and became
 * invisible to every agent-scoped query — agent-filtered listings/counts, and
 * silently dropped from `agent export` / bundle round-trips.
 *
 * The fix: when no explicit agent_id is supplied, resolve the owning agent via
 * the shared, context-agnostic resolver datamachine_resolve_agent_id() before
 * deciding whether to persist the column. NULL stays valid only when no agent
 * context can be resolved (genuine unowned/system flow).
 *
 * This smoke replicates the resolution branch that both abilities now run,
 * matching the lightweight style of logger-agent-id-resolution-smoke.php.
 *
 * @package DataMachine\Tests
 */

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Stubbable resolver ────────────────────────────────────────────
//
// The real datamachine_resolve_agent_id() lives in the global namespace
// (inc/Engine/Logger.php) and consults PermissionHelper. Here we stub it
// to a controllable value so the test exercises the *ability's* branch
// logic, not the resolver internals (those are covered by
// logger-agent-id-resolution-smoke.php).
$GLOBALS['__smoke_resolved_agent_id'] = null;

function datamachine_resolve_agent_id( array $context = array() ): ?int {
	return $GLOBALS['__smoke_resolved_agent_id'];
}

/**
 * Replicates the exact agent_id resolution branch added to
 * CreateFlowAbility::executeSingle() and CreatePipelineAbility::executeSingle().
 * Returns the agent_id that would be persisted (null => column omitted =>
 * row stays DEFAULT NULL).
 *
 * @param int|null $input_agent_id The agent_id from caller input (or null).
 * @return int|null Effective agent_id to persist.
 */
function resolve_effective_agent_id( ?int $input_agent_id ): ?int {
	$agent_id = ( null !== $input_agent_id ) ? (int) $input_agent_id : null;

	if ( ( null === $agent_id || $agent_id <= 0 ) && function_exists( 'datamachine_resolve_agent_id' ) ) {
		$resolved_agent_id = datamachine_resolve_agent_id();
		if ( null !== $resolved_agent_id && $resolved_agent_id > 0 ) {
			$agent_id = $resolved_agent_id;
		}
	}

	// Persistence guard from create_flow()/create_pipeline(): column only
	// written when non-null and > 0.
	return ( null !== $agent_id && $agent_id > 0 ) ? $agent_id : null;
}

// ─── Test harness ──────────────────────────────────────────────────

$pass     = 0;
$fail     = 0;
$failures = array();

function smoke_assert( string $label, bool $cond, string $detail = '' ): void {
	global $pass, $fail, $failures;
	if ( $cond ) {
		$pass++;
		echo "  ✓ {$label}\n";
	} else {
		$fail++;
		$failures[] = array( 'label' => $label, 'detail' => $detail );
		echo "  ✗ {$label}" . ( $detail ? "\n      {$detail}" : '' ) . "\n";
	}
}

// ─── Test 1: explicit agent_id always wins ─────────────────────────

echo "[1] Explicit agent_id is honored and never overridden\n";

$GLOBALS['__smoke_resolved_agent_id'] = 6; // active agent context present
$result = resolve_effective_agent_id( 3 );
smoke_assert(
	'explicit agent_id=3 persists even when resolver would return 6',
	3 === $result,
	'got ' . var_export( $result, true )
);

$GLOBALS['__smoke_resolved_agent_id'] = null;
$result = resolve_effective_agent_id( 42 );
smoke_assert(
	'explicit agent_id=42 persists when resolver returns null',
	42 === $result,
	'got ' . var_export( $result, true )
);

// ─── Test 2: the fix — NULL input resolves to the owning agent ─────

echo "\n[2] No explicit agent_id → resolves owning agent (the fix)\n";

$GLOBALS['__smoke_resolved_agent_id'] = 6;
$result = resolve_effective_agent_id( null );
smoke_assert(
	'NULL input resolves to active/owning agent (6) instead of persisting NULL',
	6 === $result,
	'got ' . var_export( $result, true ) . ' — pre-fix bug: flow orphaned with agent_id=NULL'
);

$GLOBALS['__smoke_resolved_agent_id'] = 6;
$result = resolve_effective_agent_id( 0 );
smoke_assert(
	'zero agent_id is treated as unset and resolves to owning agent (6)',
	6 === $result,
	'got ' . var_export( $result, true )
);

// ─── Test 3: NULL stays valid for genuine unowned/system flows ─────

echo "\n[3] NULL remains valid when no agent context resolves\n";

$GLOBALS['__smoke_resolved_agent_id'] = null;
$result = resolve_effective_agent_id( null );
smoke_assert(
	'no input + no resolvable agent context → persists NULL (unowned/system)',
	null === $result,
	'got ' . var_export( $result, true )
);

$GLOBALS['__smoke_resolved_agent_id'] = 0; // defensive: resolver returns 0
$result = resolve_effective_agent_id( null );
smoke_assert(
	'resolver returning 0 is rejected → persists NULL, not 0',
	null === $result,
	'got ' . var_export( $result, true )
);

// ─── Test 4: regression — events-bot batch-import scenario ─────────

echo "\n[4] Regression — programmatic batch import without explicit agent\n";

// Mirrors the 72-flow blog-7 incident: a batch import created flows on
// events-bot (agent_id=6) pipelines without passing agent_id, persisting
// NULL and orphaning them from `agent export events-bot`. With an active
// agent context installed (as a runtime/CLI run would have), the resolver
// returns 6 and the flows are correctly owned.
$GLOBALS['__smoke_resolved_agent_id'] = 6;
$result = resolve_effective_agent_id( null );
smoke_assert(
	'batch-imported flow with active events-bot context is owned by 6, not orphaned',
	6 === $result,
	'got ' . var_export( $result, true ) . ' — pre-fix: 72 flows shipped as NULL, lost on bundle round-trip'
);

// ─── Done ──────────────────────────────────────────────────────────

echo "\n{$pass} passed, {$fail} failed\n";

if ( $fail > 0 ) {
	echo "\nFailures:\n";
	foreach ( $failures as $f ) {
		echo "  - {$f['label']}" . ( $f['detail'] ? ": {$f['detail']}" : '' ) . "\n";
	}
	exit( 1 );
}

}
