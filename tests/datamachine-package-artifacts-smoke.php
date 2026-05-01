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

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleLegacyAdapter;
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
			'extensions'    => array(),
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
			)
		),
	),
	array(
		BundleSchema::PROMPTS_DIR       => array( 'extract-facts.md' => "Extract facts.\n" ),
		BundleSchema::RUBRICS_DIR       => array( 'wiki-quality.json' => array( 'min_score' => 4 ) ),
		BundleSchema::TOOL_POLICIES_DIR => array( 'read-only-context.json' => array( 'allow' => array( 'datamachine/search' ) ) ),
		BundleSchema::AUTH_REFS_DIR     => array( 'github-default.json' => array( 'ref' => 'github:default' ) ),
		BundleSchema::SEED_QUEUES_DIR   => array( 'mgs-topic-loop.json' => array( 'mode' => 'loop', 'items' => array( 'HPOS' ) ) ),
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

echo "\n[3] Existing bundle paths can read package identity from WP_Agent_Package:\n";
$legacy_bundle   = AgentBundleLegacyAdapter::to_legacy_bundle( $directory );
$legacy_package  = AgentBundler::package_from_bundle( $legacy_bundle );
$command_source  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/AgentBundleCommand.php' );
$bundler_source  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Core/Agents/AgentBundler.php' );
agents_api_smoke_assert_equals( 'woocommerce-wiki-package', $legacy_package->get_slug(), 'legacy bundle projection preserves package identity', $failures, $passes );
agents_api_smoke_assert_equals( 'daily-ingest-flow', $legacy_package->get_artifacts()[0]->get_slug(), 'legacy bundle projection preserves typed artifact payloads', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $command_source, 'AgentBundler::package_from_bundle( $bundle )' ), 'package install/diff summary reads identity through WP_Agent_Package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $bundler_source, '\'package\'   => AgentPackageProjection::from_directory( $directory )' ), 'export directory path exposes package projection alongside Data Machine directory', $failures, $passes );

agents_api_smoke_finish( 'Data Machine package artifact projection', $failures, $passes );
