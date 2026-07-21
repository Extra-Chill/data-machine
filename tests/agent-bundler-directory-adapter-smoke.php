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

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
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

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		// no-op stub for standalone smoke runs.
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value = null, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {
		// no-op stub for standalone smoke runs.
	}
}


require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleArrayAdapter;
use DataMachine\Engine\Bundle\BundleValidationException;

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

$array_bundle = array(
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
				'88_graph' => array(
					'pipeline_step_id' => '88_graph',
					'step_type'        => 'system_task',
					'execution_order'  => 2,
					'label'            => 'Extract graph',
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
					'handler_slugs'      => array( 'mcp' ),
					'handler_configs'    => array(
						'mcp' => array(
							'provider'   => 'github',
							'auth_ref'   => 'github:default',
							'source_url' => 'https://example.com/events/',
							'path'       => 'C:\\Temp\\events.json',
							'regexp'     => '\\d+\\s+events',
						),
					),
					'config_patch_queue' => array(
						array(
							'patch'    => array( 'after' => '2026-04-01' ),
							'added_at' => '2026-04-27T00:00:00Z',
						),
					),
					'queue_mode'         => 'drain',
					'enabled'            => true,
				),
				'88_ai_144'    => array(
					'flow_step_id'     => '88_ai_144',
					'pipeline_step_id' => '88_ai',
					'pipeline_id'      => 88,
					'flow_id'          => 144,
					'execution_order'  => 1,
					'completion_assertions' => array(
						'required_tool_names' => array(
							'create_github_pull_request',
							'comment_github_pull_request',
						),
					),
					'tool_runtime_rules' => array(
						array(
							'after_tool'          => 'workspace_worktree_add',
							'limited_tools'       => array( 'workspace_read' ),
							'max_calls'           => 4,
							'then_require_one_of' => array( 'workspace_edit', 'create_github_issue' ),
						),
					),
					'enabled_tools'    => array( 'datamachine/get-github-pull-review-context' ),
					'disabled_tools'   => array( 'datamachine/delete-flow' ),
					'prompt_queue'     => array(
						array(
							'prompt'   => 'Review PR #1 start/end with \\d+ items',
							'added_at' => '2026-04-28T12:00:00Z',
						),
					),
					'queue_mode'       => 'loop',
				),
				'88_graph_144' => array(
					'flow_step_id'       => '88_graph_144',
					'pipeline_step_id'   => '88_graph',
					'pipeline_id'        => 88,
					'flow_id'            => 144,
					'execution_order'    => 2,
					'step_type'          => 'system_task',
					'flow_step_settings' => array(
						'task_type' => 'wiki_graph_extract',
						'params'    => array(
							'root'    => 'wordpress-com',
							'limit'   => 5,
							'dry_run' => true,
						),
					),
				),
			),
			'scheduling_config'    => array(
				'enabled'   => true,
				'interval'  => 'hourly',
				'max_items' => array( 'mcp' => 5 ),
			),
			'memory_file_contents' => array(
				'flow.md'            => "# Flow\n",
				'files/context.json' => "{}\n",
			),
		),
	),
);

echo "\n[1] Legacy bundle converts to value-object directory documents\n";
$directory = AgentBundleArrayAdapter::from_array_bundle( $array_bundle );
$manifest  = $directory->manifest()->to_array();
$pipeline  = $directory->pipelines()[0]->to_array();
$flow      = $directory->flows()[0]->to_array();

assert_adapter_equals( 'manifest uses portable agent slug', 'review-agent', $manifest['agent']['slug'] );
assert_adapter_equals( 'pipeline document named by portable slug', 'pr-review-pipeline', $pipeline['slug'] );
assert_adapter_equals( 'flow references pipeline by slug', 'pr-review-pipeline', $flow['pipeline_slug'] );
assert_adapter_equals( 'pipeline document strips runtime pipeline_step_id', false, array_key_exists( 'pipeline_step_id', $pipeline['steps'][0]['step_config'] ) );
assert_adapter_equals(
	'flow document preserves handler config',
	array(
		'provider'   => 'github',
		'auth_ref'   => 'github:default',
		'source_url' => 'https://example.com/events/',
		'path'       => 'C:\\Temp\\events.json',
		'regexp'     => '\\d+\\s+events',
	),
	$flow['steps'][0]['handler_configs']['mcp'] ?? null
);
assert_adapter_equals( 'flow document preserves step type', 'fetch', $flow['steps'][0]['step_type'] );
assert_adapter_equals( 'flow document preserves config patch queue', array( 'after' => '2026-04-01' ), $flow['steps'][0]['config_patch_queue'][0]['patch'] ?? null );
assert_adapter_equals( 'flow document preserves AI enabled tools', array( 'datamachine/get-github-pull-review-context' ), $flow['steps'][1]['enabled_tools'] );
assert_adapter_equals( 'flow document preserves AI disabled tools', array( 'datamachine/delete-flow' ), $flow['steps'][1]['disabled_tools'] );
assert_adapter_equals( 'flow document preserves completion assertions', array( 'create_github_pull_request', 'comment_github_pull_request' ), $flow['steps'][1]['completion_assertions']['required_tool_names'] ?? null );
assert_adapter_equals( 'flow document preserves tool runtime rules', 4, $flow['steps'][1]['tool_runtime_rules'][0]['max_calls'] ?? null );
assert_adapter_equals( 'flow document preserves prompt queue', 'Review PR #1 start/end with \\d+ items', $flow['steps'][1]['prompt_queue'][0]['prompt'] );
assert_adapter_equals( 'flow document preserves queue mode', 'loop', $flow['steps'][1]['queue_mode'] );
assert_adapter_equals( 'flow document preserves system task settings', 'wiki_graph_extract', $flow['steps'][2]['flow_step_settings']['task_type'] ?? null );
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
$round_trip     = AgentBundleArrayAdapter::to_array_bundle( $read_directory );
$round_flow     = $round_trip['flows'][0];
$round_steps    = array_values( $round_flow['flow_config'] );

