<?php
/**
 * Pure-PHP smoke test for the agents-api substrate-load guardrail (#2477, #2500).
 *
 * Simulates the failure mode: AGENTS_API_LOADED is defined but the substrate class set is
 * incomplete (canary: WP_Agent_Package_Artifact_Hasher absent). Triggers include an older
 * standalone copy winning the load race, a partial/aborted bootstrap, or a load-order
 * regression. The guardrail must top up the missing class files from data-machine's OWN
 * bundled copy — UNCONDITIONALLY whenever the bootstrap constant is defined — so the
 * substrate is complete before any namespaced delegator references a WP_Agent_* global,
 * without ever fataling on a bare "Class ... not found".
 *
 * Run with: php tests/agents-api-dualload-guardrail-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'DATAMACHINE_VERSION' ) ) {
	define( 'DATAMACHINE_VERSION', 'test' );
}

$failures = array();
$passes   = 0;

echo "agents-api-dualload-guardrail-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$bundled_dir    = dirname( __DIR__ ) . '/vendor/wordpress/agents-api';
$bootstrap_path = $bundled_dir . '/agents-api.php';
$canary         = 'WP_Agent_Package_Artifact_Hasher';

// Pre-conditions: the bundled copy must ship the canary file (otherwise the whole repro
// premise is wrong). This documents the dependency the guardrail relies on.
agents_api_smoke_assert_equals(
	true,
	is_readable( $bootstrap_path ),
	'bundled agents-api bootstrap is present',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	true,
	is_readable( $bundled_dir . '/src/Packages/class-wp-agent-package-artifact-hasher.php' ),
	'bundled agents-api ships the canary class file',
	$failures,
	$passes
);

// Load the guardrail under test.
require_once dirname( __DIR__ ) . '/inc/agents-api-guardrail.php';

echo "\n[1] Require-target parser reads the bundled bootstrap require list:\n";
$require_targets = datamachine_agents_api_bundled_require_targets( $bootstrap_path );
agents_api_smoke_assert_equals(
	true,
	count( $require_targets ) > 10,
	'parser extracts the bundled src/ require targets',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	true,
	in_array( 'src/Packages/class-wp-agent-package-artifact-hasher.php', $require_targets, true ),
	'parser includes the canary class file target',
	$failures,
	$passes
);
$only_src_targets = true;
foreach ( $require_targets as $relative_path ) {
	if ( ! str_starts_with( $relative_path, 'src/' ) ) {
		$only_src_targets = false;
		break;
	}
}
agents_api_smoke_assert_equals(
	true,
	$only_src_targets,
	'parser only returns src/ require targets',
	$failures,
	$passes
);

echo "\n[2] Simulate the collision: older copy won, canary class is absent:\n";
// Mimic the older standalone copy winning the load race.
if ( ! defined( 'AGENTS_API_LOADED' ) ) {
	define( 'AGENTS_API_LOADED', true );
}
agents_api_smoke_assert_equals(
	false,
	class_exists( $canary, false ),
	'canary class is not loaded before self-heal',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	true,
	datamachine_agents_api_skew_detected(),
	'guardrail detects the version-skew collision',
	$failures,
	$passes
);

echo "\n[3] Self-heal tops up the missing class from the bundled copy:\n";
// Provide the AGENTS_API_PATH constant the bundled class files expect, pointing at the
// bundled tree (as the bundled bootstrap would have, had it not early-returned).
if ( ! defined( 'AGENTS_API_PATH' ) ) {
	define( 'AGENTS_API_PATH', rtrim( $bundled_dir, '/' ) . '/' );
}
datamachine_agents_api_run_guardrail( $bundled_dir );
agents_api_smoke_assert_equals(
	true,
	class_exists( $canary ),
	'canary class is available after guardrail self-heal',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	false,
	datamachine_agents_api_skew_detected(),
	'skew no longer detected after self-heal',
	$failures,
	$passes
);

echo "\n[4] Guardrail is idempotent — a second run is a no-op:\n";
datamachine_agents_api_run_guardrail( $bundled_dir );
agents_api_smoke_assert_equals(
	true,
	class_exists( $canary ),
	'canary class remains available after a second guardrail run',
	$failures,
	$passes
);

echo "\n[5] Namespaced delegator resolves the topped-up global (the #2500 regression):\n";
// This is the exact call path that fataled on production: the namespaced
// AgentBundleArtifactHasher delegating to the global WP_Agent_Package_Artifact_Hasher.
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactHasher.php';
$delegated_hash = \DataMachine\Engine\Bundle\AgentBundleArtifactHasher::hash(
	array(
		'a' => 1,
		'b' => array( 2, 3 ),
	)
);
agents_api_smoke_assert_equals(
	true,
	is_string( $delegated_hash ) && 64 === strlen( $delegated_hash ),
	'AgentBundleArtifactHasher::hash() returns a sha256 without fataling',
	$failures,
	$passes
);

agents_api_smoke_finish( 'Agents API substrate-load guardrail', $failures, $passes );
