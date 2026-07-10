<?php
/**
 * Smoke checks for agent bundle manifest reconciliation (#2860).
 *
 * Tests bundle directory discovery, manifest-vs-row drift detection,
 * and operator-override survival through the projection policies that
 * the reconcile path relies on.
 *
 * Run with: php tests/agent-bundle-manifest-reconcile-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "agent-bundle-manifest-reconcile-smoke (#2860)\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
agents_api_smoke_require_module();
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/register-agent-package-artifacts.php';

use DataMachine\Engine\Bundle\AgentBundleAbilityService;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleDirectoryRegistry;
use DataMachine\Engine\Bundle\AgentConfigArtifactProjector;
use DataMachine\Engine\Bundle\AgentBundleAgentConfig;

// ---------------------------------------------------------------------------
// Helper: create a temporary bundle directory with a manifest.json.
// ---------------------------------------------------------------------------
function reconcile_smoke_make_bundle_dir( string $slug, string $version, array $agent_config ): string {
	$dir = sys_get_temp_dir() . '/dm-reconcile-' . $slug . '-' . uniqid();
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	$manifest = array(
		'schema_version'  => 1,
		'bundle_slug'     => $slug,
		'bundle_version'  => $version,
		'exported_at'     => '2026-07-08T00:00:00Z',
		'exported_by'     => 'data-machine/test',
		'source_ref'      => '',
		'source_revision' => '',
		'agent'           => array(
			'slug'         => $slug,
			'label'        => ucfirst( $slug ),
			'description'  => 'Test agent.',
			'agent_config' => $agent_config,
		),
		'included'        => array(
			'memory'        => array(),
			'pipelines'     => array(),
			'flows'         => array(),
			'prompts'       => array(),
			'rubrics'       => array(),
			'tool_policies' => array(),
			'auth_refs'     => array(),
			'seed_queues'   => array(),
			'extensions'    => array(),
			'handler_auth'  => 'refs',
		),
	);

	file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

	return $dir;
}

// ===========================================================================
// [1] AgentBundleDirectoryRegistry resolves registered directories
// ===========================================================================
echo "\n[1] AgentBundleDirectoryRegistry resolves registered directories\n";

$bundle_dir = reconcile_smoke_make_bundle_dir( 'test-agent', '1', array( 'default_model' => 'gpt-5.5' ) );

add_filter(
	'datamachine_agent_bundle_directories',
	static function ( array $dirs ) use ( $bundle_dir ): array {
		$dirs['test-agent'] = $bundle_dir;
		return $dirs;
	},
	10,
	1
);

$dirs = AgentBundleDirectoryRegistry::directories();
agents_api_smoke_assert_equals( $bundle_dir, $dirs['test-agent'] ?? null, 'registered directory is collected', $failures, $passes );

$resolved = AgentBundleDirectoryRegistry::resolve_for_bundle_slug( 'test-agent' );
agents_api_smoke_assert_equals( $bundle_dir, $resolved, 'resolve_for_bundle_slug returns the path', $failures, $passes );

$resolved_normalized = AgentBundleDirectoryRegistry::resolve_for_bundle_slug( 'Test Agent' );
agents_api_smoke_assert_equals( $bundle_dir, $resolved_normalized, 'slug is normalized before lookup', $failures, $passes );

// Resolve via agent row (simulated).
$fake_agent = array(
	'agent_config' => array(
		'datamachine_bundle' => array(
			'bundle_slug' => 'test-agent',
		),
	),
);
$resolved_via_agent = AgentBundleDirectoryRegistry::resolve_for_agent( $fake_agent );
agents_api_smoke_assert_equals( $bundle_dir, $resolved_via_agent, 'resolve_for_agent returns the path from agent row', $failures, $passes );

// Unregistered slug returns null.
agents_api_smoke_assert_equals( null, AgentBundleDirectoryRegistry::resolve_for_bundle_slug( 'nonexistent' ), 'unregistered slug returns null', $failures, $passes );

// Agent without bundle_slug returns null.
agents_api_smoke_assert_equals( null, AgentBundleDirectoryRegistry::resolve_for_agent( array( 'agent_config' => array() ) ), 'agent without bundle_slug returns null', $failures, $passes );

// ===========================================================================
// [2] Registry drops stale (non-existent) directories
// ===========================================================================
echo "\n[2] Registry drops stale directories\n";

add_filter(
	'datamachine_agent_bundle_directories',
	static function ( array $dirs ): array {
		$dirs['stale-bundle'] = '/nonexistent/path/that/does/not/exist';
		return $dirs;
	},
	20,
	1
);

$dirs_after = AgentBundleDirectoryRegistry::directories();
agents_api_smoke_assert_equals( false, array_key_exists( 'stale-bundle', $dirs_after ), 'non-existent directory is dropped', $failures, $passes );
agents_api_smoke_assert_equals( $bundle_dir, $dirs_after['test-agent'] ?? null, 'valid directory survives alongside stale entry', $failures, $passes );

// ===========================================================================
// [3] config_drift_fields detects manifest-vs-row divergence
// ===========================================================================
echo "\n[3] config_drift_fields detects manifest-vs-row divergence\n";

$installed_config = array(
	'default_model'    => 'gpt-5.4-nano',
	'default_provider' => 'openai',
	'description'      => 'Test agent.',
);

$manifest_config = array(
	'default_model'    => 'gpt-5.5',
	'default_provider' => 'openai',
	'description'      => 'Test agent.',
);

$drift = AgentBundleAbilityService::config_drift_fields( $installed_config, $manifest_config );
agents_api_smoke_assert_equals( 1, count( $drift ), 'only the changed key (default_model) is reported as drift', $failures, $passes );
agents_api_smoke_assert_equals( 'default_model', $drift[0]['key'] ?? '', 'drift key is default_model', $failures, $passes );
agents_api_smoke_assert_equals( 'gpt-5.4-nano', $drift[0]['installed'] ?? null, 'installed value is the old model', $failures, $passes );
agents_api_smoke_assert_equals( 'gpt-5.5', $drift[0]['manifest'] ?? null, 'manifest value is the new model', $failures, $passes );

// ===========================================================================
// [4] config_drift_fields returns empty when configs match
// ===========================================================================
echo "\n[4] config_drift_fields returns empty when configs match\n";

$matching_drift = AgentBundleAbilityService::config_drift_fields( $installed_config, $installed_config );
agents_api_smoke_assert_equals( array(), $matching_drift, 'identical configs produce no drift', $failures, $passes );

// Key order difference does not cause false drift.
$reordered = array( 'description' => 'Test agent.', 'default_provider' => 'openai', 'default_model' => 'gpt-5.4-nano' );
$reordered_drift = AgentBundleAbilityService::config_drift_fields( $installed_config, $reordered );
agents_api_smoke_assert_equals( array(), $reordered_drift, 'key order difference does not cause drift', $failures, $passes );

// ===========================================================================
// [5] config_drift_fields detects added and removed keys
// ===========================================================================
echo "\n[5] config_drift_fields detects added and removed keys\n";

$config_with_extra = array_merge( $installed_config, array( 'tool_policy' => array( 'mode' => 'deny' ) ) );
$added_drift = AgentBundleAbilityService::config_drift_fields( $installed_config, $config_with_extra );
agents_api_smoke_assert_equals( 1, count( $added_drift ), 'added key is reported as drift', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_policy', $added_drift[0]['key'] ?? '', 'added key name is tool_policy', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'installed', $added_drift[0] ) && null === $added_drift[0]['installed'], 'added key has null installed value', $failures, $passes );

$removed_drift = AgentBundleAbilityService::config_drift_fields( $config_with_extra, $installed_config );
agents_api_smoke_assert_equals( 1, count( $removed_drift ), 'removed key is reported as drift', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_policy', $removed_drift[0]['key'] ?? '', 'removed key name is tool_policy', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'manifest', $removed_drift[0] ) && null === $removed_drift[0]['manifest'], 'removed key has null manifest value', $failures, $passes );

// ===========================================================================
// [6] tracked_payload excludes datamachine_bundle (not bundle-owned)
// ===========================================================================
echo "\n[6] tracked_payload excludes datamachine_bundle from drift comparison\n";

$config_with_bundle_header = array(
	'default_model'         => 'gpt-5.5',
	'datamachine_bundle'    => array(
		'bundle_slug'    => 'test-agent',
		'bundle_version' => '2',
	),
);

$config_without_bundle_header = array(
	'default_model' => 'gpt-5.5',
);

$tracked_a = AgentBundleAgentConfig::tracked_payload( $config_with_bundle_header );
$tracked_b = AgentBundleAgentConfig::tracked_payload( $config_without_bundle_header );
$bundle_header_drift = AgentBundleAbilityService::config_drift_fields( $tracked_a, $tracked_b );
agents_api_smoke_assert_equals( array(), $bundle_header_drift, 'datamachine_bundle key does not cause drift (excluded from tracked payload)', $failures, $passes );

// ===========================================================================
// [7] Operator override survives upgrade via preserve_local_paths
// ===========================================================================
echo "\n[7] Operator override survives upgrade via preserve_local_paths\n";

// Simulate: manifest ships model=4o, operator overrode to 5.5 locally.
// The 'model' key has preserve_local merge policy, so the operator's
// value must survive even when the manifest payload is applied.
$operator_config = array(
	'model' => 'gpt-5.5',
);

$manifest_payload = array(
	'model' => 'gpt-4o',
);

$preserved = AgentConfigArtifactProjector::preserve_local_paths( $manifest_payload, $operator_config );
agents_api_smoke_assert_equals( 'gpt-5.5', $preserved['model'] ?? null, 'operator model override survives preserve_local_paths', $failures, $passes );

// Keys WITHOUT a preserve_local policy are NOT restored (manifest wins on merge).
$operator_config_no_policy = array(
	'default_model' => 'gpt-5.5',
);
$manifest_payload_no_policy = array(
	'default_model' => 'gpt-4o',
);
$not_preserved = AgentConfigArtifactProjector::preserve_local_paths( $manifest_payload_no_policy, $operator_config_no_policy );
agents_api_smoke_assert_equals( 'gpt-4o', $not_preserved['default_model'] ?? null, 'non-preserve_local key is not restored (three_way merge applies)', $failures, $passes );

// ===========================================================================
// [8] Hash comparison is order-independent for drift detection
// ===========================================================================
echo "\n[8] Hash comparison is order-independent for drift detection\n";

$nested_a = array( 'b' => 2, 'a' => 1, 'steps' => array( 'fetch', 'ai' ) );
$nested_b = array( 'steps' => array( 'fetch', 'ai' ), 'a' => 1, 'b' => 2 );
agents_api_smoke_assert_equals(
	AgentBundleArtifactHasher::hash( $nested_a ),
	AgentBundleArtifactHasher::hash( $nested_b ),
	'associative key order does not affect hash (drift is not a false positive)',
	$failures,
	$passes
);

// List order DOES matter (different content = drift).
$nested_c = array( 'a' => 1, 'b' => 2, 'steps' => array( 'ai', 'fetch' ) );
$hash_differs = AgentBundleArtifactHasher::hash( $nested_a ) !== AgentBundleArtifactHasher::hash( $nested_c );
agents_api_smoke_assert_equals( true, $hash_differs, 'list order difference is detected as drift', $failures, $passes );

// ===========================================================================
// [9] CLI and ability surfaces reference the new reconcile entry points
// ===========================================================================
echo "\n[9] CLI and ability surfaces reference reconcile entry points\n";

$ability_source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/AgentAbilities.php' );
$cli_source     = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/AgentBundleCommand.php' );
$service_source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleAbilityService.php' );
$registry_file  = dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleDirectoryRegistry.php';

agents_api_smoke_assert_equals( true, str_contains( $ability_source, 'reconcileAllAgentBundles' ), 'reconcileAllAgentBundles ability method exists', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $cli_source, "'all'" ) || str_contains( $cli_source, '--all' ), 'CLI upgrade --all flag is present', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $service_source, 'resolve_bundle_directory_for_slug' ), 'service has slug resolution method', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $service_source, 'manifest_drift' ), 'service has manifest_drift method', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $service_source, 'config_drift_fields' ), 'service has config_drift_fields method', $failures, $passes );
agents_api_smoke_assert_equals( true, file_exists( $registry_file ), 'AgentBundleDirectoryRegistry file exists', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( (string) file_get_contents( $registry_file ), 'datamachine_agent_bundle_directories' ), 'registry uses datamachine_agent_bundle_directories filter', $failures, $passes );

// ===========================================================================
// [10] Precedence rule: three_way keys apply manifest when operator hasn't diverged
// ===========================================================================
echo "\n[10] Precedence rule: manifest applies to untouched keys\n";

// When the installed (base) == current (operator didn't touch), the manifest
// change should be detectable as drift and applyable. We verify this through
// the drift comparison: if base == current and manifest differs, drift is
// reported — which is the signal that `agent upgrade` would auto-apply.
$base_config    = array( 'default_model' => 'gpt-5.4-nano' );
$current_config = array( 'default_model' => 'gpt-5.4-nano' ); // operator didn't touch
$target_config  = array( 'default_model' => 'gpt-5.5' );     // manifest changed

$drift_base_to_target = AgentBundleAbilityService::config_drift_fields( $current_config, $target_config );
agents_api_smoke_assert_equals( 1, count( $drift_base_to_target ), 'manifest change is detected as drift when operator has not diverged', $failures, $passes );

// When operator already fixed it to the same value as the manifest — no drift.
$operator_fixed = array( 'default_model' => 'gpt-5.5' );
$drift_after_fix = AgentBundleAbilityService::config_drift_fields( $operator_fixed, $target_config );
agents_api_smoke_assert_equals( array(), $drift_after_fix, 'no drift when operator already set the same value as the manifest', $failures, $passes );

// Cleanup temp directory.
if ( is_dir( $bundle_dir ) ) {
	unlink( $bundle_dir . '/manifest.json' );
	rmdir( $bundle_dir );
}

agents_api_smoke_finish( 'agent bundle manifest reconcile', $failures, $passes );
