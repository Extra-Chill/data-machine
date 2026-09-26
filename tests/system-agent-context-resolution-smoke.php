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
 * Extended for Extra-Chill/data-machine #3555: an explicit `system_agent_slug`
 * setting can pin system-task attribution to a fixed agent identity instead of
 * depending on the install default owner's active-agent chat preference. That
 * setting is checked first; an unset or invalid (nonexistent agent) value falls
 * back to the existing owner/active-agent/single-agent chain unchanged.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Harness mirroring datamachine_resolve_explicit_system_agent() ───
//
// Mirrors data-machine.php:datamachine_resolve_explicit_system_agent().
//
// @param string   $system_agent_slug_setting The stored `system_agent_slug` setting value.
// @param callable $lookup_by_slug            slug => array{agent_id:int,owner_id:int}|null.
function resolve_explicit_system_agent_for_test( string $system_agent_slug_setting, callable $lookup_by_slug ): ?array {
	if ( '' === $system_agent_slug_setting ) {
		return null;
	}

	$agent    = $lookup_by_slug( $system_agent_slug_setting );
	$agent_id = (int) ( $agent['agent_id'] ?? 0 );
	$owner_id = (int) ( $agent['owner_id'] ?? 0 );

	if ( $agent_id <= 0 || $owner_id <= 0 ) {
		return null;
	}

	return array(
		'user_id'  => $owner_id,
		'agent_id' => $agent_id,
	);
}

