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

defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

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
$ai_step   = (string) file_get_contents( $root . '/inc/Core/Steps/AI/AIStep.php' );
$bootstrap = (string) file_get_contents( $root . '/inc/bootstrap.php' );

echo "agent-bundle-runner-contract-smoke\n";

echo "\n[1] Ability exposes the headless runner contract\n";
foreach ( array(
	'datamachine/run-agent-bundle' => 'run-agent-bundle ability registered',
	'runAgentBundleInputSchema'    => 'dedicated input schema declared',
	'runAgentBundleOutputSchema'   => 'dedicated output schema declared',
	'AgentBundleRunner'            => 'ability delegates to runner service',
	'runRuntimeAgentBundle'        => 'generic runtime run adapter declared',
	"'show_in_rest' => true"      => 'ability is REST-visible for headless callers',
	"'readonly'    => false"      => 'ability marks execution as mutating',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $abilities, $needle, $label, $failures, $passes );
}
datamachine_bundle_runner_contains( $bootstrap, "add_filter( 'wp_agent_runtime_run_bundle'", 'Data Machine registers generic runtime run seam', $failures, $passes );

echo "\n[2] Runner projects bundles to ephemeral workflows\n";
foreach ( array(
	'AgentBundleArrayAdapter::from_array_bundle' => 'runner consumes portable bundle documents',
	'BundleSourceAuth::build_resolve_context'    => 'runner shares bundle token resolution with import/install surfaces',
	'workflow_from_bundle_flow'                  => 'runner converts selected bundle flow to workflow steps',
	'ExecuteWorkflowAbility'                     => 'runner reuses existing headless workflow executor',
	'DrainJobAbility'                            => 'runner can drain jobs for final result callers',
	'wp_agent_import_runtime_bundles'            => 'runner uses generic runtime bundle import helper when available',
	"apply_filters( 'wp_agent_runtime_import_bundle'" => 'runner falls back to generic runtime bundle import filter',
	"'runtime_imports'"                         => 'runner returns runtime import diagnostics',
	"'completion_outcome'"                      => 'runner returns completion outcome summary',
	"'outputs'"                                 => 'runner returns semantic output map',
	"'output_diagnostics'"                      => 'runner returns semantic output diagnostics',
	"'transcript_refs'"                         => 'runner returns transcript references',
	"'export_refs'"                             => 'runner returns export references',
	'datamachine_directives_enabled'             => 'runner owns directive controls inside Data Machine',
	'datamachine_resolved_tools'                 => 'runner owns runtime tool controls inside Data Machine',
	"'wait_for_completion'"                     => 'runner exposes opt-in final result mode',
	"'engine_data'"                              => 'runner returns final engine data after waiting',
	"'schema'       => 'datamachine/agent-bundle-run/v1'" => 'runner returns stable response schema',
	"'dry_run'      => true"                     => 'runner supports dry-run projection without job creation',
	"\$initial_data['job_source']   = (string) ( \$input['job_source'] ?? 'agent_bundle' );" => 'runner stamps agent_bundle job source by default',
	'ensure_runtime_agent_identity'               => 'runner resolves or imports a runtime agent identity before execution',
	'stamp_runtime_agent_identity'                => 'runner stamps runtime agent identity into workflow initial data',
	'AgentIdentityResolver'                       => 'runner reuses canonical agent identity resolution',
	"\$job_snapshot['user_id'] = \$owner_id"       => 'runner stamps owner user into the job snapshot',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $runner, $needle, $label, $failures, $passes );
}
datamachine_bundle_runner_contains( $abilities, "'outputs'", 'ability output schema advertises semantic outputs', $failures, $passes );
datamachine_bundle_runner_contains( $abilities, "'output_diagnostics'", 'ability output schema advertises semantic output diagnostics', $failures, $passes );

