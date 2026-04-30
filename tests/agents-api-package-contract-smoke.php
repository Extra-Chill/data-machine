<?php
/**
 * Pure-PHP smoke test for Agents API package/adoption contract (#1686, #1688).
 *
 * Run with: php tests/agents-api-package-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "agents-api-package-contract-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

function agents_api_package_artifacts_reset(): void {
	WP_Agent_Package_Artifacts_Registry::reset_for_tests();
	$GLOBALS['__agents_api_smoke_actions'] = array();
	$GLOBALS['__agents_api_smoke_wrong']   = array();
	$GLOBALS['__agents_api_smoke_current'] = array();
	$GLOBALS['__agents_api_smoke_done']    = array();
	do_action( 'init' );
}

echo "\n[1] Package manifests normalize generic agent metadata:\n";
$package = WP_Agent_Package::from_array(
	array(
		'slug'         => 'WordPress.com Wiki Package',
		'version'      => '2026.04.30',
		'agent'        => array(
			'slug'           => 'WordPress.com Wiki',
			'label'          => 'WordPress.com Wiki',
			'description'    => 'Maintains a historical knowledge base.',
			'memory_seeds'   => array(
				'../SOUL.md'   => 'memory/SOUL.md',
				'../MEMORY.md' => 'memory/MEMORY.md',
			),
			'default_config' => array(
				'model' => array( 'pipeline' => 'gpt-5.4' ),
			),
			'meta'           => array(
				'domain' => 'wordpress-com',
			),
		),
		'capabilities' => array( 'knowledge.read', 'knowledge.write', 'knowledge.read' ),
		'artifacts'    => array(
			array(
				'type'        => 'intelligence/brain',
				'slug'        => 'Historical Synthesis',
				'label'       => 'Historical synthesis',
				'description' => 'Converts source packets into grounded wiki notes.',
				'source'      => 'brains/historical-synthesis.json',
				'checksum'    => 'sha256:abc123',
				'requires'    => array( 'intelligence', 'intelligence' ),
				'meta'        => array( 'root' => 'wordpress-com' ),
			),
			array(
				'type'        => 'datamachine/flow',
				'slug'        => 'Source Notes',
				'label'       => 'Source notes',
				'description' => 'Loops through source windows.',
				'source'      => 'artifacts/source-notes.json',
			),
		),
		'meta'         => array(
			'homepage' => 'https://example.invalid/agents/wordpress-com-wiki',
		),
	)
);

$manifest = $package->to_array();
agents_api_smoke_assert_equals( 'wordpress-com-wiki-package', $package->get_slug(), 'package slug is normalized', $failures, $passes );
agents_api_smoke_assert_equals( '2026.04.30', $package->get_version(), 'package version is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'wordpress-com-wiki', $package->get_agent()->get_slug(), 'agent slug is normalized through WP_Agent', $failures, $passes );
agents_api_smoke_assert_equals( 'WordPress.com Wiki', $package->get_agent()->get_label(), 'agent label is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'knowledge.read', 'knowledge.write' ), $package->get_capabilities(), 'capabilities are deduped and sorted', $failures, $passes );
agents_api_smoke_assert_equals( array( 'SOUL.md' => 'memory/SOUL.md', 'MEMORY.md' => 'memory/MEMORY.md' ), $package->get_agent()->get_memory_seeds(), 'agent memory seeds use existing WP_Agent validation', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Package_Artifact::class, get_class( $package->get_artifacts()[0] ), 'package artifacts normalize to artifact objects', $failures, $passes );
agents_api_smoke_assert_equals( 'datamachine/flow', $manifest['artifacts'][0]['type'], 'artifacts preserve typed namespaced slugs', $failures, $passes );
agents_api_smoke_assert_equals( 'intelligence/brain', $manifest['artifacts'][1]['type'], 'package carries another typed artifact without importing product code', $failures, $passes );
agents_api_smoke_assert_equals( 'sha256:abc123', $manifest['artifacts'][1]['checksum'], 'artifact checksum is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'intelligence' ), $manifest['artifacts'][1]['requires'], 'artifact requires list is deduped', $failures, $passes );
agents_api_smoke_assert_equals( 'wordpress-com', $package->get_agent()->get_meta()['domain'] ?? '', 'agent meta can describe a domain without product vocabulary', $failures, $passes );

echo "\n[2] Invalid package manifests fail before materialization:\n";
$threw = false;
try {
	WP_Agent_Package::from_array( array( 'slug' => '!!!', 'agent' => array( 'slug' => 'valid-agent' ) ) );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'package slug cannot be empty' );
}
agents_api_smoke_assert_equals( true, $threw, 'empty package slug is rejected', $failures, $passes );

$threw = false;
try {
	WP_Agent_Package::from_array( array( 'slug' => 'valid-package' ) );
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'requires an agent definition' );
}
agents_api_smoke_assert_equals( true, $threw, 'missing agent definition is rejected', $failures, $passes );

$threw = false;
try {
	WP_Agent_Package::from_array(
		array(
			'slug'      => 'valid-package',
			'agent'     => array( 'slug' => 'valid-agent' ),
			'artifacts' => array( array( 'type' => 'prompt' ) ),
		)
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_contains( $e->getMessage(), 'type must be a namespaced slug' );
}
agents_api_smoke_assert_equals( true, $threw, 'malformed artifact is rejected', $failures, $passes );

echo "\n[3] Artifact type registry mirrors agent registration lifecycle:\n";
agents_api_package_artifacts_reset();
$validate_callback = static function ( WP_Agent_Package_Artifact $artifact ): bool {
	return '' !== $artifact->get_source();
};
$diff_callback     = static function (): array {
	return array( 'status' => 'changed' );
};
add_action(
	'wp_agent_package_artifacts_init',
	static function () use ( $validate_callback, $diff_callback ): void {
		wp_register_agent_package_artifact_type(
			'datamachine/flow',
			array(
				'label'             => 'Package flow artifact',
				'description'       => 'Registered by a product plugin.',
				'validate_callback' => $validate_callback,
				'diff_callback'     => $diff_callback,
				'import_callback'   => static fn() => 'imported',
				'delete_callback'   => static fn() => 'deleted',
				'meta'              => array( 'owner' => 'example' ),
			)
		);
		wp_register_agent_package_artifact_type( 'intelligence/brain', array( 'label' => 'Brain artifact' ) );
		wp_register_agent_package_artifact_type( 'datamachine/flow', array( 'label' => 'Duplicate' ) );
		wp_register_agent_package_artifact_type( 'broken', array( 'label' => 'Broken' ) );
		wp_register_agent_package_artifact_type( 'example/bad-callback', array( 'validate_callback' => 'not callable' ) );
	}
);

$artifact_types = wp_get_agent_package_artifact_types();
$artifact_type  = wp_get_agent_package_artifact_type( 'DATAMACHINE/FLOW' );
agents_api_smoke_assert_equals( array( 'datamachine/flow', 'intelligence/brain' ), array_keys( $artifact_types ), 'artifact type slugs are normalized and collected', $failures, $passes );
agents_api_smoke_assert_equals( true, $artifact_type instanceof WP_Agent_Package_Artifact_Type, 'artifact type getter returns object', $failures, $passes );
agents_api_smoke_assert_equals( 'Package flow artifact', $artifact_type ? $artifact_type->get_label() : '', 'artifact type label is preserved', $failures, $passes );
agents_api_smoke_assert_equals( true, is_callable( $artifact_type ? $artifact_type->get_validate_callback() : null ), 'validate callback is preserved', $failures, $passes );
agents_api_smoke_assert_equals( true, is_callable( $artifact_type ? $artifact_type->get_diff_callback() : null ), 'diff callback is preserved', $failures, $passes );
agents_api_smoke_assert_equals( true, is_callable( $artifact_type ? $artifact_type->get_import_callback() : null ), 'import callback is preserved', $failures, $passes );
agents_api_smoke_assert_equals( true, is_callable( $artifact_type ? $artifact_type->get_delete_callback() : null ), 'delete callback is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'owner' => 'example' ), $artifact_type ? $artifact_type->get_meta() : array(), 'artifact type meta is preserved', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_agent_package_artifact_type( 'datamachine/flow' ), 'wp_has_agent_package_artifact_type reports registered slug', $failures, $passes );
agents_api_smoke_assert_equals( false, wp_has_agent_package_artifact_type( 'broken' ), 'invalid unnamespaced type is rejected', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $GLOBALS['__agents_api_smoke_wrong'] ), 'duplicate and invalid type registrations emit notices', $failures, $passes );
$removed = wp_unregister_agent_package_artifact_type( 'intelligence/brain' );
agents_api_smoke_assert_equals( true, $removed instanceof WP_Agent_Package_Artifact_Type, 'unregister returns removed artifact type', $failures, $passes );
agents_api_smoke_assert_equals( false, wp_has_agent_package_artifact_type( 'intelligence/brain' ), 'unregister removes artifact type', $failures, $passes );
$GLOBALS['__agents_api_smoke_wrong'] = array();
wp_register_agent_package_artifact_type( 'outside/hook', array( 'label' => 'Outside Hook' ) );
agents_api_smoke_assert_equals( 1, count( $GLOBALS['__agents_api_smoke_wrong'] ), 'outside-hook direct artifact type registration is rejected', $failures, $passes );

echo "\n[4] Adopter contracts stay runtime-neutral:\n";
class Agents_API_Package_Smoke_Adopter implements WP_Agent_Package_Adopter_Interface {
	public function diff( WP_Agent_Package $package ): WP_Agent_Package_Adoption_Diff {
		return new WP_Agent_Package_Adoption_Diff(
			'needs-adoption',
			array(
				array(
					'type'       => 'agent',
					'slug'       => $package->get_agent()->get_slug(),
					'operation'  => 'create',
				),
			)
		);
	}

	public function adopt( WP_Agent_Package $package, array $options = array() ): WP_Agent_Package_Adoption_Result {
		unset( $options );
		return new WP_Agent_Package_Adoption_Result( 'adopted', $package->get_agent()->get_slug(), array( 'Package accepted.' ) );
	}
}

$adopter = new Agents_API_Package_Smoke_Adopter();
$diff    = $adopter->diff( $package );
$result  = $adopter->adopt( $package );
agents_api_smoke_assert_equals( 'needs-adoption', $diff->get_status(), 'adopter diff exposes generic status', $failures, $passes );
agents_api_smoke_assert_equals( 'agent', $diff->get_changes()[0]['type'] ?? '', 'adopter diff can describe generic agent changes', $failures, $passes );
agents_api_smoke_assert_equals( 'adopted', $result->get_status(), 'adopter result exposes generic status', $failures, $passes );
agents_api_smoke_assert_equals( 'wordpress-com-wiki', $result->get_agent_slug(), 'adopter result references normalized agent slug', $failures, $passes );

echo "\n[5] Public package contract has no product vocabulary:\n";
$contract_files = array(
	'agents-api/inc/class-wp-agent-package.php',
	'agents-api/inc/class-wp-agent-package-artifact.php',
	'agents-api/inc/class-wp-agent-package-artifact-type.php',
	'agents-api/inc/class-wp-agent-package-artifacts-registry.php',
	'agents-api/inc/class-wp-agent-package-adopter-interface.php',
	'agents-api/inc/class-wp-agent-package-adoption-diff.php',
	'agents-api/inc/class-wp-agent-package-adoption-result.php',
	'agents-api/inc/register-agent-package-artifacts.php',
);
$forbidden = array( 'DataMachine\\', 'pipeline', 'flow', 'job', 'handler', 'Intelligence', 'wpcom', 'Dolly', 'Odie' );
$matches   = array();
foreach ( $contract_files as $contract_file ) {
	$source = (string) file_get_contents( dirname( __DIR__ ) . '/' . $contract_file );
	foreach ( $forbidden as $needle ) {
		if ( str_contains( $source, $needle ) ) {
			$matches[] = $contract_file . ' contains ' . $needle;
		}
	}
}
agents_api_smoke_assert_equals( array(), $matches, 'package contract files avoid product-specific vocabulary', $failures, $passes );

agents_api_smoke_finish( 'Agents API package contract', $failures, $passes );