assert_adapter_equals( 'round-trip reconstructs one pipeline', 1, count( $round_trip['pipelines'] ) );
assert_adapter_equals( 'round-trip reconstructs one flow', 1, count( $round_trip['flows'] ) );
assert_adapter_equals( 'round-trip drops source pipeline install ID', 1, $round_trip['pipelines'][0]['original_id'] ?? null );
assert_adapter_equals( 'round-trip drops source flow install ID', 1, $round_trip['flows'][0]['original_id'] ?? null );
assert_adapter_equals( 'round-trip rewrites flow pipeline reference without source install ID', 1, $round_trip['flows'][0]['original_pipeline_id'] ?? null );
assert_adapter_equals( 'round-trip preserves pipeline memory', "# Pipeline\n", $round_trip['pipelines'][0]['memory_file_contents']['pipeline.md'] ?? null );
assert_adapter_equals( 'round-trip preserves flow file memory', "{}\n", $round_trip['flows'][0]['memory_file_contents']['files/context.json'] ?? null );
assert_adapter_equals(
	'round-trip preserves handler config',
	array(
		'auth_ref'   => 'github:default',
		'path'       => 'C:\\Temp\\events.json',
		'provider'   => 'github',
		'regexp'     => '\\d+\\s+events',
		'source_url' => 'https://example.com/events/',
	),
	$round_steps[0]['handler_configs']['mcp'] ?? null
);
assert_adapter_equals( 'round-trip preserves step type', 'fetch', $round_steps[0]['step_type'] );
assert_adapter_equals( 'round-trip preserves config patch queue', array( 'after' => '2026-04-01' ), $round_steps[0]['config_patch_queue'][0]['patch'] ?? null );
assert_adapter_equals( 'round-trip preserves enabled flag', true, $round_steps[0]['enabled'] );
assert_adapter_equals( 'round-trip preserves enabled tools', array( 'datamachine/get-github-pull-review-context' ), $round_steps[1]['enabled_tools'] );
assert_adapter_equals( 'round-trip preserves disabled tools', array( 'datamachine/delete-flow' ), $round_steps[1]['disabled_tools'] );
assert_adapter_equals( 'round-trip preserves completion assertions', array( 'create_github_pull_request', 'comment_github_pull_request' ), $round_steps[1]['completion_assertions']['required_tool_names'] ?? null );
assert_adapter_equals( 'round-trip preserves tool runtime rules', array( 'workspace_edit', 'create_github_issue' ), $round_steps[1]['tool_runtime_rules'][0]['then_require_one_of'] ?? null );
assert_adapter_equals( 'round-trip preserves prompt queue', 'Review PR #1 start/end with \\d+ items', $round_steps[1]['prompt_queue'][0]['prompt'] );
assert_adapter_equals( 'round-trip preserves queue mode', 'loop', $round_steps[1]['queue_mode'] );
assert_adapter_equals( 'round-trip preserves system task settings', 'wiki_graph_extract', $round_steps[2]['flow_step_settings']['task_type'] ?? null );
assert_adapter_equals( 'round-trip preserves system task params', 'wordpress-com', $round_steps[2]['flow_step_settings']['params']['root'] ?? null );
assert_adapter_equals( 'round-trip preserves scheduling interval', 'hourly', $round_flow['scheduling_config']['interval'] );

echo "\n[3] Directory read resolves prompt file references relative to bundle root\n";
if ( ! is_dir( $tmp . '/prompts' ) ) {
	mkdir( $tmp . '/prompts', 0775, true );
}
file_put_contents( $tmp . '/prompts/system.md', "Review from file.\n" );
file_put_contents( $tmp . '/prompts/queue.md', "Review PR from file.\n" );
if ( ! is_dir( $tmp . '/rubrics' ) ) {
	mkdir( $tmp . '/rubrics', 0775, true );
}
file_put_contents( $tmp . '/rubrics/review-quality.md', "Score evidence quality.\n" );

