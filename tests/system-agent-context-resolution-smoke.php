<?php
/**
 * Pure-PHP smoke test for datamachine_resolve_system_agent_context().
 *
 * Run with: php tests/system-agent-context-resolution-smoke.php
 *
 * Regression coverage for the stray-agent provisioning leak tracked in
 * Extra-Chill/data-machine #2864. Media/SEO/linking abilities enqueue
 * agent-owned queued tasks; historically they resolved identity from
 * get_current_user_id(), which caused every authenticated user who triggered
 * a system task to get a persistent agent row minted from their login.
 *
 * The resolver now always attributes system tasks to the install's default
 * agent owner and returns the original triggering user separately so callers
 * can carry it as task-context metadata for audit.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Harness mirroring datamachine_resolve_system_agent_context() ────
//
// Mirrors data-machine.php:datamachine_resolve_system_agent_context().
// The collaborators the real function calls are supplied as inputs/closure so
// the branch logic is exercised in isolation without a WP bootstrap.
//
// @param int      $current_user_id  What get_current_user_id() would return.
// @param int      $default_user_id  What DirectoryManager::get_default_agent_user_id() would return.
// @param callable $resolve_agent_id user_id => agent_id (0 for unresolvable users).
function resolve_system_agent_context_for_test( int $current_user_id, int $default_user_id, callable $resolve_agent_id ): array {
	$triggering_user_id = $current_user_id;
	$user_id            = $default_user_id;

	$agent_id = $user_id > 0 ? (int) $resolve_agent_id( $user_id ) : 0;

	return array(
		'user_id'            => $user_id,
		'agent_id'           => $agent_id,
		'triggering_user_id' => $triggering_user_id,
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

echo "\n[1] Authenticated web request attributes to the default agent, not the triggering user\n";
$ctx = resolve_system_agent_context_for_test( 7, 1, $resolve );
$assert( 'user_id is the default agent owner', 1 === $ctx['user_id'] );
$assert( 'agent_id resolved for default owner', 1 === $ctx['agent_id'] );
$assert( 'triggering_user_id preserves the human', 7 === $ctx['triggering_user_id'] );
$assert( 'enqueue context passes the agent-context gate', task_scheduler_gate_for_test( $ctx ) );

echo "\n[2] Cron/CLI/system context (no current user) still resolves to default agent owner\n";
$ctx = resolve_system_agent_context_for_test( 0, 1, $resolve );
$assert( 'user_id is the default agent user', 1 === $ctx['user_id'] );
$assert( 'agent_id resolved from default owner', 1 === $ctx['agent_id'] );
$assert( 'triggering_user_id is 0 when no human triggered the work', 0 === $ctx['triggering_user_id'] );
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
$assert( 'triggering_user_id is still reported', 0 === $ctx['triggering_user_id'] );

echo "\n[5] Production sources use the shared resolver and carry triggering_user_id\n";
$root    = dirname( __DIR__ );
$sources = array(
	'AltTextAbilities'          => $root . '/inc/Abilities/Media/AltTextAbilities.php',
	'ImageOptimizationAbilities' => $root . '/inc/Abilities/Media/ImageOptimizationAbilities.php',
	'MetaDescriptionAbilities'  => $root . '/inc/Abilities/SEO/MetaDescriptionAbilities.php',
	'InternalLinkingAbilities'  => $root . '/inc/Abilities/InternalLinkingAbilities.php',
);
foreach ( $sources as $name => $path ) {
	$src = (string) file_get_contents( $path );
	$assert( "{$name} calls datamachine_resolve_system_agent_context()", str_contains( $src, 'datamachine_resolve_system_agent_context()' ) );
	$assert(
		"{$name} carries triggering_user_id into TaskScheduler context",
		str_contains( $src, "'triggering_user_id' => \$triggering_user_id" )
	);
}

echo "\n[6] Chat path is untouched — it still auto-provisions via resolve_or_create_agent_id\n";
$chat_src = (string) file_get_contents( $root . '/inc/Api/Chat/ChatOrchestrator.php' );
$assert(
	'ChatOrchestrator calls datamachine_resolve_or_create_agent_id()',
	str_contains( $chat_src, 'datamachine_resolve_or_create_agent_id' )
);

echo "\n[7] The resolver documents the attribution-vs-identity distinction\n";
$plugin_src = (string) file_get_contents( $root . '/data-machine.php' );
$assert( 'datamachine_resolve_system_agent_context() defined', str_contains( $plugin_src, 'function datamachine_resolve_system_agent_context(): array' ) );
$assert( 'resolver always falls back to default agent user', str_contains( $plugin_src, '$user_id = (int) \\DataMachine\\Core\\FilesRepository\\DirectoryManager::get_default_agent_user_id();' ) );
$assert( 'resolver returns triggering_user_id', str_contains( $plugin_src, "'triggering_user_id' =>" ) );

echo "\n";
if ( $failures > 0 ) {
	echo "=== system-agent-context-resolution-smoke: {$failures}/{$total} FAILED ===\n";
	exit( 1 );
}
echo "=== system-agent-context-resolution-smoke: ALL PASS ({$total} assertions) ===\n";
