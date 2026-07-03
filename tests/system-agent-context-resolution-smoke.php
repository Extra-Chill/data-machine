<?php
/**
 * Pure-PHP smoke test for datamachine_resolve_system_agent_context().
 *
 * Run with: php tests/system-agent-context-resolution-smoke.php
 *
 * Regression coverage for the "queued task requires agent context" flood
 * produced when media/SEO/linking abilities enqueued agent-owned batch tasks
 * (alt_text_generation, image_optimization, meta_description_generation,
 * internal_linking) from a context with no authenticated user — WP-Cron,
 * WP-CLI without --user, or a system pipeline step.
 *
 * The old resolution resolved identity solely from get_current_user_id(),
 * which returns 0 outside a web request. That zeroed the enqueue context, so
 * every batched item hit TaskScheduler's agent-context gate and was rejected
 * (one ERROR log line per item). The fix teaches the enqueue path to fall back
 * to the install's default agent owner via
 * DirectoryManager::get_default_agent_user_id().
 *
 * This test verifies the resolver's decision table without a WP bootstrap by
 * mirroring its production logic byte-for-byte in a harness whose two inputs
 * (current-user id, default-agent user id → agent id) are injectable.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Harness mirroring datamachine_resolve_system_agent_context() ────
//
// Mirrors data-machine.php:datamachine_resolve_system_agent_context().
// The two collaborators the real function calls — get_current_user_id() and
// DirectoryManager::get_default_agent_user_id() — are supplied as inputs, and
// the user_id → agent_id resolution (datamachine_resolve_or_create_agent_id)
// is supplied as a closure so the branch logic is exercised in isolation.
//
// @param int      $current_user_id  What get_current_user_id() would return.
// @param int      $default_user_id  What DirectoryManager::get_default_agent_user_id() would return.
// @param callable $resolve_agent_id user_id => agent_id (0 for unresolvable users).
function resolve_system_agent_context_for_test( int $current_user_id, int $default_user_id, callable $resolve_agent_id ): array {
	$user_id = $current_user_id;

	// No authenticated user: fall back to the install's default agent owner.
	if ( $user_id <= 0 ) {
		$user_id = $default_user_id;
	}

	$agent_id = $user_id > 0 ? (int) $resolve_agent_id( $user_id ) : 0;

	return array(
		'user_id'  => $user_id,
		'agent_id' => $agent_id,
	);
}

/**
 * Mirror of TaskScheduler's agent-context gate for a task that
 * requiresAgentContext() === true: reject when neither agent_id nor
 * agent_slug is present in the enqueue context.
 */
function task_scheduler_gate_for_test( array $context ): bool {
	return ! empty( $context['agent_slug'] ) || ! empty( $context['agent_id'] );
}

// ─── Tiny assertion helpers ─────────────────────────────────────────

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $cond ) use ( &$failures, &$total ): void {
	$total++;
	if ( $cond ) {
		echo "  [PASS] {$label}\n";
	} else {
		$failures++;
		echo "  [FAIL] {$label}\n";
	}
};

// Single-agent install: default owner is user 1 → agent 1.
$resolve = static function ( int $user_id ): int {
	$map = array( 1 => 1, 7 => 25 ); // owner_id => agent_id
	return $map[ $user_id ] ?? 0;
};

// ─── Test cases ─────────────────────────────────────────────────────

echo "\n[1] Authenticated web request resolves the current user's agent\n";
$ctx = resolve_system_agent_context_for_test( 7, 1, $resolve );
$assert( 'user_id is the current user', 7 === $ctx['user_id'] );
$assert( 'agent_id resolved for current user', 25 === $ctx['agent_id'] );
$assert( 'enqueue context passes the agent-context gate', task_scheduler_gate_for_test( $ctx ) );

echo "\n[2] Cron/CLI/system context (no current user) falls back to default agent owner\n";
$ctx = resolve_system_agent_context_for_test( 0, 1, $resolve );
$assert( 'user_id falls back to default agent user', 1 === $ctx['user_id'] );
$assert( 'agent_id resolved from default owner', 1 === $ctx['agent_id'] );
$assert(
	'REGRESSION: fallback context is NOT gate-rejected (was agent_id 0 before fix)',
	task_scheduler_gate_for_test( $ctx )
);

echo "\n[3] Old behaviour would have produced a zeroed, gate-rejected context\n";
// Before the fix, a no-user context resolved to user_id 0 / agent_id 0.
$old_ctx = array( 'user_id' => 0, 'agent_id' => 0 );
$assert(
	'old zeroed context is exactly what the gate rejected',
	false === task_scheduler_gate_for_test( $old_ctx )
);

echo "\n[4] Install with no resolvable owner at all still returns zeros (no fatal)\n";
$ctx = resolve_system_agent_context_for_test( 0, 0, $resolve );
$assert( 'user_id is 0 when no default owner exists', 0 === $ctx['user_id'] );
$assert( 'agent_id is 0 when no default owner exists', 0 === $ctx['agent_id'] );

echo "\n[5] Production sources use the shared resolver, not raw get_current_user_id()\n";
$root    = dirname( __DIR__ );
$sources = array(
	'AltTextAbilities'          => $root . '/inc/Abilities/Media/AltTextAbilities.php',
	'ImageOptimizationAbilities' => $root . '/inc/Abilities/Media/ImageOptimizationAbilities.php',
	'ImageGenerationAbilities'  => $root . '/inc/Abilities/Media/ImageGenerationAbilities.php',
	'MetaDescriptionAbilities'  => $root . '/inc/Abilities/SEO/MetaDescriptionAbilities.php',
	'InternalLinkingAbilities'  => $root . '/inc/Abilities/InternalLinkingAbilities.php',
);
foreach ( $sources as $name => $path ) {
	$src = (string) file_get_contents( $path );
	$assert( "{$name} calls datamachine_resolve_system_agent_context()", str_contains( $src, 'datamachine_resolve_system_agent_context()' ) );
	$assert(
		"{$name} no longer resolves system-task agent id from a user-gated ternary",
		! str_contains( $src, 'datamachine_resolve_or_create_agent_id( $user_id ) : 0' )
	);
}

echo "\n[6] The resolver is defined and documents the cron/CLI fallback\n";
$plugin_src = (string) file_get_contents( $root . '/data-machine.php' );
$assert( 'datamachine_resolve_system_agent_context() defined', str_contains( $plugin_src, 'function datamachine_resolve_system_agent_context(): array' ) );
$assert( 'resolver falls back to the default agent user', str_contains( $plugin_src, 'get_default_agent_user_id()' ) );

echo "\n";
if ( $failures > 0 ) {
	echo "=== system-agent-context-resolution-smoke: {$failures}/{$total} FAILED ===\n";
	exit( 1 );
}
echo "=== system-agent-context-resolution-smoke: ALL PASS ({$total} assertions) ===\n";