$pipeline_path = $tmp . '/pipelines/pr-review-pipeline.json';
$pipeline_json = json_decode( file_get_contents( $pipeline_path ), true );
$pipeline_json['steps'][1]['step_config']['system_prompt_file'] = 'prompts/system.md';
unset( $pipeline_json['steps'][1]['step_config']['system_prompt'] );
file_put_contents( $pipeline_path, wp_json_encode( $pipeline_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

$flow_path = $tmp . '/flows/pr-review-flow.json';
$flow_json = json_decode( file_get_contents( $flow_path ), true );
$flow_json['steps'][1]['prompt_queue'][0]['prompt_file'] = 'prompts/queue.md';
unset( $flow_json['steps'][1]['prompt_queue'][0]['prompt'] );
file_put_contents( $flow_path, wp_json_encode( $flow_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

$file_backed_round_trip = AgentBundleArrayAdapter::to_array_bundle( AgentBundleDirectory::read( $tmp ) );
$file_backed_pipeline_steps = array_values( $file_backed_round_trip['pipelines'][0]['pipeline_config'] );
$file_backed_flow_steps = array_values( $file_backed_round_trip['flows'][0]['flow_config'] );

assert_adapter_equals( 'system_prompt_file resolves into system_prompt', "Review from file.\n", $file_backed_pipeline_steps[1]['system_prompt'] ?? null );
assert_adapter_equals( 'system_prompt_file does not leak into runtime config', false, array_key_exists( 'system_prompt_file', $file_backed_pipeline_steps[1] ) );
assert_adapter_equals( 'prompt_file resolves into prompt queue entry', "Review PR from file.\n", $file_backed_flow_steps[1]['prompt_queue'][0]['prompt'] ?? null );
assert_adapter_equals( 'prompt_file does not leak into runtime queue', false, array_key_exists( 'prompt_file', $file_backed_flow_steps[1]['prompt_queue'][0] ?? array() ) );
assert_adapter_equals( 'prompt artifacts round-trip into importable bundle array', "Review from file.\n", $file_backed_round_trip['artifact_files']['prompts']['system.md'] ?? null );
assert_adapter_equals( 'rubric artifacts round-trip into importable bundle array', "Score evidence quality.\n", $file_backed_round_trip['artifact_files']['rubrics']['review-quality.md'] ?? null );

$flow_json['steps'][1]['prompt_queue'][0]['prompt_file'] = 'prompts/missing.md';
unset( $flow_json['steps'][1]['prompt_queue'][0]['prompt'] );
file_put_contents( $flow_path, wp_json_encode( $flow_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

$missing_prompt_failed_clearly = false;
try {
	AgentBundleDirectory::read( $tmp );
} catch ( BundleValidationException $e ) {
	$missing_prompt_failed_clearly = false !== strpos( $e->getMessage(), 'references missing prompt file: prompts/missing.md' );
}
assert_adapter( 'missing prompt_file fails clearly', $missing_prompt_failed_clearly );

echo "\n[4] AgentBundler directory methods route through the adapter\n";
$agent_bundler_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Agents/AgentBundler.php' ) ?: '';
assert_adapter( 'export builds AgentBundleDirectory value objects first', false !== strpos( $agent_bundler_source, 'public function export_directory_object' ) );
assert_adapter( 'export adapts value-object directory back to bundle array', false !== strpos( $agent_bundler_source, 'AgentBundleArrayAdapter::to_array_bundle( $directory )' ) );
assert_adapter( 'export composes AgentBundleManifest directly', false !== strpos( $agent_bundler_source, 'new AgentBundleManifest' ) );
assert_adapter( 'export composes AgentBundlePipelineFile directly', false !== strpos( $agent_bundler_source, 'new AgentBundlePipelineFile' ) );
assert_adapter( 'export composes AgentBundleFlowFile directly', false !== strpos( $agent_bundler_source, 'new AgentBundleFlowFile' ) );
assert_adapter( 'to_directory writes AgentBundleArrayAdapter output', false !== strpos( $agent_bundler_source, 'AgentBundleArrayAdapter::from_array_bundle( $bundle )->write( $directory )' ) );
assert_adapter( 'from_directory reads AgentBundleDirectory before array fallback', false !== strpos( $agent_bundler_source, 'AgentBundleArrayAdapter::to_array_bundle( AgentBundleDirectory::read( $directory ) )' ) );
assert_adapter( 'import tracks bundle file artifacts', false !== strpos( $agent_bundler_source, 'self::bundle_file_artifacts( $bundle )' ) );

rm_adapter_tree( $tmp );

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " agent bundler directory adapter assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} agent bundler directory adapter assertions passed.\n";
