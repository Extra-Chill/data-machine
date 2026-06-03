<?php
/**
 * Pure-PHP smoke test for installed-artifact payload snapshot persistence.
 *
 * Exercises the round-trip:
 *   payload at install → AgentBundleInstalledArtifact → to_array() → from_array()
 *   → installed_payload() returns the original payload.
 *
 * And the rebase-fidelity claim:
 *   With installed_payload populated, the burn-in-safe policy can produce a
 *   clean (no-ambiguous) merge for the canonical wpcom case, where without it
 *   the policy has to flag fields ambiguous because it can't tell "local moved
 *   this field" from "local just inherited base".
 *
 * Run with: php tests/agent-bundle-installed-payload-snapshot-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9_-]+/', '-', $title );
		return trim( null === $title ? '' : $title, '-' );
	}
}

require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSchema.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleValidationException.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/PortableSlug.php';
require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Packages/class-wp-agent-package-artifact.php';
require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Packages/class-wp-agent-package-artifact-hasher.php';
require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Packages/class-wp-agent-package-artifact-status.php';
require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Packages/class-wp-agent-package-installed-artifact.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactExtensions.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactHasher.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactStatus.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleManifest.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleInstalledArtifact.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactRebase.php';

use DataMachine\Engine\Bundle\AgentBundleArtifactRebase;
use DataMachine\Engine\Bundle\AgentBundleInstalledArtifact;
use DataMachine\Engine\Bundle\AgentBundleManifest;

$failures = 0;
$total    = 0;

function snap_assert( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function snap_assert_equals( string $label, $expected, $actual ): void {
	$ok = $expected === $actual;
	snap_assert( $label, $ok );
	if ( ! $ok ) {
		echo "    expected: " . var_export( $expected, true ) . "\n";
		echo "    actual:   " . var_export( $actual, true ) . "\n";
	}
}

echo "=== Installed Payload Snapshot Smoke (#1832 follow-up) ===\n";

$manifest = AgentBundleManifest::from_array(
	array(
		'schema_version' => 1,
		'bundle_slug'    => 'wordpress-com-wiki',
		'bundle_version' => '1.0.0',
		'exported_at'    => '2026-04-28T00:00:00Z',
		'exported_by'    => 'data-machine/test',
		'agent'          => array(
			'slug'         => 'wordpress-com-wiki',
			'label'        => 'wpcom Wiki',
			'description'  => 'Builds wpcom domain wiki.',
			'agent_config' => array(),
		),
		'included'       => array(
			'memory'       => array(),
			'pipelines'    => array(),
			'flows'        => array(),
			'handler_auth' => 'refs',
		),
	)
);

// ---------------------------------------------------------------------------
// [1] Install-time payload is captured on AgentBundleInstalledArtifact.
// ---------------------------------------------------------------------------
echo "\n[1] Install-time payload is captured\n";
$flow_payload = array(
	'flow_config' => array(
		'10_fetch_1' => array(
			'handler'        => 'github',
			'handler_config' => array(
				'server'    => 'github',
				'owner'     => 'old-owner',
				'repo'      => 'old-repo',
				'perPage'   => 25,
				'max_items' => 25,
			),
		),
	),
	'scheduling_config' => array( 'enabled' => true, 'interval' => 'hourly', 'max_items' => array( 'fetch' => 25 ) ),
);

$installed = AgentBundleInstalledArtifact::from_installed_payload(
	$manifest,
	'flow',
	'wordpress-com-history-github-wpcom-platform-queue',
	'flows/wordpress-com-history.json',
	$flow_payload,
	'2026-04-28T00:00:00Z'
);

snap_assert_equals( 'installed_payload exposes the install-time payload', $flow_payload, $installed->installed_payload() );
$serialized = $installed->to_array();
snap_assert( 'to_array() includes installed_payload', isset( $serialized['installed_payload'] ) );
snap_assert_equals( 'serialized payload round-trips identically', $flow_payload, $serialized['installed_payload'] );

// ---------------------------------------------------------------------------
// [2] from_array() → installed_payload() round-trips.
// ---------------------------------------------------------------------------
echo "\n[2] from_array() round-trips installed_payload\n";
$rehydrated = AgentBundleInstalledArtifact::from_array( $serialized );
snap_assert_equals( 'rehydrated installed_payload matches', $flow_payload, $rehydrated->installed_payload() );

// ---------------------------------------------------------------------------
// [3] Pre-snapshot rows (no installed_payload field) round-trip cleanly.
// ---------------------------------------------------------------------------
echo "\n[3] Pre-snapshot rows propagate as null\n";
$legacy_row = $serialized;
unset( $legacy_row['installed_payload'] );
$legacy = AgentBundleInstalledArtifact::from_array( $legacy_row );
snap_assert_equals( 'legacy row exposes null installed_payload', null, $legacy->installed_payload() );

$legacy_serialized = $legacy->to_array();
snap_assert( 'legacy to_array() omits installed_payload key', ! array_key_exists( 'installed_payload', $legacy_serialized ) );

// ---------------------------------------------------------------------------
// [4] with_current_payload() preserves installed_payload (it's the base, not
// the live payload — so it must not change as live state mutates).
// ---------------------------------------------------------------------------
echo "\n[4] with_current_payload preserves base payload\n";
$mutated = $installed->with_current_payload( array( 'name' => 'edited' ), '2026-04-28T05:00:00Z' );
snap_assert_equals( 'mutating current_payload does not clobber installed_payload', $flow_payload, $mutated->installed_payload() );

// ---------------------------------------------------------------------------
// [5] burn-in-safe rebase produces a clean merge given a real base.
// This is the whole point of persisting installed_payload.
// ---------------------------------------------------------------------------
echo "\n[5] burn-in-safe rebase is clean with installed_payload as base\n";
$base   = $installed->installed_payload();
$local  = array(
	'flow_config' => array(
		'10_fetch_1' => array(
			'handler'        => 'github',
			'handler_config' => array(
				'server'    => 'github',
				'owner'     => 'old-owner',
				'repo'      => 'old-repo',
				'perPage'   => 25,
				'max_items' => 1, // local burn-in throttle
			),
		),
	),
	'scheduling_config' => array( 'enabled' => true, 'interval' => 'hourly', 'max_items' => array( 'fetch' => 1 ) ),
);
$remote = array(
	'flow_config' => array(
		'10_fetch_1' => array(
			'handler'        => 'github-a8c',
			'handler_config' => array(
				'server'    => 'github-a8c',
				'owner'     => 'Automattic',
				'repo'      => 'wpcom',
				'perPage'   => 1,
				'max_items' => 25, // bundle default — base also was 25, so remote did NOT change this
			),
		),
	),
	'scheduling_config' => array( 'enabled' => false, 'interval' => 'manual', 'max_items' => array( 'fetch' => 25 ) ),
);

$rebase_with_base = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'wordpress-com-history-github-wpcom-platform-queue',
		'base'          => $base,
		'local'         => $local,
		'remote'        => $remote,
	),
	AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE
);

snap_assert( 'merge is unambiguous when base is available', empty( $rebase_with_base['ambiguous'] ) );
snap_assert( 'no approval required with base', false === $rebase_with_base['requires_approval'] );

$step = $rebase_with_base['merged']['flow_config']['10_fetch_1'] ?? array();
snap_assert_equals( 'remote handler taken', 'github-a8c', $step['handler'] ?? null );
snap_assert_equals( 'remote owner taken', 'Automattic', $step['handler_config']['owner'] ?? null );
snap_assert_equals( 'local throttle preserved (max_items)', 1, $step['handler_config']['max_items'] ?? null );

// Demonstrate the regression we are fixing: without base, the merge is noisier.
// max_items moves from "burn-in throttle preserved" (clean) to a state where
// the policy can still keep local but does so without confidence that local
// represented intent vs. inherited default.
$rebase_without_base = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'wordpress-com-history-github-wpcom-platform-queue',
		'base'          => null,
		'local'         => $local,
		'remote'        => $remote,
	),
	AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE
);

// Without base, the policy sees local==remote on max_items? No: local=1, remote=25,
// both diverge from "base=null" → policy sees both changed → ambiguous.
$step_no_base   = $rebase_without_base['merged']['flow_config']['10_fetch_1'] ?? array();
$has_throttle_ambiguity = in_array(
	'flow_config.10_fetch_1.handler_config.max_items',
	$rebase_without_base['ambiguous'],
	true
);
snap_assert(
	'baseline regression: without installed_payload, max_items is ambiguous',
	$has_throttle_ambiguity
);
snap_assert(
	'baseline regression: without installed_payload, requires approval',
	true === $rebase_without_base['requires_approval']
);
snap_assert(
	'with installed_payload: clean merge (this PR\'s improvement)',
	false === $rebase_with_base['requires_approval']
);

echo "\nTotal assertions: {$total}\n";
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}
echo "All assertions passed.\n";

}
