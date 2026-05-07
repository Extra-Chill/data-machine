<?php
/**
 * Pure-PHP smoke test for the policy-driven bundle artifact rebase primitive (#1832).
 *
 * Run with: php tests/agent-bundle-artifact-rebase-smoke.php
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

// Use direct requires (not the composer autoloader) to avoid pulling in
// vendor packages that need a full WordPress environment to bootstrap.
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSchema.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactExtensions.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactHasher.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactRebase.php';

use DataMachine\Engine\Bundle\AgentBundleArtifactRebase;

$failures = 0;
$total    = 0;

function rebase_assert( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function rebase_assert_equals( string $label, $expected, $actual ): void {
	$ok = $expected === $actual;
	rebase_assert( $label, $ok );
	if ( ! $ok ) {
		echo "    expected: " . var_export( $expected, true ) . "\n";
		echo "    actual:   " . var_export( $actual, true ) . "\n";
	}
}

echo "=== Agent Bundle Artifact Rebase Smoke (#1832) ===\n";

// ---------------------------------------------------------------------------
// [1] Conservative policy keeps local payload but flags every divergence.
// ---------------------------------------------------------------------------
echo "\n[1] Conservative policy never auto-merges\n";
$result = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'demo',
		'base'          => array( 'a' => 1, 'b' => 2 ),
		'local'         => array( 'a' => 1, 'b' => 99 ),
		'remote'        => array( 'a' => 5, 'b' => 2 ),
	),
	AgentBundleArtifactRebase::POLICY_CONSERVATIVE
);

rebase_assert_equals( 'conservative keeps local payload', array( 'a' => 1, 'b' => 99 ), $result['merged'] );
rebase_assert( 'conservative flags both diverging fields ambiguous', count( $result['ambiguous'] ) === 2 );
rebase_assert( 'conservative requires approval', true === $result['requires_approval'] );
rebase_assert_equals( 'policy is recorded', 'conservative', $result['policy'] );

// ---------------------------------------------------------------------------
// [2] burn-in-safe falls back to conservative for non-flow artifacts.
// ---------------------------------------------------------------------------
echo "\n[2] burn-in-safe is conservative for non-flow artifacts\n";
$pipeline_result = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'pipeline',
		'artifact_id'   => 'collector',
		'base'          => array( 'steps' => array( 'fetch', 'ai' ) ),
		'local'         => array( 'steps' => array( 'fetch', 'ai' ), 'note' => 'local' ),
		'remote'        => array( 'steps' => array( 'fetch', 'ai', 'publish' ) ),
	),
	AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE
);

rebase_assert( 'pipeline rebase requires approval under burn-in-safe', true === $pipeline_result['requires_approval'] );
rebase_assert_equals( 'pipeline merged stays local under burn-in-safe', array( 'steps' => array( 'fetch', 'ai' ), 'note' => 'local' ), $pipeline_result['merged'] );

// ---------------------------------------------------------------------------
// [3] burn-in-safe takes remote source-shape, keeps local throttles.
// Mirrors the wordpress-com-wiki Flow 41 case from the issue.
// ---------------------------------------------------------------------------
echo "\n[3] burn-in-safe preserves throttles, takes remote provider/owner/repo\n";
$base_flow = array(
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
			'queue_mode'         => 'idle',
			'config_patch_queue' => array(),
		),
	),
	'scheduling_config' => array(
		'enabled'   => true,
		'interval'  => 'hourly',
		'max_items' => array( 'fetch' => 25 ),
	),
);

$local_flow = array(
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
			'queue_mode'         => 'drain', // runtime queue change
			'config_patch_queue' => array( array( 'patch' => array( 'state' => 'live' ) ) ),
		),
	),
	'scheduling_config' => array(
		'enabled'   => true,
		'interval'  => 'hourly',
		'max_items' => array( 'fetch' => 1 ), // local schedule throttle
	),
);

$remote_flow = array(
	'flow_config' => array(
		'10_fetch_1' => array(
			'handler'        => 'github-a8c', // upstream provider switch
			'handler_config' => array(
				'server'    => 'github-a8c',
				'owner'     => 'Automattic',
				'repo'      => 'wpcom',
				'perPage'   => 1,
				'max_items' => 25, // bundle-default upper bound
			),
			'queue_mode'         => 'idle',
			'config_patch_queue' => array(),
		),
	),
	'scheduling_config' => array(
		'enabled'   => false,
		'interval'  => 'manual',
		'max_items' => array( 'fetch' => 25 ),
	),
);

$result = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'wordpress-com-history-github-wpcom-platform-queue',
		'base'          => $base_flow,
		'local'         => $local_flow,
		'remote'        => $remote_flow,
	),
	AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE
);

$step = $result['merged']['flow_config']['10_fetch_1'] ?? array();

rebase_assert_equals( 'remote source-shape: handler', 'github-a8c', $step['handler'] ?? null );
rebase_assert_equals( 'remote source-shape: server', 'github-a8c', $step['handler_config']['server'] ?? null );
rebase_assert_equals( 'remote source-shape: owner', 'Automattic', $step['handler_config']['owner'] ?? null );
rebase_assert_equals( 'remote source-shape: repo', 'wpcom', $step['handler_config']['repo'] ?? null );
rebase_assert_equals( 'remote source-shape: perPage', 1, $step['handler_config']['perPage'] ?? null );
rebase_assert_equals( 'local throttle preserved: max_items', 1, $step['handler_config']['max_items'] ?? null );
rebase_assert_equals( 'local runtime queue preserved: queue_mode', 'drain', $step['queue_mode'] ?? null );
rebase_assert_equals( 'local runtime queue preserved: config_patch_queue length', 1, count( $step['config_patch_queue'] ?? array() ) );
rebase_assert_equals(
	'local scheduling preserved: max_items',
	array( 'fetch' => 1 ),
	$result['merged']['scheduling_config']['max_items'] ?? null
);
rebase_assert_equals( 'local scheduling preserved: enabled', true, $result['merged']['scheduling_config']['enabled'] ?? null );
rebase_assert( 'no fields ambiguous in canonical wpcom case', empty( $result['ambiguous'] ) );
rebase_assert( 'rebase does not require approval in canonical case', false === $result['requires_approval'] );

// Decisions are surfaced for the operator.
rebase_assert_equals(
	'decision recorded for max_items local preservation',
	'local',
	$result['decisions']['flow_config.10_fetch_1.handler_config.max_items']['source'] ?? null
);
rebase_assert_equals(
	'decision recorded for owner remote source-shape',
	'remote',
	$result['decisions']['flow_config.10_fetch_1.handler_config.owner']['source'] ?? null
);

// ---------------------------------------------------------------------------
// [4] Ambiguous overlap remains approval-required, not silently merged.
// ---------------------------------------------------------------------------
echo "\n[4] Ambiguous overlap stays approval-required\n";
$ambiguous_result = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'tricky',
		'base'          => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler_config' => array(
						'tool'      => 'old-tool',
						'max_items' => 10,
					),
				),
			),
		),
		'local' => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler_config' => array(
						'tool'      => 'local-tool', // local changed source-shape
						'max_items' => 1,            // local lowered throttle
					),
				),
			),
		),
		'remote' => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler_config' => array(
						'tool'      => 'remote-tool', // remote also changed source-shape
						'max_items' => 25,            // remote raised throttle
					),
				),
			),
		),
	),
	AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE
);

rebase_assert( 'ambiguous overlap requires approval', true === $ambiguous_result['requires_approval'] );
rebase_assert(
	'tool flagged ambiguous when both diverged from base',
	in_array( 'flow_config.1_step_1.handler_config.tool', $ambiguous_result['ambiguous'], true )
);
rebase_assert(
	'max_items flagged ambiguous when both throttles diverged',
	in_array( 'flow_config.1_step_1.handler_config.max_items', $ambiguous_result['ambiguous'], true )
);
$step_ambiguous = $ambiguous_result['merged']['flow_config']['1_step_1']['handler_config'] ?? array();
rebase_assert_equals( 'ambiguous merge defaults to local tool (safer)', 'local-tool', $step_ambiguous['tool'] ?? null );
rebase_assert_equals( 'ambiguous merge defaults to local throttle', 1, $step_ambiguous['max_items'] ?? null );

// ---------------------------------------------------------------------------
// [5] Multi-handler shape (handler_configs.<slug>) merges per slug.
// ---------------------------------------------------------------------------
echo "\n[5] Multi-handler handler_configs merges per slug\n";
$multi_result = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'multi',
		'base'          => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler_configs' => array(
						'github' => array( 'owner' => 'old', 'max_items' => 25 ),
						'rss'    => array( 'feed' => 'https://old.example/feed' ),
					),
				),
			),
		),
		'local' => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler_configs' => array(
						'github' => array( 'owner' => 'old', 'max_items' => 1 ),
						'rss'    => array( 'feed' => 'https://old.example/feed' ),
					),
				),
			),
		),
		'remote' => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler_configs' => array(
						'github' => array( 'owner' => 'Automattic', 'max_items' => 25 ),
						'rss'    => array( 'feed' => 'https://new.example/feed' ),
					),
				),
			),
		),
	),
	AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE
);

$multi_step = $multi_result['merged']['flow_config']['1_step_1']['handler_configs'] ?? array();
rebase_assert_equals( 'multi: github owner from remote', 'Automattic', $multi_step['github']['owner'] ?? null );
rebase_assert_equals( 'multi: github throttle from local', 1, $multi_step['github']['max_items'] ?? null );
rebase_assert_equals( 'multi: rss feed from remote (local unchanged)', 'https://new.example/feed', $multi_step['rss']['feed'] ?? null );
rebase_assert( 'multi: no ambiguous fields', empty( $multi_result['ambiguous'] ) );

// ---------------------------------------------------------------------------
// [6] Hashes are recomputed for the merged payload.
// ---------------------------------------------------------------------------
echo "\n[6] Rebase output carries reproducible hashes\n";
$hashed_result = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'hash-check',
		'base'          => array( 'a' => 1 ),
		'local'         => array( 'a' => 1, 'b' => 2 ),
		'remote'        => array( 'a' => 9 ),
	),
	AgentBundleArtifactRebase::POLICY_CONSERVATIVE
);
rebase_assert( 'merged_hash is set when merged payload is non-null', is_string( $hashed_result['merged_hash'] ) && '' !== $hashed_result['merged_hash'] );
rebase_assert( 'local_hash is set', is_string( $hashed_result['local_hash'] ) );
rebase_assert( 'remote_hash is set', is_string( $hashed_result['remote_hash'] ) );
rebase_assert( 'base_hash is set', is_string( $hashed_result['base_hash'] ) );

// ---------------------------------------------------------------------------
// [7] Default policy is conservative when an unknown policy name is supplied.
// ---------------------------------------------------------------------------
echo "\n[7] Unknown policy falls back to conservative\n";
$unknown_result = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'unknown-policy',
		'base'          => array( 'a' => 1 ),
		'local'         => array( 'a' => 2 ),
		'remote'        => array( 'a' => 3 ),
	),
	'no-such-policy'
);
rebase_assert_equals( 'unknown policy resolves to conservative', 'conservative', $unknown_result['policy'] );
rebase_assert( 'unknown policy still flags divergence', true === $unknown_result['requires_approval'] );

echo "\nTotal assertions: {$total}\n";
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}
echo "All assertions passed.\n";

}
