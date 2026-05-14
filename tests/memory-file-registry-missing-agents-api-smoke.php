<?php
/**
 * Pure-PHP smoke test for MemoryFileRegistry running without the Agents API
 * substrate loaded — the Homeboy playground bootstrap scenario that fataled
 * before issue #2005 was fixed.
 *
 * The smoke deliberately avoids loading `vendor/automattic/agents-api/` so
 * `WP_Agent_Memory_Layer`, `WP_Agent_Memory_Registry`,
 * `WP_Agent_Context_Injection_Policy`, and
 * `\AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier` are all absent
 * when MemoryFileRegistry::register() is called.
 *
 * Run with: php tests/memory-file-registry-missing-agents-api-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

$failures = array();
$passes   = 0;

echo "memory-file-registry-missing-agents-api-smoke\n";

// Minimal WordPress shims. We only need the few helpers MemoryFileRegistry
// touches during register(); none of them require a live WP environment.
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $filename );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {
		// no-op
	}
}

// Load ONLY the registry file. Do not load agents-api. This mirrors the
// Homeboy playground load order: DM core's bootstrap fires before
// `agents-api` would normally register its classes.
require_once dirname( __DIR__ ) . '/inc/Engine/AI/MemoryFileRegistry.php';

use DataMachine\Engine\AI\MemoryFileRegistry;

function mfr_smoke_assert_equals( $expected, $actual, string $label, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		echo "  ✓ {$label}\n";
		$passes++;
		return;
	}
	$failures[] = $label;
	echo "  ✗ {$label}\n";
	echo "      expected: " . var_export( $expected, true ) . "\n";
	echo "      actual:   " . var_export( $actual, true ) . "\n";
}

function mfr_smoke_assert_true( $actual, string $label, array &$failures, int &$passes ): void {
	mfr_smoke_assert_equals( true, (bool) $actual, $label, $failures, $passes );
}

echo "\n[0] Precondition — Agents API substrate is NOT loaded:\n";
mfr_smoke_assert_equals( false, class_exists( 'WP_Agent_Memory_Layer', false ), 'WP_Agent_Memory_Layer is not autoloaded', $failures, $passes );
mfr_smoke_assert_equals( false, class_exists( 'WP_Agent_Memory_Registry', false ), 'WP_Agent_Memory_Registry is not autoloaded', $failures, $passes );
mfr_smoke_assert_equals( false, class_exists( 'WP_Agent_Context_Injection_Policy', false ), 'WP_Agent_Context_Injection_Policy is not autoloaded', $failures, $passes );
mfr_smoke_assert_equals( false, class_exists( '\\AgentsAPI\\AI\\Context\\WP_Agent_Context_Authority_Tier', false ), 'WP_Agent_Context_Authority_Tier is not autoloaded', $failures, $passes );

echo "\n[1] register() must not fatal when substrate is missing:\n";
$fatal = null;
try {
	MemoryFileRegistry::register( 'SITE.md', 10, array(
		'layer'      => MemoryFileRegistry::LAYER_SHARED,
		'protected'  => true,
		'composable' => true,
		'modes'      => array( MemoryFileRegistry::MODE_ALL ),
		'label'      => 'Site Context',
	) );
} catch ( \Throwable $e ) {
	$fatal = $e;
}
mfr_smoke_assert_equals( null, $fatal, 'register() completes without throwing', $failures, $passes );

echo "\n[2] Valid layers pass through normalize_layer fallback:\n";
MemoryFileRegistry::register( 'RULES.md', 15, array( 'layer' => 'shared' ) );
MemoryFileRegistry::register( 'SOUL.md',  20, array( 'layer' => 'agent' ) );
MemoryFileRegistry::register( 'USER.md',  25, array( 'layer' => 'user' ) );
MemoryFileRegistry::register( 'NETWORK.md', 5, array( 'layer' => 'network' ) );

$resolved = MemoryFileRegistry::get_all();
mfr_smoke_assert_equals( 'shared',  $resolved['RULES.md']['layer']   ?? null, 'shared layer round-trips', $failures, $passes );
mfr_smoke_assert_equals( 'agent',   $resolved['SOUL.md']['layer']    ?? null, 'agent layer round-trips', $failures, $passes );
mfr_smoke_assert_equals( 'user',    $resolved['USER.md']['layer']    ?? null, 'user layer round-trips', $failures, $passes );
mfr_smoke_assert_equals( 'network', $resolved['NETWORK.md']['layer'] ?? null, 'network layer round-trips', $failures, $passes );

echo "\n[3] Unknown layers fall back to LAYER_AGENT:\n";
MemoryFileRegistry::register( 'BOGUS.md', 99, array( 'layer' => 'not-a-real-layer' ) );
$resolved = MemoryFileRegistry::get_all();
mfr_smoke_assert_equals( MemoryFileRegistry::LAYER_AGENT, $resolved['BOGUS.md']['layer'] ?? null, 'unknown layer normalizes to LAYER_AGENT', $failures, $passes );

echo "\n[4] default_authority_tier returns documented string literals:\n";
// Authority tier defaults are computed during register(); inspect via the
// resolved registry. Values must match the substrate's vocabulary
// (WP_Agent_Context_Authority_Tier::ordered()).
mfr_smoke_assert_equals( 'workspace_shared', $resolved['SITE.md']['authority_tier']    ?? null, 'shared layer → workspace_shared', $failures, $passes );
mfr_smoke_assert_equals( 'workspace_shared', $resolved['NETWORK.md']['authority_tier'] ?? null, 'network layer → workspace_shared', $failures, $passes );
mfr_smoke_assert_equals( 'user_global',      $resolved['USER.md']['authority_tier']    ?? null, 'user layer → user_global',       $failures, $passes );
mfr_smoke_assert_equals( 'agent_identity',   $resolved['SOUL.md']['authority_tier']    ?? null, 'SOUL.md → agent_identity',       $failures, $passes );
mfr_smoke_assert_equals( 'agent_memory',     $resolved['BOGUS.md']['authority_tier']   ?? null, 'agent layer default → agent_memory', $failures, $passes );

echo "\n[5] retrieval_policy default uses canonical string literals:\n";
// SITE.md was registered with modes=[MODE_ALL] → default policy is 'always'.
// RULES.md was registered with NO modes → default policy is 'never'.
mfr_smoke_assert_equals( 'always', $resolved['SITE.md']['retrieval_policy']  ?? null, 'modes present → always', $failures, $passes );
mfr_smoke_assert_equals( 'never',  $resolved['RULES.md']['retrieval_policy'] ?? null, 'modes empty → never',    $failures, $passes );

echo "\n[6] get_for_mode() does not fatal without substrate:\n";
$fatal = null;
try {
	$injected = MemoryFileRegistry::get_for_mode( 'chat' );
} catch ( \Throwable $e ) {
	$fatal    = $e;
	$injected = array();
}
mfr_smoke_assert_equals( null, $fatal, 'get_for_mode() completes without throwing', $failures, $passes );
// SITE.md is mode=all + policy=always, so it must appear in chat mode.
mfr_smoke_assert_true( isset( $injected['SITE.md'] ), 'SITE.md is injected in chat mode', $failures, $passes );

echo "\n[7] deregister() and reset() do not fatal without substrate:\n";
$fatal = null;
try {
	MemoryFileRegistry::deregister( 'BOGUS.md' );
	MemoryFileRegistry::reset();
} catch ( \Throwable $e ) {
	$fatal = $e;
}
mfr_smoke_assert_equals( null, $fatal, 'deregister + reset complete without throwing', $failures, $passes );
mfr_smoke_assert_equals( array(), MemoryFileRegistry::get_all(), 'registry is empty after reset', $failures, $passes );

echo "\n---\n";
echo sprintf( "Passes:   %d\n", $passes );
echo sprintf( "Failures: %d\n", count( $failures ) );

if ( ! empty( $failures ) ) {
	echo "\nFailed assertions:\n";
	foreach ( $failures as $label ) {
		echo "  - {$label}\n";
	}
	exit( 1 );
}

echo "\nOK\n";
exit( 0 );