echo "\n[3] Runner exposes semantic outputs without hiding raw engine data\n";
require_once $root . '/inc/Engine/Bundle/AgentBundleRunner.php';
$runner_reflection = new ReflectionClass( DataMachine\Engine\Bundle\AgentBundleRunner::class );
$runner_instance   = $runner_reflection->newInstanceWithoutConstructor();
$output_projection = $runner_reflection->getMethod( 'output_projection' );
$projected_outputs = $output_projection->invoke(
	$runner_instance,
	array(
		'engine_data' => array(
			'completion_assertions_required' => array( 'engine_data_keys' => array( 'issue_number', 'issue_url', 'missing_result_url' ) ),
			'issue_number'                   => 123,
			'issue_url'                      => 'https://github.com/Extra-Chill/data-machine/issues/2519',
			'result_path'                    => 'artifacts/result.json',
			'agent_id'                       => 7,
			'store_idea_agent'              => array(
				'issue_number' => 456,
				'issue_url'    => 'https://github.com/chubes4/wp-site-generator/issues/456',
			),
			'outputs'                        => array( 'summary_title' => 'semantic output projection' ),
		),
	),
	array(
		'engine_data_outputs' => array(
			'store_issue_number' => 'metadata.engine_data.store_idea_agent.issue_number',
			'store_issue_url'    => 'metadata.engine_data.store_idea_agent.issue_url',
			'missing_store_url'  => 'metadata.engine_data.store_idea_agent.missing_url',
		),
	)
);
datamachine_bundle_runner_assert( 123 === ( $projected_outputs['outputs']['issue_number'] ?? null ), 'declared issue_number output is projected', $failures, $passes );
datamachine_bundle_runner_assert( 'https://github.com/Extra-Chill/data-machine/issues/2519' === ( $projected_outputs['outputs']['issue_url'] ?? null ), 'declared issue_url output is projected', $failures, $passes );
datamachine_bundle_runner_assert( 'artifacts/result.json' === ( $projected_outputs['outputs']['result_path'] ?? null ), 'common scalar task output is projected', $failures, $passes );
datamachine_bundle_runner_assert( 'semantic output projection' === ( $projected_outputs['outputs']['summary_title'] ?? null ), 'explicit outputs map is projected', $failures, $passes );
datamachine_bundle_runner_assert( 456 === ( $projected_outputs['outputs']['store_issue_number'] ?? null ), 'declared nested engine_data output number is projected', $failures, $passes );
datamachine_bundle_runner_assert( 'https://github.com/chubes4/wp-site-generator/issues/456' === ( $projected_outputs['outputs']['store_issue_url'] ?? null ), 'declared nested engine_data output URL is projected', $failures, $passes );
datamachine_bundle_runner_assert( ! isset( $projected_outputs['outputs']['agent_id'] ), 'runtime identity fields are not projected as outputs', $failures, $passes );
datamachine_bundle_runner_assert( array( 'missing_result_url', 'missing_store_url' ) === ( $projected_outputs['diagnostics']['missing_outputs'] ?? null ), 'missing declared outputs are diagnosed semantically', $failures, $passes );

echo "\n[4] WP-CLI wraps the same ability instead of duplicating runner internals\n";
foreach ( array(
	'@subcommand run-bundle'       => 'run-bundle subcommand declared',
	'AgentAbilities::runAgentBundle' => 'CLI calls ability callback',
	'--initial-data=<json>'        => 'CLI accepts JSON initial data',
	'--dry-run'                    => 'CLI exposes dry-run projection',
	'--wait'                       => 'CLI exposes final result mode',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $cli, $needle, $label, $failures, $passes );
}

echo "\n[5] Runner supports run-scoped provider/model config\n";
foreach ( array(
	"'provider'            => array("                                           => 'run-agent-bundle schema accepts provider',
	"'model'               => array("                                           => 'run-agent-bundle schema accepts model',
	'apply_runtime_model_config'                                              => 'runner projects provider/model into initial data',
	"\$job_snapshot['default_provider']"                                      => 'runner stamps job-scoped default provider',
	"\$job_snapshot['default_model']"                                         => 'runner stamps job-scoped default model',
	"\$mode_models['pipeline']"                                               => 'runner stamps pipeline mode model config',
	'resolveModelFromJobSnapshot'                                             => 'AI step reads run-scoped model config',
	'resolveModelForExecutionModes( $agent_id, $execution_modes, $job_snapshot )' => 'AI validation uses job-scoped model config',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $abilities . $runner . $ai_step, $needle, $label, $failures, $passes );
}

echo "\n[6] Boundary stays generic\n";
foreach ( array( 'homeboy', 'wp-codebox' ) as $forbidden ) {
	datamachine_bundle_runner_assert( false === stripos( $runner, $forbidden ), "runner does not mention {$forbidden}", $failures, $passes );
}
foreach ( array( 'DataMachine\\Core\\Database\\Agents', 'DataMachine\\Core\\Database\\Flows', 'DataMachine\\Core\\Database\\Pipelines' ) as $forbidden ) {
	datamachine_bundle_runner_assert( false === strpos( $runner, $forbidden ), "runtime runner does not require caller-facing {$forbidden}", $failures, $passes );
}

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " bundle runner assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAgent bundle runner contract smoke passed ({$passes} assertions).\n";
