<?php
/**
 * Pure-PHP smoke test for bundle-preserved runtime queue drift reporting.
 *
 * Run with: php tests/agent-bundle-runtime-drift-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Engine/Bundle/BundleSchema.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleRuntimeDrift.php';

use DataMachine\Engine\Bundle\AgentBundleRuntimeDrift;

$failures = array();
$passes   = 0;

function agent_bundle_runtime_drift_assert( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
}

echo "agent-bundle-runtime-drift-smoke\n";

$current_flow = array(
	'flow_config'       => array(
		'88_fetch_2' => array(
			'queue_mode'         => 'drain',
			'_queue_consume_revision' => 'live-rev-1',
			'handler_configs'    => array(
				'mcp' => array( 'max_items' => 50 ),
			),
			'config_patch_queue' => array(
				array( 'patch' => array( 'max_items' => 50, 'state' => 'stale-a' ) ),
				array( 'patch' => array( 'max_items' => 50, 'state' => 'stale-b' ) ),
			),
		),
	),
	'scheduling_config' => array(
		'enabled'   => true,
		'interval'  => 'hourly',
		'max_items' => array( 'fetch' => 50 ),
	),
);

$target_flow = array(
	'flow_config'       => array(
		'88_fetch_2' => array(
			'queue_mode'         => 'loop',
			'handler_configs'    => array(
				'mcp' => array( 'max_items' => 5 ),
			),
			'config_patch_queue' => array(
				array( 'patch' => array( 'max_items' => 5, 'state' => 'reviewed' ) ),
			),
		),
	),
	'scheduling_config' => array(
		'enabled'   => false,
		'interval'  => 'manual',
		'max_items' => array( 'fetch' => 5 ),
		'run_artifacts' => array(
			'completion_assertions' => array( 'egress' => array( 'artifact', 'bundle-file' ) ),
		),
	),
);

$preview = AgentBundleRuntimeDrift::preview( 'wordpress-com-digest', $current_flow, $target_flow, 'preserve_existing' );

agent_bundle_runtime_drift_assert( is_array( $preview ), 'stale queue drift is detected', $failures, $passes );
agent_bundle_runtime_drift_assert( 'preserve_existing' === ( $preview['decision'] ?? null ), 'default install policy preserves runtime queues', $failures, $passes );
agent_bundle_runtime_drift_assert( 2 === ( $preview['queue_depth']['current']['config_patch_queue'] ?? null ), 'current queue depth is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( 1 === ( $preview['queue_depth']['target']['config_patch_queue'] ?? null ), 'target queue depth is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( 'drain' === ( $preview['queue_mode']['current']['88_fetch_2'] ?? null ), 'current queue mode is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( 'loop' === ( $preview['queue_mode']['target']['88_fetch_2'] ?? null ), 'target queue mode is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( array( 'mcp' => 50 ) === ( $preview['steps'][0]['current']['handler_max_items'] ?? null ), 'current burn-in max_items is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( array( 'mcp' => 5 ) === ( $preview['steps'][0]['target']['handler_max_items'] ?? null ), 'target burn-in max_items is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( true === ( $preview['scheduling']['changed'] ?? null ), 'scheduling drift is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( array( 'fetch' => 50 ) === ( $preview['scheduling']['current']['max_items'] ?? null ), 'current scheduling max_items is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( array( 'fetch' => 5 ) === ( $preview['scheduling']['target']['max_items'] ?? null ), 'target scheduling max_items is reported', $failures, $passes );
agent_bundle_runtime_drift_assert( array( 'artifact', 'bundle-file' ) === ( $preview['scheduling']['target']['run_artifacts']['completion_assertions']['egress'] ?? null ), 'target run artifact policy is reported with scheduling drift', $failures, $passes );

$preserved_config = $current_flow['flow_config'];
agent_bundle_runtime_drift_assert( 2 === count( $preserved_config['88_fetch_2']['config_patch_queue'] ), 'default install leaves existing queue untouched', $failures, $passes );

$reconciled_config = AgentBundleRuntimeDrift::replace_runtime_queue_fields( $current_flow['flow_config'], $target_flow['flow_config'] );
agent_bundle_runtime_drift_assert( $target_flow['flow_config']['88_fetch_2']['config_patch_queue'] === $reconciled_config['88_fetch_2']['config_patch_queue'], 'explicit reconcile applies bundle seed queue', $failures, $passes );
agent_bundle_runtime_drift_assert( 'loop' === ( $reconciled_config['88_fetch_2']['queue_mode'] ?? null ), 'explicit reconcile applies bundle seed queue mode', $failures, $passes );
agent_bundle_runtime_drift_assert( 5 === ( $reconciled_config['88_fetch_2']['handler_configs']['mcp']['max_items'] ?? null ), 'explicit reconcile applies bundle seed max_items', $failures, $passes );
agent_bundle_runtime_drift_assert( ! array_key_exists( '_queue_consume_revision', $reconciled_config['88_fetch_2'] ), 'explicit reconcile clears consume revision', $failures, $passes );

$clean_preview = AgentBundleRuntimeDrift::preview(
	'wordpress-com-digest',
	array(
		'flow_config'       => $reconciled_config,
		'scheduling_config' => $target_flow['scheduling_config'],
	),
	$target_flow,
	'preserve_existing'
);

agent_bundle_runtime_drift_assert( null === $clean_preview, 'second diff is clean after runtime reconciliation', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " agent bundle runtime drift assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} agent bundle runtime drift assertions passed.\n";
}