// ─── Harness mirroring datamachine_resolve_system_agent_context() ────
//
// Mirrors data-machine.php:datamachine_resolve_system_agent_context().
// The collaborators the real function calls are supplied as inputs/closure so
// the branch logic is exercised in isolation without a WP bootstrap.
//
// @param int      $current_user_id           What get_current_user_id() would return.
// @param int      $default_user_id           What DirectoryManager::get_default_agent_user_id() would return.
// @param callable $resolve_agent_id          user_id => agent_id (0 for unresolvable users).
// @param string   $system_agent_slug_setting The stored `system_agent_slug` setting value.
// @param callable $lookup_by_slug            slug => array{agent_id:int,owner_id:int}|null.
function resolve_system_agent_context_for_test(
	int $current_user_id,
	int $default_user_id,
	callable $resolve_agent_id,
	string $system_agent_slug_setting = '',
	?callable $lookup_by_slug = null
): array {
	$triggering_user_id = $current_user_id;

	$explicit = resolve_explicit_system_agent_for_test(
		$system_agent_slug_setting,
		$lookup_by_slug ?? static fn(): ?array => null
	);
	if ( null !== $explicit ) {
		return array(
			'user_id'            => $explicit['user_id'],
			'agent_id'           => $explicit['agent_id'],
			'triggering_user_id' => $triggering_user_id,
		);
	}

	$user_id = $default_user_id;

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

echo "\n[4] Fresh install with an owner but no agent fails closed without provisioning\n";
$ctx = resolve_system_agent_context_for_test( 7, 1, static fn(): int => 0 );
$assert( 'default owner remains attributable', 1 === $ctx['user_id'] );
$assert( 'no synthetic agent is returned', 0 === $ctx['agent_id'] );
$assert( 'agent-owned enqueue is rejected until an identity exists', false === task_scheduler_gate_for_test( $ctx ) );

echo "\n[5] Install with no resolvable owner at all still returns zeros (no fatal)\n";
$ctx = resolve_system_agent_context_for_test( 0, 0, $resolve );
$assert( 'user_id is 0 when no default owner exists', 0 === $ctx['user_id'] );
$assert( 'agent_id is 0 when no default owner exists', 0 === $ctx['agent_id'] );
$assert( 'triggering_user_id is still reported', 0 === $ctx['triggering_user_id'] );

echo "\n[6] Explicit system_agent_slug setting overrides the owner/active-agent chain\n";
$lookup = static function ( string $slug ): ?array {
	$map = array( 'pinned-agent' => array( 'agent_id' => 99, 'owner_id' => 42 ) );
	return $map[ $slug ] ?? null;
};
$ctx = resolve_system_agent_context_for_test( 7, 1, $resolve, 'pinned-agent', $lookup );
$assert( 'agent_id is the pinned agent, not the default-owner chain result', 99 === $ctx['agent_id'] );
$assert( 'user_id is the pinned agent\'s owner, not the install default owner', 42 === $ctx['user_id'] );
$assert( 'triggering_user_id is still preserved', 7 === $ctx['triggering_user_id'] );

echo "\n[7] Setting a slug that does not resolve to an existing agent falls back unchanged\n";
$ctx = resolve_system_agent_context_for_test( 7, 1, $resolve, 'no-such-agent', $lookup );
$assert( 'falls back to default owner', 1 === $ctx['user_id'] );
$assert( 'falls back to owner/active-agent chain resolution', 1 === $ctx['agent_id'] );

echo "\n[8] Unset system_agent_slug setting behaves exactly as before this change\n";
$ctx = resolve_system_agent_context_for_test( 7, 1, $resolve, '', $lookup );
$assert( 'unset setting falls back to default owner', 1 === $ctx['user_id'] );
$assert( 'unset setting falls back to owner/active-agent chain resolution', 1 === $ctx['agent_id'] );

echo "\n[9] Fresh install still fails closed when the setting is unset (unchanged regression coverage)\n";
$ctx = resolve_system_agent_context_for_test( 7, 1, static fn(): int => 0, '', $lookup );
$assert( 'default owner remains attributable', 1 === $ctx['user_id'] );
$assert( 'no synthetic agent is returned when unset and ambiguous/absent', 0 === $ctx['agent_id'] );

echo "\n[10] Production sources use the shared resolver and carry triggering_user_id\n";
$root    = dirname( __DIR__ );
$sources = array(
	'AltTextAbilities'         => $root . '/inc/Abilities/Media/AltTextAbilities.php',
	'MetaDescriptionAbilities' => $root . '/inc/Abilities/SEO/MetaDescriptionAbilities.php',
	'InternalLinkingAbilities' => $root . '/inc/Abilities/InternalLinkingAbilities.php',
);
foreach ( $sources as $name => $path ) {
	$src = (string) file_get_contents( $path );
	$assert( "{$name} calls datamachine_resolve_system_agent_context()", str_contains( $src, 'datamachine_resolve_system_agent_context()' ) );
	$assert(
		"{$name} carries triggering_user_id into TaskScheduler context",
		str_contains( $src, "'triggering_user_id' => \$triggering_user_id" )
	);
}

echo "\n[11] Chat path is untouched — it still auto-provisions via resolve_or_create_agent_id\n";
$chat_src = (string) file_get_contents( $root . '/inc/Api/Chat/ChatOrchestrator.php' );
$assert(
	'ChatOrchestrator calls datamachine_resolve_or_create_agent_id()',
	str_contains( $chat_src, 'datamachine_resolve_or_create_agent_id' )
);

echo "\n[12] The resolver documents the attribution-vs-identity distinction\n";
$plugin_src = (string) file_get_contents( $root . '/data-machine.php' );
$assert( 'datamachine_resolve_system_agent_context() defined', str_contains( $plugin_src, 'function datamachine_resolve_system_agent_context(): array' ) );
$assert( 'resolver always falls back to default agent user', str_contains( $plugin_src, '$user_id = (int) \\DataMachine\\Core\\FilesRepository\\DirectoryManager::get_default_agent_user_id();' ) );
$assert( 'resolver returns triggering_user_id', str_contains( $plugin_src, "'triggering_user_id' =>" ) );

echo "\n[13] The explicit system-agent resolver is defined and checked first\n";
$assert( 'datamachine_resolve_explicit_system_agent() defined', str_contains( $plugin_src, 'function datamachine_resolve_explicit_system_agent(): ?array' ) );
$assert( "resolver reads the 'system_agent_slug' setting", str_contains( $plugin_src, "PluginSettings::get( 'system_agent_slug'" ) );
$assert( 'resolver looks up the agent by slug via the Agents repository', str_contains( $plugin_src, '$agents_repo->get_by_slug( $agent_slug )' ) );
$assert(
	'datamachine_resolve_system_agent_context() checks the explicit resolver before the owner chain',
	strpos( $plugin_src, 'datamachine_resolve_explicit_system_agent()' ) < strpos( $plugin_src, 'DirectoryManager::get_default_agent_user_id()', strpos( $plugin_src, 'function datamachine_resolve_system_agent_context' ) )
);

echo "\n";
if ( $failures > 0 ) {
	echo "=== system-agent-context-resolution-smoke: {$failures}/{$total} FAILED ===\n";
	exit( 1 );
}
echo "=== system-agent-context-resolution-smoke: ALL PASS ({$total} assertions) ===\n";
