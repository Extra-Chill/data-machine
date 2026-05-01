<?php
/**
 * Pure-PHP smoke test for portable bundle update semantics (#1534/#1539).
 *
 * Run with: php tests/agent-bundle-portable-update-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( (string) $title, '-' );
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

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleLegacyAdapter;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundlePipelineFile;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;

$failures = array();
$passes   = 0;

function assert_bundle_update( string $label, bool $condition ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  PASS: {$label}\n";
		return;
	}
	$failures[] = $label;
	echo "  FAIL: {$label}\n";
}

function assert_bundle_update_equals( string $label, $expected, $actual ): void {
	assert_bundle_update( $label, $expected === $actual );
}

function call_bundle_private( AgentBundler $bundler, string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( AgentBundler::class, $method );
	return $reflection->invokeArgs( $bundler, $args );
}

echo "=== Agent Bundle Portable Update Smoke (#1534/#1539) ===\n";

$manifest = AgentBundleManifest::from_array(
	array(
		'schema_version'  => 1,
		'bundle_slug'     => 'Woo Brain',
		'bundle_version'  => '2026.04.28',
		'exported_at'     => '2026-04-28T00:00:00Z',
		'exported_by'     => 'data-machine/test',
		'agent'           => array(
			'slug'         => 'woo-agent',
			'label'        => 'Woo Agent',
			'description'  => 'Maintains WooCommerce knowledge.',
			'agent_config' => array(),
		),
		'included'        => array(
			'memory'       => array(),
			'pipelines'    => array( 'daily-ingest' ),
			'flows'        => array( 'daily-ingest-flow' ),
			'handler_auth' => 'refs',
		),
	)
);

$directory = new AgentBundleDirectory(
	$manifest,
	array(),
	array(
		new AgentBundlePipelineFile(
			'Daily Ingest',
			'Daily ingest',
			array(
				array(
					'step_position' => 0,
					'step_type'     => 'fetch',
					'step_config'   => array( 'label' => 'Fetch' ),
				),
			)
		),
	),
	array(
		new AgentBundleFlowFile(
			'Daily Ingest Flow',
			'Daily ingest flow',
			'Daily Ingest',
			'daily',
			array( 'mcp' => 5 ),
			array(
				array(
					'step_position'       => 0,
					'handler_slug'        => 'mcp',
					'handler_config'      => array( 'provider' => 'mgs' ),
					'handler_configs'     => array( 'mcp' => array( 'provider' => 'mgs' ) ),
					'config_patch_queue'  => array( array( 'patch' => array( 'query' => 'WooCommerce' ), 'added_at' => '2026-04-27T00:00:00Z' ) ),
					'queue_mode'          => 'loop',
				)
			)
		),
	)
);

$legacy_bundle = AgentBundleLegacyAdapter::to_legacy_bundle( $directory );
assert_bundle_update_equals( 'directory adapter preserves bundle slug for updater', 'woo-brain', $legacy_bundle['bundle_slug'] ?? null );
assert_bundle_update_equals( 'directory adapter preserves semantic bundle version', '2026.04.28', $legacy_bundle['bundle_version'] ?? null );
assert_bundle_update_equals( 'pipeline carries portable slug into legacy importer', 'daily-ingest', $legacy_bundle['pipelines'][0]['portable_slug'] ?? null );
assert_bundle_update_equals( 'flow carries portable slug into legacy importer', 'daily-ingest-flow', $legacy_bundle['flows'][0]['portable_slug'] ?? null );

$bundler_reflection = new ReflectionClass( AgentBundler::class );
$bundler            = $bundler_reflection->newInstanceWithoutConstructor();

$pipeline_payload = call_bundle_private(
	$bundler,
	'pipeline_artifact_payload',
	array(
		array(
			'pipeline_name'   => 'Daily ingest',
			'pipeline_config' => array( 'step-1' => array( 'step_type' => 'fetch' ) ),
		),
		'daily-ingest',
	)
);
$installed_hash = AgentBundleArtifactHasher::hash( $pipeline_payload );
$record         = array( 'installed_hash' => $installed_hash );

assert_bundle_update( 'clean artifact is safe to update in place', ! call_bundle_private( $bundler, 'artifact_has_local_modifications', array( $record, $pipeline_payload ) ) );
assert_bundle_update( 'changed artifact is detected as local modification', call_bundle_private( $bundler, 'artifact_has_local_modifications', array( $record, array_merge( $pipeline_payload, array( 'pipeline_name' => 'Locally edited' ) ) ) ) );
assert_bundle_update_equals( 'artifact classifier labels changed payload modified', AgentBundleArtifactStatus::MODIFIED, AgentBundleArtifactStatus::classify( $installed_hash, AgentBundleArtifactHasher::hash( array_merge( $pipeline_payload, array( 'pipeline_name' => 'Locally edited' ) ) ) ) );

$incoming_flow_config = array(
	'flow-step-1' => array(
		'handler_slug'       => 'mcp',
		'handler_config'     => array( 'provider' => 'mgs', 'query' => 'New seed' ),
		'config_patch_queue' => array( array( 'patch' => array( 'query' => 'New seed' ), 'added_at' => '2026-04-27T00:00:00Z' ) ),
		'queue_mode'         => 'drain',
	),
);
$existing_flow_config = array(
	'flow-step-1' => array(
		'config_patch_queue' => array( array( 'patch' => array( 'query' => 'Local queue head' ), 'added_at' => '2026-04-27T00:00:00Z' ) ),
		'queue_mode'         => 'static',
	),
);
$preserved = call_bundle_private( $bundler, 'preserve_runtime_queue_fields', array( $incoming_flow_config, $existing_flow_config ) );
assert_bundle_update_equals( 'upgrade preserves existing config_patch_queue', 'Local queue head', $preserved['flow-step-1']['config_patch_queue'][0]['patch']['query'] ?? null );
assert_bundle_update_equals( 'upgrade preserves existing queue_mode', 'static', $preserved['flow-step-1']['queue_mode'] ?? null );

$remapped_pipeline = call_bundle_private(
	$bundler,
	'remap_pipeline_step_ids',
	array(
		array(
			'2_bundle_step_0' => array(
				'pipeline_step_id' => '2_bundle_step_0',
				'step_type'        => 'fetch',
			),
		),
		2,
		3,
	)
);
assert_bundle_update( 'pipeline step key remaps to installed pipeline ID', isset( $remapped_pipeline['3_bundle_step_0'] ) );
assert_bundle_update_equals( 'pipeline step metadata remaps to installed pipeline ID', '3_bundle_step_0', $remapped_pipeline['3_bundle_step_0']['pipeline_step_id'] ?? null );

$remapped_flow = call_bundle_private(
	$bundler,
	'remap_flow_step_ids',
	array(
		array(
			'2_bundle_step_0_4' => array(
				'flow_step_id'     => '2_bundle_step_0_4',
				'pipeline_step_id' => '2_bundle_step_0',
				'pipeline_id'      => 2,
				'flow_id'          => 4,
			),
		),
		2,
		3,
		9,
	)
);
assert_bundle_update( 'flow step key remaps to installed pipeline and flow IDs', isset( $remapped_flow['3_bundle_step_0_9'] ) );
assert_bundle_update_equals( 'flow step metadata pipeline_step_id remaps', '3_bundle_step_0', $remapped_flow['3_bundle_step_0_9']['pipeline_step_id'] ?? null );
assert_bundle_update_equals( 'flow step metadata pipeline_id remaps', 3, $remapped_flow['3_bundle_step_0_9']['pipeline_id'] ?? null );
assert_bundle_update_equals( 'flow step metadata flow_id remaps', 9, $remapped_flow['3_bundle_step_0_9']['flow_id'] ?? null );

$agent_bundler_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Agents/AgentBundler.php' ) ?: '';
$pipelines_source     = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Database/Pipelines/Pipelines.php' ) ?: '';
$flows_source         = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Database/Flows/Flows.php' ) ?: '';
$bootstrap_source     = file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Bootstrap.php' ) ?: '';
$agents_cli_source    = file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/AgentsCommand.php' ) ?: '';
$bundle_cli_source    = file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/AgentBundleCommand.php' ) ?: '';
assert_bundle_update( 'importer resolves existing pipelines by portable slug', str_contains( $agent_bundler_source, 'get_by_portable_slug( $agent_id, $portable_slug )' ) );
assert_bundle_update( 'importer updates existing pipelines instead of duplicating', str_contains( $agent_bundler_source, 'update_pipeline(' ) );
assert_bundle_update( 'importer resolves existing flows by portable slug', str_contains( $agent_bundler_source, 'get_by_portable_slug( (int) $new_pipeline_id, $portable_slug )' ) );
assert_bundle_update( 'importer updates existing flows instead of duplicating', str_contains( $agent_bundler_source, 'update_flow(' ) );
assert_bundle_update( 'pipelines repository exposes portable slug lookup', str_contains( $pipelines_source, 'function get_by_portable_slug' ) );
assert_bundle_update( 'flows repository exposes portable slug lookup', str_contains( $flows_source, 'function get_by_portable_slug' ) );
assert_bundle_update( 'agent command is registered', str_contains( $bootstrap_source, "WP_CLI::add_command( 'datamachine agent', Commands\\AgentsCommand::class );" ) );
assert_bundle_update( 'top-level agent-bundle command is not registered', ! str_contains( $bootstrap_source, 'datamachine agent-bundle' ) );
assert_bundle_update( 'agent CLI inherits package lifecycle helper', str_contains( $agents_cli_source, 'class AgentsCommand extends AgentBundleCommand' ) );
assert_bundle_update( 'package CLI exposes install command', str_contains( $bundle_cli_source, 'function install(' ) );
assert_bundle_update( 'package CLI exposes non-conflicting installed command', str_contains( $bundle_cli_source, 'function installed(' ) );
assert_bundle_update( 'package CLI does not claim agent list command', ! str_contains( $bundle_cli_source, '@subcommand list' ) );
assert_bundle_update( 'package CLI exposes status command', str_contains( $bundle_cli_source, 'function status(' ) );
assert_bundle_update( 'package CLI exposes diff command', str_contains( $bundle_cli_source, 'function diff(' ) );
assert_bundle_update( 'package CLI exposes upgrade command', str_contains( $bundle_cli_source, 'function upgrade(' ) );
assert_bundle_update( 'package CLI stages PendingActions for approval-required upgrades', str_contains( $bundle_cli_source, 'AgentBundleUpgradePendingAction::stage' ) );
assert_bundle_update( 'package CLI resolves staged apply actions', str_contains( $bundle_cli_source, 'ResolvePendingActionAbility::execute' ) );

$failure_count = is_array( $GLOBALS['failures'] ?? null ) ? count( $GLOBALS['failures'] ) : 0;
if ( 0 !== $failure_count ) {
	echo "\nFAILED: " . $failure_count . " portable update assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} portable update assertions passed.\n";
