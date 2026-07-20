<?php
/**
 * Pure-PHP smoke test for read-only bundle validate/inspect compatibility.
 *
 * Run with: php tests/bundle-validate-inspect-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "bundle-validate-inspect-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
agents_api_smoke_require_module();
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/register-agent-package-artifacts.php';

use DataMachine\Engine\Bundle\AgentBundleAbilityService;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundlePipelineFile;
use DataMachine\Engine\Bundle\AgentPackageProjection;
use DataMachine\Engine\Bundle\BundleSchema;

function datamachine_bundle_validate_smoke_reset_registry(): void {
	do_action( 'init' );
	WP_Agent_Package_Artifacts_Registry::reset_for_tests();
	$GLOBALS['__agents_api_smoke_wrong'] = array();
}

function datamachine_bundle_validate_smoke_artifact_definitions(): array {
	return array_map(
		static fn( WP_Agent_Package_Artifact_Type $type ): array => $type->to_array(),
		wp_get_agent_package_artifact_types()
	);
}

echo "\n[1] Valid Data Machine bundle projects and passes host capability checks:\n";
datamachine_bundle_validate_smoke_reset_registry();

$directory = new AgentBundleDirectory(
	new AgentBundleManifest(
		'2026-05-25T12:00:00Z',
		'data-machine/test',
		'Valid Package',
		'1.0.0',
		'',
		'',
		array(
			'slug'         => 'valid-agent',
			'label'        => 'Valid Agent',
			'description'  => 'Validates package compatibility.',
			'agent_config' => array(),
		),
		array(
			'memory'        => array(),
			'pipelines'     => array( 'daily-ingest' ),
			'flows'         => array( 'daily-ingest-flow' ),
			'prompts'       => array( 'daily-prompt' ),
			'rubrics'       => array(),
			'tool_policies' => array(),
			'auth_refs'     => array(),
			'seed_queues'   => array(),
			'extensions'    => array(),
			'handler_auth'  => 'refs',
		),
		array(),
		array( 'datamachine/agent-bundle' )
	),
	array(),
	array(
		new AgentBundlePipelineFile(
			'daily-ingest',
			'Daily ingest',
			array(
				array(
					'step_position' => 0,
					'step_type'     => 'fetch',
					'step_config'   => array(),
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
			array(),
			array(
				array(
					'step_position'   => 0,
					'step_type'       => 'fetch',
					'handler_configs' => array(),
				),
			)
		),
	),
	array(
		BundleSchema::PROMPTS_DIR => array( 'daily-prompt.md' => "Run the daily ingest.\n" ),
	)
);

$package           = AgentPackageProjection::from_directory( $directory );
$report            = AgentBundleAbilityService::capability_report( $package )->to_array();
$artifact_types    = datamachine_bundle_validate_smoke_artifact_definitions();
$repeated_report   = AgentBundleAbilityService::capability_report( $package )->to_array();
$repeated_artifacts = datamachine_bundle_validate_smoke_artifact_definitions();
agents_api_smoke_assert_equals( true, $report['compatible'] ?? false, 'valid bundle is compatible with Data Machine host capabilities', $failures, $passes );
agents_api_smoke_assert_equals( array(), $report['unsupported_capabilities'] ?? null, 'valid bundle has no unsupported package capabilities', $failures, $passes );
agents_api_smoke_assert_equals( array(), $report['unsupported_artifacts'] ?? null, 'valid bundle has no unsupported artifacts', $failures, $passes );
agents_api_smoke_assert_equals( $report, $repeated_report, 'repeated inspection returns the same compatibility report', $failures, $passes );
agents_api_smoke_assert_equals( $artifact_types, $repeated_artifacts, 'repeated inspection preserves registered artifact definitions', $failures, $passes );
agents_api_smoke_assert_equals( array(), $GLOBALS['__agents_api_smoke_wrong'], 'repeated inspection emits no incorrect-usage notices', $failures, $passes );

echo "\n[2] Projected extension artifact requirements are reported as unsupported:\n";
add_filter(
	'datamachine_agent_bundle_artifact_types',
	static function ( array $types ): array {
		$types[] = 'intelligence/wiki-brain';
		return $types;
	},
	10,
	1
);
datamachine_bundle_validate_smoke_reset_registry();
$extension_directory = new AgentBundleDirectory(
	new AgentBundleManifest(
		'2026-05-25T12:00:00Z',
		'data-machine/test',
		'Extension Package',
		'1.0.0',
		'',
		'',
		array(
			'slug'         => 'extension-agent',
			'label'        => 'Extension Agent',
			'description'  => '',
			'agent_config' => array(),
		),
		array(
			'memory'        => array(),
			'pipelines'     => array(),
			'flows'         => array(),
			'prompts'       => array(),
			'rubrics'       => array(),
			'tool_policies' => array(),
			'auth_refs'     => array(),
			'seed_queues'   => array(),
			'extensions'    => array( 'extensions/intelligence/wiki-brain/woocommerce.json' ),
			'handler_auth'  => 'refs',
		)
	),
	array(),
	array(),
	array(),
	array(),
	array(
		array(
			'artifact_type' => 'intelligence/wiki-brain',
			'artifact_id'   => 'woocommerce',
			'source_path'   => 'extensions/intelligence/wiki-brain/woocommerce.json',
			'payload'       => array( 'root' => 'woocommerce' ),
			'requires'      => array( 'intelligence/wiki-brain' ),
		),
	)
);
$extension_report = AgentBundleAbilityService::capability_report( AgentPackageProjection::from_directory( $extension_directory ) )->to_array();
agents_api_smoke_assert_equals( false, $extension_report['compatible'] ?? true, 'bundle with unsupported extension requirement is incompatible', $failures, $passes );
agents_api_smoke_assert_equals( array( 'intelligence/wiki-brain' ), $extension_report['unsupported_capabilities'] ?? null, 'unsupported artifact requirement is promoted to required capabilities', $failures, $passes );
agents_api_smoke_assert_equals( 'intelligence/wiki-brain:woocommerce', $extension_report['unsupported_artifacts'][0]['artifact_key'] ?? '', 'unsupported extension artifact carries stable key', $failures, $passes );

echo "\n[3] Unsupported capability and unknown artifact reports stay machine-readable:\n";
$unsupported_package = WP_Agent_Package::from_array(
	array(
		'slug'         => 'unsupported-package',
		'version'      => '1.0.0',
		'agent'        => array(
			'slug'           => 'unsupported-agent',
			'label'          => 'Unsupported Agent',
			'description'    => '',
			'default_config' => array(),
		),
		'capabilities' => array( 'datamachine/agent-bundle', 'intelligence/wiki-brain' ),
		'artifacts'    => array(
			array(
				'type'     => 'unknown/vendor-artifact',
				'slug'     => 'opaque-seed',
				'label'    => 'Opaque seed',
				'source'   => 'extensions/unknown/vendor-artifact/opaque-seed.json',
				'requires' => array( 'unknown/runtime' ),
			),
		),
	)
);
$unsupported_report = WP_Agent_Package_Capability_Checker::check( $unsupported_package, AgentBundleAbilityService::host_capabilities() )->to_array();
agents_api_smoke_assert_equals( false, $unsupported_report['compatible'] ?? true, 'unsupported package is incompatible', $failures, $passes );
agents_api_smoke_assert_equals( array( 'intelligence/wiki-brain', 'unknown/runtime' ), $unsupported_report['unsupported_capabilities'] ?? null, 'unsupported capabilities include package and artifact requirements', $failures, $passes );
agents_api_smoke_assert_equals( array( 'unknown/vendor-artifact' ), $unsupported_report['unknown_artifact_types'] ?? null, 'unknown artifact type is reported', $failures, $passes );
agents_api_smoke_assert_equals( 'unknown/vendor-artifact:opaque-seed', $unsupported_report['unsupported_artifacts'][0]['artifact_key'] ?? '', 'unsupported artifact carries stable key', $failures, $passes );

echo "\n[4] Ability and CLI surfaces are registered in source:\n";
$ability_source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/AgentAbilities.php' );
$cli_source     = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/AgentBundleCommand.php' );
agents_api_smoke_assert_equals( true, str_contains( $ability_source, 'datamachine/validate-agent-bundle' ), 'validate ability is registered', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $ability_source, 'datamachine/inspect-agent-bundle' ), 'inspect ability is registered', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $cli_source, 'function validate(' ), 'validate CLI method exists', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $cli_source, 'function inspect(' ), 'inspect CLI method exists', $failures, $passes );

agents_api_smoke_finish( 'bundle validate/inspect', $failures, $passes );
