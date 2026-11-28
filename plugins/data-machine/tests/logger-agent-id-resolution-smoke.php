<?php
/**
 * Pure-PHP smoke test for `datamachine_resolve_agent_id()` priority ordering
 * (Extra-Chill/data-machine #1268).
 *
 * Run with: php tests/logger-agent-id-resolution-smoke.php
 *
 * Verifies the three-priority resolution chain:
 *   1. Explicit agent_id in context wins.
 *   2. Active agent context (PermissionHelper::in_agent_context() +
 *      get_acting_agent_id()) is consulted next — this is the new
 *      priority introduced by #1268.
 *   3. Owner → first-agent fallback last (legacy behavior preserved
 *      for code paths outside an agent session).
 *
 * The bug before #1268: priority 2 was missing, so log lines emitted
 * inside an AIStep tool call (where set_agent_context() had been
 * installed) fell through to priority 3 and got attributed to whichever
 * agent the database happened to return first for the owner — wrong on
 * any site with multiple agents per owner.
 *
 * Pure PHP, matches the lightweight style of system-task-agent-context-smoke.php.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities {

// PermissionHelper stub. Mirrors the public surface used by
// datamachine_resolve_agent_id().
class PermissionHelper {
	private static ?int $acting_agent_id = null;
	private static int $acting_user_id   = 0;

	public static function set_agent_context(
		int $agent_id,
		int $owner_id = 0,
		?array $caps = null,
		?int $token_id = null
	): void {
		self::$acting_agent_id = $agent_id;
		if ( $owner_id > 0 ) {
			self::$acting_user_id = $owner_id;
		}
	}

	public static function clear_agent_context(): void {
		self::$acting_agent_id = null;
	}

	public static function set_test_acting_user_id( int $user_id ): void {
		self::$acting_user_id = $user_id;
	}

	public static function in_agent_context(): bool {
		return null !== self::get_acting_agent_id();
	}

	public static function get_acting_agent_id(): ?int {
		return self::$acting_agent_id;
	}

	public static function acting_user_id(): int {
		return self::$acting_user_id;
	}
}

}

namespace DataMachine\Core\Database\Agents {

// Agents repo stub. Returns the first agent owned by a user — the test
// uses this to simulate the priority-3 fallback's "guess wrong on
// multi-agent sites" behavior.
class Agents {
	public static array $first_agent_by_owner = array();

	public function get_by_owner_id( int $user_id ) {
		return self::$first_agent_by_owner[ $user_id ] ?? null;
	}
}

}

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

use DataMachine\Abilities\PermissionHelper;

// ─── Subject under test ────────────────────────────────────────────
//
// Replicate the production function in the global namespace so the
// shape tracks the real file. If the real function changes, this smoke
// needs to follow.
function datamachine_resolve_agent_id( array $context = array() ): ?int {
	// Priority 1: Explicit agent_id in context.
	if ( isset( $context['agent_id'] ) && is_numeric( $context['agent_id'] ) && $context['agent_id'] > 0 ) {
		return (int) $context['agent_id'];
	}

	// Priority 2: Active agent context from PermissionHelper.
	try {
		if ( class_exists( PermissionHelper::class )
			&& PermissionHelper::in_agent_context() ) {
			$acting_agent_id = PermissionHelper::get_acting_agent_id();
			if ( $acting_agent_id ) {
				return (int) $acting_agent_id;
			}
		}
	} catch ( \Exception $e ) {
		unset( $e );
	}

	// Priority 3: User → first-agent lookup.
	try {
		if ( class_exists( PermissionHelper::class ) ) {
			$user_id = PermissionHelper::acting_user_id();
			if ( $user_id > 0 && class_exists( \DataMachine\Core\Database\Agents\Agents::class ) ) {
				$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
				$agent       = $agents_repo->get_by_owner_id( $user_id );
				if ( $agent && ! empty( $agent['agent_id'] ) ) {
					return (int) $agent['agent_id'];
				}
			}
		}
	} catch ( \Exception $e ) {
		unset( $e );
	}

	return null;
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

function reset_test_state(): void {
	\DataMachine\Abilities\PermissionHelper::clear_agent_context();
	\DataMachine\Abilities\PermissionHelper::set_test_acting_user_id( 0 );
	\DataMachine\Core\Database\Agents\Agents::$first_agent_by_owner = array();
}

// Site shape: owner_id=1 (admin) owns multiple agents. Repo returns
// agent_id=1 (Franklin) first when looked up by owner_id=1 — this is
// the multi-agent-per-owner scenario where priority 3 guesses wrong.
function configure_multi_agent_site(): void {
	\DataMachine\Core\Database\Agents\Agents::$first_agent_by_owner = array(
		1 => array(
			'agent_id'   => 1,
			'agent_slug' => 'franklin',
		),
	);
}

// ─── Test 1: explicit agent_id in context always wins ──────────────

echo "[1] Priority 1 — explicit agent_id in context wins over everything\n";

reset_test_state();
configure_multi_agent_site();
\DataMachine\Abilities\PermissionHelper::set_agent_context( 5, 1 );
$result = datamachine_resolve_agent_id( array( 'agent_id' => 99 ) );
smoke_assert(
	'explicit context wins over active agent context',
	99 === $result,
	"got " . var_export( $result, true )
);

reset_test_state();
configure_multi_agent_site();
\DataMachine\Abilities\PermissionHelper::set_test_acting_user_id( 1 );
$result = datamachine_resolve_agent_id( array( 'agent_id' => 42 ) );
smoke_assert(
	'explicit context wins over user→first-agent fallback',
	42 === $result,
	"got " . var_export( $result, true )
);

$result = datamachine_resolve_agent_id( array( 'agent_id' => '7' ) );
smoke_assert(
	'numeric-string agent_id is honored',
	7 === $result,
	"got " . var_export( $result, true )
);

$result = datamachine_resolve_agent_id( array( 'agent_id' => 0 ) );
smoke_assert(
	'zero agent_id is rejected (falls through to next priority)',
	1 === $result,
	"got " . var_export( $result, true )
);

$result = datamachine_resolve_agent_id( array( 'agent_id' => 'not-a-number' ) );
smoke_assert(
	'non-numeric agent_id is rejected (falls through to next priority)',
	1 === $result,
	"got " . var_export( $result, true )
);

// ─── Test 2: priority 2 — active agent context (the bug fix) ───────

echo "\n[2] Priority 2 — active agent context resolves correctly\n";

reset_test_state();
configure_multi_agent_site();
// Install agent 2 (Wiki Generator) as active. Acting user is 1
// (admin), which on this site owns multiple agents — but the repo
// returns Franklin (1) first. Priority 2 must beat priority 3 and
// return 2.
\DataMachine\Abilities\PermissionHelper::set_agent_context( 2, 1 );
$result = datamachine_resolve_agent_id();
smoke_assert(
	'active agent context (id=2) beats user→first-agent fallback (id=1)',
	2 === $result,
	"got " . var_export( $result, true ) . " — bug regression: log would be attributed to wrong agent"
);

// Edge case: agent context is set but with id=0 (defensive).
// in_agent_context() returns true (because non-null), but
// get_acting_agent_id() returns 0 which is falsy and should not
// short-circuit priority 3.
reset_test_state();
configure_multi_agent_site();
\DataMachine\Abilities\PermissionHelper::set_agent_context( 0, 1 );
$result = datamachine_resolve_agent_id();
smoke_assert(
	'zero agent_id from active context falls through to priority 3',
	1 === $result,
	"got " . var_export( $result, true )
);

// ─── Test 3: priority 3 — user → first-agent fallback ──────────────

echo "\n[3] Priority 3 — owner→first-agent fallback (legacy behavior preserved)\n";

reset_test_state();
configure_multi_agent_site();
\DataMachine\Abilities\PermissionHelper::set_test_acting_user_id( 1 );
$result = datamachine_resolve_agent_id();
smoke_assert(
	'no context, no agent context → returns first agent for acting user',
	1 === $result,
	"got " . var_export( $result, true )
);

// Acting user with no agents at all → returns null.
reset_test_state();
\DataMachine\Abilities\PermissionHelper::set_test_acting_user_id( 99 );
$result = datamachine_resolve_agent_id();
smoke_assert(
	'acting user with no agents returns null',
	null === $result,
	"got " . var_export( $result, true )
);

// No acting user at all (system context).
reset_test_state();
$result = datamachine_resolve_agent_id();
smoke_assert(
	'no agent context AND no acting user returns null',
	null === $result,
	"got " . var_export( $result, true )
);

// ─── Test 4: regression — DM #1268 reproducer ──────────────────────

echo "\n[4] Regression — pipeline-2-on-Franklin's-host scenario\n";

// Site shape from the bug report:
//   agent_id=1 (Franklin)         owner_id=1
//   agent_id=2 (Wiki Generator)   owner_id=1
//   agent_id=3 (admin)            owner_id=1
// Repo's get_by_owner_id(1) returns Franklin first.
// AIStep installs set_agent_context(2, 1) before invoking SkipItemTool.
// SkipItemTool's log call passes no agent_id in context.
// Pre-fix: log row would be attributed to agent_id=1 (Franklin).
// Post-fix: log row is correctly attributed to agent_id=2 (Wiki Generator).

reset_test_state();
\DataMachine\Core\Database\Agents\Agents::$first_agent_by_owner = array(
	1 => array(
		'agent_id'   => 1,
		'agent_slug' => 'franklin',
	),
);
\DataMachine\Abilities\PermissionHelper::set_agent_context( 2, 1 );

// SkipItemTool's actual log-call shape: context has job_id /
// flow_step_id / item_identifier / source_type / reason — no
// agent_id. The resolver must NOT be tricked by the absence of
// agent_id in context into resolving via priority 3.
$skip_item_context = array(
	'job_id'          => 206,
	'flow_step_id'    => '2_f204f0da-598f-41dd-af2e-d08c47ae1c80_2',
	'item_identifier' => 'mcp_a8c_mgs_search_post_1707',
	'source_type'     => 'mcp',
	'reason'          => 'not_relevant',
);

$result = datamachine_resolve_agent_id( $skip_item_context );
smoke_assert(
	'SkipItemTool log with no agent_id resolves to active agent (2), not first-by-owner (1)',
	2 === $result,
	"got " . var_export( $result, true ) . " — pre-fix bug: log attributed to Franklin (1) not Wiki Generator (2)"
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
