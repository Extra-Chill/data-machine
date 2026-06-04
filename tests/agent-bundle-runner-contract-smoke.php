<?php
/**
 * Smoke test for the headless agent bundle runner contract.
 *
 * Run with: php tests/agent-bundle-runner-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

$root     = dirname( __DIR__ );
$failures = array();
$passes   = 0;

function datamachine_bundle_runner_assert( bool $condition, string $label, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  PASS {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  FAIL {$label}\n";
}

function datamachine_bundle_runner_contains( string $source, string $needle, string $label, array &$failures, int &$passes ): void {
	datamachine_bundle_runner_assert( false !== strpos( $source, $needle ), $label, $failures, $passes );
}

$abilities = (string) file_get_contents( $root . '/inc/Abilities/AgentAbilities.php' );
$cli       = (string) file_get_contents( $root . '/inc/Cli/Commands/AgentBundleCommand.php' );
$runner    = (string) file_get_contents( $root . '/inc/Engine/Bundle/AgentBundleRunner.php' );

echo "agent-bundle-runner-contract-smoke\n";

echo "\n[1] Ability exposes the headless runner contract\n";
foreach ( array(
	'datamachine/run-agent-bundle' => 'run-agent-bundle ability registered',
	'runAgentBundleInputSchema'    => 'dedicated input schema declared',
	'AgentBundleRunner'            => 'ability delegates to runner service',
	"'show_in_rest' => true"      => 'ability is REST-visible for headless callers',
	"'readonly'    => false"      => 'ability marks execution as mutating',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $abilities, $needle, $label, $failures, $passes );
}

echo "\n[2] Runner projects bundles to ephemeral workflows\n";
foreach ( array(
	'AgentBundleArrayAdapter::from_array_bundle' => 'runner consumes portable bundle documents',
	'BundleSourceAuth::build_resolve_context'    => 'runner shares bundle token resolution with import/install surfaces',
	'workflow_from_bundle_flow'                  => 'runner converts selected bundle flow to workflow steps',
	'ExecuteWorkflowAbility'                     => 'runner reuses existing headless workflow executor',
	"'schema'       => 'datamachine/agent-bundle-run/v1'" => 'runner returns stable response schema',
	"'dry_run'      => true"                     => 'runner supports dry-run projection without job creation',
	"\$initial_data['job_source']   = (string) ( \$input['job_source'] ?? 'agent_bundle' );" => 'runner stamps agent_bundle job source by default',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $runner, $needle, $label, $failures, $passes );
}

echo "\n[3] WP-CLI wraps the same ability instead of duplicating runner internals\n";
foreach ( array(
	'@subcommand run-bundle'       => 'run-bundle subcommand declared',
	'AgentAbilities::runAgentBundle' => 'CLI calls ability callback',
	'--initial-data=<json>'        => 'CLI accepts JSON initial data',
	'--dry-run'                    => 'CLI exposes dry-run projection',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $cli, $needle, $label, $failures, $passes );
}

echo "\n[4] Boundary stays generic\n";
foreach ( array( 'homeboy', 'wp-codebox' ) as $forbidden ) {
	datamachine_bundle_runner_assert( false === stripos( $runner, $forbidden ), "runner does not mention {$forbidden}", $failures, $passes );
}

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " bundle runner assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAgent bundle runner contract smoke passed ({$passes} assertions).\n";
