<?php
/**
 * Pure-PHP smoke test for AgentBundler directory value-object adapter (#1501).
 *
 * Run with: php tests/agent-bundler-directory-adapter-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		return 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		// no-op
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleLegacyAdapter;

$failures = array();
$passes   = 0;

function assert_adapter( string $label, bool $condition ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  PASS: {$label}\n";
		return;
	}
	$failures[] = $label;
	echo "  FAIL: {$label}\n";
}

function assert_adapter_equals( string $label, $expected, $actual ): void {
	assert_adapter( $label, $expected === $actual );
}

function rm_adapter_tree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $path );
}

echo "=== AgentBundler Directory Adapter Smoke (#1501) ===\n";

$legacy_bundle = array(
	'bundle_version' => 1,
	'exported_at'    => '2026-04-28T12:00:00Z',
	'agent'          => array(
		'agent_slug'   => 'review-agent',
		'agent_name'   => 'Review Agent',
		'agent_config' => array( 'model' => 'gpt-5.5' ),
		'site_scope'   => 'site',
	),
	'files'          => array(
		'SOUL.md'   => "# Soul\n",
		'MEMORY.md' => "# Memory\n",
	),
	'user_template'  => "# User\n",
	'pipelines'      => array(
		array(
			'original_id'          => 88,
			'pipeline_name'        => 'PR Review Pipeline',
			'pipeline_config'      => array(
				'88_fetch' => array(
					'pipeline_step_id' => '88_fetch',
					'step_type'        => 'fetch',
					'execution_order'  => 0,
					'label'            => 'Fetch context',
				),
				'88_ai'    => array(
					'pipeline_step_id' => '88_ai',
					'step_type'        => 'ai',
					'execution_order'  => 1,
					'label'            => 'Review',
					'system_prompt'    => 'Review carefully.',
					'disabled_tools'   => array( 'datamachine/delete-flow' ),
				),
			),
			'memory_file_contents' => array( 'pipeline.md' => "# Pipeline\n" ),
		),
	),
	'flows'          => array(
		array(
			'original_id'          => 144,
			'original_pipeline_id' => 88,
			'flow_name'            => 'PR Review Flow',
			'flow_config'          => array(
				'88_fetch_144' => array(
					'flow_step_id'       => '88_fetch_144',
					'pipeline_step_id'   => '88_fetch',
					'pipeline_id'        => 88,
					'flow_id'            => 144,
					'execution_order'    => 0,
					'handler_slug'       => 'mcp',
					'handler_config'     => array( 'provider' => 'github', 'auth_ref' => 'github:default' ),
					'config_patch_queue' => array( array( 'patch' => array( 'after' => '2026-04-01' ), 'added_at' => '2026-04-27T00:00:00Z' ) ),
					'queue_mode'         => 'drain',
					'enabled'            => true,
				),
				'88_ai_144'    => array(
					'flow_step_id'     => '88_ai_144',
					'pipeline_step_id' => '88_ai',
					'pipeline_id'      => 88,
					'flow_id'          => 144,
					'execution_order'  => 1,
					'enabled_tools'    => array( 'datamachine/get-github-pull-review-context' ),
					'disabled_tools'   => array( 'datamachine/delete-flow' ),
					'prompt_queue'     => array( array( 'prompt' => 'Review PR #1', 'added_at' => '2026-04-28T12:00:00Z' ) ),
					'queue_mode'       => 'loop',
				),
			),
			'scheduling_config'    => array( 'enabled' => true, 'interval' => 'hourly', 'max_items' => array( 'mcp' => 5 ) ),
			'memory_file_contents' => array( 'flow.md' => "# Flow\n", 'files/context.json' => "{}\n" ),
		),
	),
);

echo "\n[1] Legacy bundle converts to value-object directory documents\n";
$directory = AgentBundleLegacyAdapter::from_legacy_bundle( $legacy_bundle );
$manifest  = $directory->manifest()->to_array();
$pipeline  = $directory->pipelines()[0]->to_array();
$flow      = $directory->flows()[0]->to_array();

assert_adapter_equals( 'manifest uses portable agent slug', 'review-agent', $manifest['agent']['slug'] );
assert_adapter_equals( 'pipeline document named by portable slug', 'pr-review-pipeline', $pipeline['slug'] );
assert_adapter_equals( 'flow references pipeline by slug', 'pr-review-pipeline', $flow['pipeline_slug'] );
assert_adapter_equals( 'pipeline document strips runtime pipeline_step_id', false, array_key_exists( 'pipeline_step_id', $pipeline['steps'][0]['step_config'] ) );
assert_adapter_equals( 'flow document preserves handler config', array( 'provider' => 'github', 'auth_ref' => 'github:default' ), $flow['steps'][0]['handler_config'] );
assert_adapter_equals( 'flow document preserves step type', 'fetch', $flow['steps'][0]['step_type'] );
assert_adapter_equals( 'flow document preserves config patch queue', array( 'after' => '2026-04-01' ), $flow['steps'][0]['config_patch_queue'][0]['patch'] ?? null );
assert_adapter_equals( 'flow document preserves AI enabled tools', array( 'datamachine/get-github-pull-review-context' ), $flow['steps'][1]['enabled_tools'] );
assert_adapter_equals( 'flow document preserves AI disabled tools', array( 'datamachine/delete-flow' ), $flow['steps'][1]['disabled_tools'] );
assert_adapter_equals( 'flow document preserves prompt queue', 'Review PR #1', $flow['steps'][1]['prompt_queue'][0]['prompt'] );
assert_adapter_equals( 'flow document preserves queue mode', 'loop', $flow['steps'][1]['queue_mode'] );
assert_adapter_equals( 'flow document preserves scheduling interval', 'hourly', $flow['schedule'] );
assert_adapter_equals( 'memory path moves agent files under memory/agent', "# Soul\n", $directory->memory_files()['agent/SOUL.md'] ?? null );

echo "\n[2] Directory write/read uses AgentBundleDirectory layout\n";
$tmp = sys_get_temp_dir() . '/datamachine-agent-bundler-adapter-' . getmypid();
rm_adapter_tree( $tmp );
$directory->write( $tmp );

assert_adapter( 'manifest.json written', is_file( $tmp . '/manifest.json' ) );
assert_adapter( 'pipeline JSON written by portable slug', is_file( $tmp . '/pipelines/pr-review-pipeline.json' ) );
assert_adapter( 'flow JSON written by portable slug', is_file( $tmp . '/flows/pr-review-flow.json' ) );
assert_adapter( 'agent memory written under memory/agent', is_file( $tmp . '/memory/agent/SOUL.md' ) );

$read_directory = AgentBundleDirectory::read( $tmp );
$round_trip     = AgentBundleLegacyAdapter::to_legacy_bundle( $read_directory );
$round_flow     = $round_trip['flows'][0];
$round_steps    = array_values( $round_flow['flow_config'] );

assert_adapter_equals( 'round-trip reconstructs one pipeline', 1, count( $round_trip['pipelines'] ) );
assert_adapter_equals( 'round-trip reconstructs one flow', 1, count( $round_trip['flows'] ) );
assert_adapter_equals( 'round-trip preserves pipeline memory', "# Pipeline\n", $round_trip['pipelines'][0]['memory_file_contents']['pipeline.md'] ?? null );
assert_adapter_equals( 'round-trip preserves flow file memory', "{}\n", $round_trip['flows'][0]['memory_file_contents']['files/context.json'] ?? null );
assert_adapter_equals( 'round-trip preserves handler config', array( 'auth_ref' => 'github:default', 'provider' => 'github' ), $round_steps[0]['handler_config'] );
assert_adapter_equals( 'round-trip preserves step type', 'fetch', $round_steps[0]['step_type'] );
assert_adapter_equals( 'round-trip preserves config patch queue', array( 'after' => '2026-04-01' ), $round_steps[0]['config_patch_queue'][0]['patch'] ?? null );
assert_adapter_equals( 'round-trip preserves enabled flag', true, $round_steps[0]['enabled'] );
assert_adapter_equals( 'round-trip preserves enabled tools', array( 'datamachine/get-github-pull-review-context' ), $round_steps[1]['enabled_tools'] );
assert_adapter_equals( 'round-trip preserves disabled tools', array( 'datamachine/delete-flow' ), $round_steps[1]['disabled_tools'] );
assert_adapter_equals( 'round-trip preserves prompt queue', 'Review PR #1', $round_steps[1]['prompt_queue'][0]['prompt'] );
assert_adapter_equals( 'round-trip preserves queue mode', 'loop', $round_steps[1]['queue_mode'] );
assert_adapter_equals( 'round-trip preserves scheduling interval', 'hourly', $round_flow['scheduling_config']['interval'] );

echo "\n[3] AgentBundler directory methods route through the adapter\n";
$agent_bundler_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Agents/AgentBundler.php' ) ?: '';
assert_adapter( 'to_directory writes AgentBundleLegacyAdapter output', false !== strpos( $agent_bundler_source, 'AgentBundleLegacyAdapter::from_legacy_bundle( $bundle )->write( $directory )' ) );
assert_adapter( 'from_directory reads AgentBundleDirectory before legacy fallback', false !== strpos( $agent_bundler_source, 'AgentBundleLegacyAdapter::to_legacy_bundle( AgentBundleDirectory::read( $directory ) )' ) );

rm_adapter_tree( $tmp );

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " agent bundler directory adapter assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} agent bundler directory adapter assertions passed.\n";
