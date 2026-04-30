<?php
/**
 * Pure-PHP smoke test for Agents API package/adoption contract (#1686).
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
				'type'        => 'prompt',
				'slug'        => 'Historical Synthesis',
				'label'       => 'Historical synthesis',
				'description' => 'Converts source packets into grounded wiki notes.',
				'source'      => 'prompts/historical-synthesis.md',
			),
			array(
				'type'   => 'memory',
				'slug'   => 'Source Notes',
				'source' => 'memory/MEMORY.md',
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
agents_api_smoke_assert_equals( 'memory', $manifest['artifacts'][0]['type'], 'artifacts sort deterministically by type', $failures, $passes );
agents_api_smoke_assert_equals( 'prompt', $manifest['artifacts'][1]['type'], 'prompt artifact is generic package metadata', $failures, $passes );
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
	$threw = str_contains( $e->getMessage(), 'artifacts require type and slug' );
}
agents_api_smoke_assert_equals( true, $threw, 'malformed artifact is rejected', $failures, $passes );

echo "\n[3] Adopter contracts stay runtime-neutral:\n";
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

echo "\n[4] Public package contract has no product vocabulary:\n";
$contract_files = array(
	'agents-api/inc/class-wp-agent-package.php',
	'agents-api/inc/class-wp-agent-package-adopter-interface.php',
	'agents-api/inc/class-wp-agent-package-adoption-diff.php',
	'agents-api/inc/class-wp-agent-package-adoption-result.php',
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
