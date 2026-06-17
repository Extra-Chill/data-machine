<?php
/**
 * Pure-PHP smoke test for Data Machine package artifact projection (#1689).
 *
 * Run with: php tests/datamachine-package-artifacts-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "datamachine-package-artifacts-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
agents_api_smoke_require_module();
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/register-agent-package-artifacts.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactPayloads;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleArrayAdapter;
use DataMachine\Engine\Bundle\AgentBundleLifecycleProjection;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundlePipelineFile;
use DataMachine\Engine\Bundle\AgentPackageProjection;
use DataMachine\Engine\Bundle\BundleSchema;

function datamachine_package_smoke_reset_registry(): void {
	do_action( 'init' );
	WP_Agent_Package_Artifacts_Registry::reset_for_tests();
}

function datamachine_package_smoke_artifacts_by_type( WP_Agent_Package $package ): array {
	$indexed = array();
	foreach ( $package->get_artifacts() as $artifact ) {
		$indexed[ $artifact->get_type() . ':' . $artifact->get_slug() ] = $artifact->to_array();
	}
	ksort( $indexed, SORT_STRING );
	return $indexed;
}

add_filter(
	'datamachine_agent_bundle_artifact_types',
	static function ( array $types ): array {
		$types[] = 'intelligence/wiki-brain';
		$types[] = 'legacy_plugin_artifact';
		return $types;
	},
	10,
	1
);
add_filter(
	'datamachine_agent_package_artifact_type_definitions',
	static function ( array $definitions ): array {
		$definitions['intelligence/wiki-brain'] = array(
			'label'           => 'Intelligence wiki brain',
			'description'     => 'Plugin-owned Intelligence wiki brain artifact.',
			'import_callback' => 'DataMachine\\Engine\\Bundle\\import_datamachine_agent_package_artifact',
		);
		$definitions['datamachine-extension/legacy_plugin_artifact'] = array(
			'label'           => 'Legacy plugin artifact',
			'description'     => 'Plugin-owned legacy artifact.',
			'import_callback' => 'DataMachine\\Engine\\Bundle\\import_datamachine_agent_package_artifact',
		);
		return $definitions;
	},
	10,
	1
);

echo "\n[1] Data Machine registers package artifact types through the generic registry:\n";
datamachine_package_smoke_reset_registry();
$registered_types = wp_get_agent_package_artifact_types();
$pipeline_type    = $registered_types['datamachine/pipeline'] ?? null;
agents_api_smoke_assert_equals( true, wp_has_agent_package_artifact_type( 'datamachine/pipeline' ), 'pipeline artifact type is registered', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_agent_package_artifact_type( 'datamachine/flow' ), 'flow artifact type is registered', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_agent_package_artifact_type( 'datamachine/queue-seed' ), 'queue seed artifact type is registered', $failures, $passes );
agents_api_smoke_assert_equals( 'Data Machine pipeline', $pipeline_type instanceof WP_Agent_Package_Artifact_Type ? $pipeline_type->get_label() : '', 'registered type carries Data Machine metadata outside agents-api', $failures, $passes );

echo "\n[2] Bundle directories project to Core-shaped WP_Agent_Package identity:\n";
$directory = new AgentBundleDirectory(
	new AgentBundleManifest(
		'2026-04-30T12:00:00Z',
		'data-machine/test',
		'WooCommerce Wiki Package',
		'2026.04.30',
		'refs/heads/main',
		'abc123',
		array(
			'slug'         => 'WooCommerce Agent',
			'label'        => 'WooCommerce Agent',
			'description'  => 'Maintains generated WooCommerce knowledge.',
			'agent_config' => array( 'mode_models' => array( 'pipeline' => 'gpt-5.4' ) ),
		),
		array(
			'memory'        => array( 'agent/SOUL.md' ),
			'pipelines'     => array( 'daily-ingest' ),
			'flows'         => array( 'daily-ingest-flow' ),
			'prompts'       => array( 'extract-facts' ),
			'rubrics'       => array( 'wiki-quality' ),
			'tool_policies' => array( 'read-only-context' ),
			'auth_refs'     => array( 'github-default' ),
			'seed_queues'   => array( 'mgs-topic-loop' ),
			'extensions'    => array(
				'extensions/intelligence/wiki-brain/woocommerce.json',
				'extensions/legacy-plugin/seed.json',
			),
			'handler_auth'  => 'refs',
		)
	),
	array( 'agent/SOUL.md' => "# Agent\n" ),
	array(
		new AgentBundlePipelineFile(
			'daily-ingest',
			'Daily ingest',
			array(
				array(
					'step_position' => 0,
					'step_type'     => 'fetch',
					'step_config'   => array( 'step_type' => 'fetch' ),
				),
			)
		),
	),
	array(
		new AgentBundleFlowFile(
			'daily-ingest-flow',
			'Daily ingest flow',
			'daily-ingest',
			'manual',
			array( 'per_run' => 5 ),
			array(
				array(
					'step_position'   => 0,
					'handler_configs' => array(),
					'step_type'       => 'fetch',
				),
			),
			array(
				'completion_assertions' => array(
					'egress' => array( 'artifact', 'bundle-file' ),
				),
			)
		),
	),
	array(
		BundleSchema::PROMPTS_DIR       => array( 'extract-facts.md' => "Extract facts.\n" ),
		BundleSchema::RUBRICS_DIR       => array( 'wiki-quality.json' => array( 'min_score' => 4 ) ),
		BundleSchema::TOOL_POLICIES_DIR => array( 'read-only-context.json' => array( 'allow' => array( 'datamachine/search' ) ) ),
		BundleSchema::AUTH_REFS_DIR     => array( 'github-default.json' => array( 'ref' => 'github:default' ) ),
		BundleSchema::SEED_QUEUES_DIR   => array( 'mgs-topic-loop.json' => array( 'mode' => 'loop', 'items' => array( 'HPOS' ) ) ),
	),
	array(
		array(
			'artifact_type' => 'intelligence/wiki-brain',
			'artifact_id'   => 'woocommerce',
			'source_path'   => 'extensions/intelligence/wiki-brain/woocommerce.json',
			'payload'       => array( 'root' => 'woocommerce' ),
		),
		array(
			'artifact_type' => 'legacy_plugin_artifact',
			'artifact_id'   => 'seed',
			'source_path'   => 'extensions/legacy-plugin/seed.json',
			'payload'       => array( 'label' => 'Seed' ),
		),
	)
);

$package   = AgentPackageProjection::from_directory( $directory );
$artifacts = datamachine_package_smoke_artifacts_by_type( $package );
agents_api_smoke_assert_equals( 'woocommerce-wiki-package', $package->get_slug(), 'package slug comes from bundle manifest through WP_Agent_Package', $failures, $passes );
agents_api_smoke_assert_equals( '2026.04.30', $package->get_version(), 'package version comes from WP_Agent_Package', $failures, $passes );
agents_api_smoke_assert_equals( 'woocommerce-agent', $package->get_agent()->get_slug(), 'agent slug is normalized by WP_Agent', $failures, $passes );
agents_api_smoke_assert_equals( array( 'pipeline' => 'gpt-5.4' ), $package->get_agent()->get_default_config()['mode_models'] ?? array(), 'agent_config projects to WP_Agent default_config', $failures, $passes );
agents_api_smoke_assert_equals( 'pipelines/daily-ingest.json', $artifacts['datamachine/pipeline:daily-ingest']['source'] ?? '', 'pipeline artifact keeps package-local source', $failures, $passes );
agents_api_smoke_assert_equals( 'flows/daily-ingest-flow.json', $artifacts['datamachine/flow:daily-ingest-flow']['source'] ?? '', 'flow artifact keeps package-local source', $failures, $passes );
agents_api_smoke_assert_equals( 'prompts/extract-facts.md', $artifacts['datamachine/prompt:extract-facts']['source'] ?? '', 'prompt artifact is typed without moving prompt fields into agents-api', $failures, $passes );
agents_api_smoke_assert_equals( 'seed-queues/mgs-topic-loop.json', $artifacts['datamachine/queue-seed:mgs-topic-loop']['source'] ?? '', 'queue seed artifact is typed as a Data Machine payload', $failures, $passes );
agents_api_smoke_assert_equals( 'extensions/intelligence/wiki-brain/woocommerce.json', $artifacts['intelligence/wiki-brain:woocommerce']['source'] ?? '', 'namespaced plugin artifact projects with package-relative extension source', $failures, $passes );
agents_api_smoke_assert_equals( 'extensions/legacy-plugin/seed.json', $artifacts['datamachine-extension/legacy_plugin_artifact:seed']['source'] ?? '', 'legacy plugin artifact maps to generic package namespace', $failures, $passes );
agents_api_smoke_assert_equals( 'legacy_plugin_artifact', $artifacts['datamachine-extension/legacy_plugin_artifact:seed']['meta']['extension_artifact_type'] ?? '', 'legacy plugin artifact preserves bundle artifact type in metadata', $failures, $passes );
$flow_document = $directory->flows()[0]->to_array();
$flow_payload  = AgentBundleArtifactPayloads::flow_document_payload( $flow_document, 'daily-ingest-flow' );
agents_api_smoke_assert_equals( AgentBundleArtifactHasher::hash( $flow_payload ), $artifacts['datamachine/flow:daily-ingest-flow']['checksum'] ?? '', 'flow package artifact checksum uses shared run-artifact-aware payload', $failures, $passes );
agents_api_smoke_assert_equals( array( 'artifact', 'bundle-file' ), $artifacts['datamachine/flow:daily-ingest-flow']['meta']['run_artifacts']['completion_assertions']['egress'] ?? array(), 'flow package artifact exposes normalized run artifact policy in metadata', $failures, $passes );

echo "\n[3] Existing bundle paths can read package identity from WP_Agent_Package:\n";
$array_bundle    = AgentBundleArrayAdapter::to_array_bundle( $directory );
$array_package   = AgentBundler::package_from_bundle( $array_bundle );
$array_artifacts = datamachine_package_smoke_artifacts_by_type( $array_package );
$command_source  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/AgentBundleCommand.php' );
$service_source  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleAbilityService.php' );
$bundler_source  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Core/Agents/AgentBundler.php' );
agents_api_smoke_assert_equals( 'woocommerce-wiki-package', $array_package->get_slug(), 'array bundle projection preserves package identity', $failures, $passes );
agents_api_smoke_assert_equals( 'flows/daily-ingest-flow.json', $array_artifacts['datamachine/flow:daily-ingest-flow']['source'] ?? '', 'array bundle projection preserves typed artifact payloads', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $command_source, 'AgentBundler::package_from_bundle( $bundle )' ), 'package install/diff summary reads identity through WP_Agent_Package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $bundler_source, '\'package\'   => AgentPackageProjection::from_directory( $directory )' ), 'export directory path exposes package projection alongside Data Machine directory', $failures, $passes );

echo "\n[4] Ability lifecycle planning uses shared first-class artifact projection:\n";
$lifecycle_artifacts = array();
foreach ( ( new AgentBundleLifecycleProjection() )->target_artifacts( $array_bundle ) as $artifact ) {
	$lifecycle_artifacts[ (string) $artifact['artifact_type'] . ':' . (string) $artifact['artifact_id'] ] = $artifact;
}
ksort( $lifecycle_artifacts, SORT_STRING );
agents_api_smoke_assert_equals( 'prompts/extract-facts.md', $lifecycle_artifacts['prompt:extract-facts']['source_path'] ?? '', 'lifecycle projection includes prompt file artifacts', $failures, $passes );
agents_api_smoke_assert_equals( 'rubrics/wiki-quality.json', $lifecycle_artifacts['rubric:wiki-quality']['source_path'] ?? '', 'lifecycle projection includes rubric file artifacts', $failures, $passes );
agents_api_smoke_assert_equals( 'tool-policies/read-only-context.json', $lifecycle_artifacts['tool_policy:read-only-context']['source_path'] ?? '', 'lifecycle projection includes tool policy file artifacts', $failures, $passes );
agents_api_smoke_assert_equals( 'auth-refs/github-default.json', $lifecycle_artifacts['auth_ref:github-default']['source_path'] ?? '', 'lifecycle projection includes auth ref file artifacts', $failures, $passes );
agents_api_smoke_assert_equals( 'seed-queues/mgs-topic-loop.json', $lifecycle_artifacts['seed_queue:mgs-topic-loop']['source_path'] ?? '', 'lifecycle projection includes seed queue file artifacts', $failures, $passes );
agents_api_smoke_assert_equals( array( 'artifact', 'bundle-file' ), $lifecycle_artifacts['flow:daily-ingest-flow']['payload']['run_artifacts']['completion_assertions']['egress'] ?? array(), 'flow lifecycle payload preserves normalized run artifact policy', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $service_source, 'AgentBundleLifecycleProjection' ), 'ability service uses shared lifecycle projection service', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $service_source, '$this->projection->target_artifacts( $bundle' ), 'ability service plans through shared lifecycle projection', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $command_source, '$this->projection()->target_artifacts( $bundle' ), 'CLI planning uses the same lifecycle projection', $failures, $passes );

echo "\n[5] Direct WP_Agent_Package artifacts carry run-artifact-aware checksums into generic planning:\n";
$base_payload   = array( 'run_artifacts' => array( 'completion_assertions' => array( 'egress' => array( 'artifact' ) ) ) );
$target_payload = array( 'run_artifacts' => array( 'completion_assertions' => array( 'egress' => array( 'artifact', 'bundle-file' ) ) ) );
$direct_package = WP_Agent_Package::from_array(
	array(
		'slug'      => 'direct-package-fixture',
		'version'   => '2.0.0',
		'agent'     => array( 'slug' => 'direct-agent', 'label' => 'Direct Agent' ),
		'artifacts' => array(
			array(
				'type'     => 'datamachine/flow',
				'slug'     => 'direct-flow',
				'label'    => 'Direct flow',
				'source'   => 'flows/direct-flow.json',
				'checksum' => AgentBundleArtifactHasher::hash( $target_payload ),
				'meta'     => array( 'run_artifacts' => $target_payload['run_artifacts'] ),
			),
		),
	)
);
$direct_plan    = WP_Agent_Package_Update_Planner::plan(
	array(
		array(
			'artifact_type'  => 'datamachine/flow',
			'artifact_id'    => 'direct-flow',
			'source'         => 'flows/direct-flow.json',
			'installed_hash' => AgentBundleArtifactHasher::hash( $base_payload ),
		),
	),
	array(
		array(
			'artifact_type' => 'datamachine/flow',
			'artifact_id'   => 'direct-flow',
			'source'        => 'flows/direct-flow.json',
			'payload'       => $base_payload,
		),
	),
	$direct_package->get_artifacts()
);
$direct_auto_apply = $direct_plan->get_bucket( 'auto_apply' )[0] ?? array();
agents_api_smoke_assert_equals( 'datamachine/flow:direct-flow', $direct_auto_apply['artifact_key'] ?? '', 'direct WP_Agent_Package artifact participates in generic update planning', $failures, $passes );
agents_api_smoke_assert_equals( AgentBundleArtifactHasher::hash( $target_payload ), $direct_auto_apply['target_hash'] ?? '', 'direct package checksum supplies target hash without legacy bundle payload', $failures, $passes );

agents_api_smoke_finish( 'Data Machine package artifact projection', $failures, $passes );
