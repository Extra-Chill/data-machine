<?php
/**
 * Pure-PHP smoke test for run artifact egress bundle policy.
 *
 * Run with: php tests/run-artifact-egress-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once __DIR__ . '/../inc/Engine/Bundle/BundleValidationException.php';
require_once __DIR__ . '/../inc/Engine/Bundle/PortableSlug.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleSlugTrait.php';
require_once __DIR__ . '/../inc/Engine/Bundle/BundleEgressTargetRegistry.php';
require_once __DIR__ . '/../inc/Engine/Bundle/BundleSchema.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleManifest.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleFlowFile.php';

use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\BundleSchema;

$failures = array();
$passes   = 0;

function assert_policy_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

echo "run-artifact-egress-policy-smoke\n";

$raw_policy = array(
	'daily_memory'          => array(
		'egress'               => array( 'bundle-file', 'pr-body', 'github-comment', 'bundle-file' ),
		'bundle_relative_path' => '/memory/agent/daily/{yyyy}/{mm}/{dd}.md',
	),
	'completion_assertions' => array(
		'egress' => array( 'pr-body' ),
	),
	'transcript_summary'    => array(
		'egress' => array( 'artifact' ),
	),
	'unknown_source'        => array(
		'egress' => array( 'pr-body' ),
	),
);

$expected = array(
	'completion_assertions' => array(
		'egress' => array( 'pr-body' ),
	),
	'daily_memory'          => array(
		'egress'               => array( 'bundle-file', 'pr-body' ),
		'bundle_relative_path' => 'memory/agent/daily/{yyyy}/{mm}/{dd}.md',
	),
	'transcript_summary'    => array(
		'egress' => array( 'artifact' ),
	),
);

assert_policy_equals( $expected, BundleSchema::normalize_run_artifact_egress_policy( $raw_policy ), 'normalizes supported sources and egress targets', $failures, $passes );
assert_policy_equals( array(), BundleSchema::normalize_run_artifact_egress_policy( array() ), 'empty policy stays empty for existing bundles', $failures, $passes );

$manifest = new AgentBundleManifest(
	'2026-05-09T00:00:00+00:00',
	'data-machine/test',
	'artifact-policy-agent',
	'1',
	'',
	'',
	array(
		'slug'         => 'artifact-policy-agent',
		'label'        => 'Artifact Policy Agent',
		'description'  => '',
		'agent_config' => array(),
	),
	array(
		'memory'       => array(),
		'pipelines'    => array(),
		'flows'        => array(),
		'handler_auth' => 'refs',
	),
	$raw_policy
);

assert_policy_equals( $expected, $manifest->to_array()['run_artifacts'] ?? array(), 'manifest round-trips run artifact policy', $failures, $passes );

$flow_file = new AgentBundleFlowFile(
	'artifact-flow',
	'Artifact Flow',
	'artifact-pipeline',
	'manual',
	array(),
	array(
		array(
			'step_position'   => 0,
			'handler_configs' => array(),
		),
	),
	$raw_policy
);

assert_policy_equals( $expected, $flow_file->to_array()['run_artifacts'] ?? array(), 'flow file round-trips run artifact policy', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " run artifact policy assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} run artifact policy assertions passed.\n";
