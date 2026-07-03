<?php
/**
 * Pure-PHP smoke test for bundling Agents API into Data Machine releases (#2178).
 *
 * Run with: php tests/release-bundled-agents-api-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;
$root     = dirname( __DIR__ );

echo "release-bundled-agents-api-smoke\n";

function datamachine_release_bundle_assert( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  FAIL {$name}\n";
}

function datamachine_release_bundle_json_file( string $path ): array {
	$decoded = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $decoded ) ) {
		throw new RuntimeException( "Unable to decode JSON file: {$path}" );
	}

	return $decoded;
}

$composer = datamachine_release_bundle_json_file( $root . '/composer.json' );
$lock     = datamachine_release_bundle_json_file( $root . '/composer.lock' );
$harness   = datamachine_release_bundle_json_file( $root . '/home' . 'boy.json' );
$blueprint = datamachine_release_bundle_json_file( $root . '/blueprints/playground.json' );

$plugin_source = (string) file_get_contents( $root . '/data-machine.php' );

echo "\n[1] Release metadata bundles Agents API instead of requiring a separate plugin:\n";
datamachine_release_bundle_assert( isset( $composer['require']['wordpress/agents-api'] ), 'composer production dependencies include wordpress/agents-api', $failures, $passes );
datamachine_release_bundle_assert( ! isset( $composer['require-dev']['wordpress/agents-api'] ), 'composer dev dependencies do not own wordpress/agents-api', $failures, $passes );
datamachine_release_bundle_assert( false === strpos( $plugin_source, 'Requires Plugins: agents-api' ), 'plugin header does not require standalone Agents API activation', $failures, $passes );

$runtime_packages = array_column( $lock['packages'] ?? array(), 'name' );
$dev_packages     = array_column( $lock['packages-dev'] ?? array(), 'name' );
datamachine_release_bundle_assert( in_array( 'wordpress/agents-api', $runtime_packages, true ), 'composer.lock keeps wordpress/agents-api in runtime packages', $failures, $passes );
datamachine_release_bundle_assert( ! in_array( 'wordpress/agents-api', $dev_packages, true ), 'composer.lock keeps wordpress/agents-api out of dev packages', $failures, $passes );

echo "\n[2] Runtime bootstrap loads bundled Agents API unless another copy already loaded:\n";
datamachine_release_bundle_assert( false !== strpos( $plugin_source, "defined( 'AGENTS_API_LOADED' )" ), 'Data Machine detects a preloaded Agents API substrate', $failures, $passes );
datamachine_release_bundle_assert( false !== strpos( $plugin_source, 'datamachine_load_bundled_agents_api' ), 'Data Machine centralizes Agents API load selection', $failures, $passes );
datamachine_release_bundle_assert( false !== strpos( $plugin_source, 'require_once $bundled_file' ), 'Data Machine requires bundled Agents API when no substrate is active', $failures, $passes );
datamachine_release_bundle_assert( false !== strpos( $plugin_source, "'loaded'          => 'external'" ), 'Data Machine skips bundled Agents API when AGENTS_API_LOADED is predefined', $failures, $passes );
datamachine_release_bundle_assert( false !== strpos( $plugin_source, 'runtime substrate skew' ), 'Data Machine warns on external/bundled Agents API version mismatch', $failures, $passes );
datamachine_release_bundle_assert( false !== strpos( $plugin_source, "vendor/wordpress/agents-api/agents-api.php" ), 'Data Machine references the bundled Agents API bootstrap', $failures, $passes );
datamachine_release_bundle_assert( is_file( $root . '/vendor/wordpress/agents-api/agents-api.php' ), 'local bundled Agents API bootstrap exists for package validation', $failures, $passes );

echo "\n[3] Validation paths no longer install a separate GitHub-only Agents API plugin:\n";
$validation_dependencies = $harness['extensions']['wordpress']['settings']['validation_dependencies'] ?? '';
datamachine_release_bundle_assert( false === strpos( (string) $validation_dependencies, 'agents-api' ), 'validation dependency list does not request standalone agents-api', $failures, $passes );

$blueprint_payload = json_encode( $blueprint );
datamachine_release_bundle_assert( is_string( $blueprint_payload ) && false === strpos( $blueprint_payload, 'github.com/Automattic/agents-api' ), 'Playground blueprint does not install the GitHub-only Agents API plugin', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " release bundle assertions failed.\n";
	exit( 1 );
}

echo "\nRelease bundle smoke passed ({$passes} assertions).\n";
