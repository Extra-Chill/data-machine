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

if ( ! function_exists( 'datamachine_merge_engine_data' ) ) {
	function datamachine_merge_engine_data( int $job_id, array $data ): bool {
		$GLOBALS['datamachine_bundle_runner_engine_data_merges'][] = array(
			'job_id' => $job_id,
			'data'   => $data,
		);
		return true;
	}
}

if ( ! function_exists( 'datamachine_append_engine_state_event' ) ) {
	function datamachine_append_engine_state_event( int $job_id, string $type, array $patch, array $metadata = array() ): ?array {
		$GLOBALS['datamachine_bundle_runner_engine_data_merges'][] = array(
			'job_id'   => $job_id,
			'type'     => $type,
			'data'     => $patch,
			'metadata' => $metadata,
		);
		return array( 'version' => count( $GLOBALS['datamachine_bundle_runner_engine_data_merges'] ) );
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
	'runtimePackageRunHandler'     => 'Agents API runtime-package handler declared',
	"'show_in_rest' => true"      => 'ability is REST-visible for headless callers',
	"'readonly'    => false"      => 'ability marks execution as mutating',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $abilities, $needle, $label, $failures, $passes );
}
datamachine_bundle_runner_contains( $bootstrap, "add_filter( 'wp_agent_runtime_import_bundle', array( AgentAbilities::class, 'importRuntimeAgentBundle' ), 5, 4 )", 'Data Machine importer runs before generic runtime bundle fallback', $failures, $passes );
datamachine_bundle_runner_contains( $bootstrap, "add_filter( 'wp_agent_runtime_run_bundle'", 'Data Machine registers generic runtime run seam', $failures, $passes );
datamachine_bundle_runner_contains( $bootstrap, "add_filter( 'wp_agent_runtime_package_run_handler', array( AgentAbilities::class, 'runtimePackageRunHandler' ), 10, 3 )", 'Data Machine registers Agents API runtime-package handler', $failures, $passes );
datamachine_bundle_runner_contains( $abilities, 'array_key_exists( $key, $input )', 'runtime-package handler lifts control fields from package input', $failures, $passes );
datamachine_bundle_runner_contains( $abilities, 'array_replace( $raw_input[\'options\']', 'runtime-package handler falls back to raw package options', $failures, $passes );

echo "\n[2] Runner projects bundles to ephemeral workflows\n";
foreach ( array(
	'AgentBundleArrayAdapter::from_array_bundle' => 'runner consumes portable bundle documents',
	'BundleSourceAuth::build_resolve_context'    => 'runner shares bundle token resolution with import/install surfaces',
	'workflow_from_bundle_flow'                  => 'runner converts selected bundle flow to workflow steps',
	'workflow_override_from_input'               => 'runner supports caller-supplied workflow overrides',
	'execute_workflow_path'                      => 'runner accepts file-backed workflow overrides',
	'ExecuteWorkflowAbility'                     => 'runner reuses existing headless workflow executor',
	'DrainJobAbility'                            => 'runner can drain jobs for final result callers',
	'wp_agent_import_runtime_bundles'            => 'runner uses generic runtime bundle import helper when available',
	'wp_agent_import_runtime_bundles( $bundle_specs, $import_input )' => 'runner delegates runtime imports through the generic helper seam',
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
datamachine_bundle_runner_contains( $abilities, "'required_outputs'", 'ability input schema accepts required semantic outputs', $failures, $passes );
datamachine_bundle_runner_contains( $abilities, "'required_artifacts'", 'ability input schema accepts required typed artifacts', $failures, $passes );
datamachine_bundle_runner_contains( $abilities, "'engine_data_outputs'", 'ability input schema accepts semantic output mappings', $failures, $passes );
datamachine_bundle_runner_contains( $ai_step, "\$payload['tool_recorders']", 'AI step forwards configured tool recorders to the loop', $failures, $passes );

echo "\n[3] Runner exposes semantic outputs while preserving raw engine data\n";
require_once $root . '/inc/Core/JobStatus.php';
require_once $root . '/inc/Core/DataPath.php';
require_once $root . '/inc/Engine/AI/Tools/HostToolPolicy.php';
require_once $root . '/inc/Engine/Bundle/AgentBundleRunner.php';
require_once $root . '/inc/Abilities/Flow/FlowHelpers.php';
require_once $root . '/inc/Abilities/Flow/QueueAbility.php';
require_once $root . '/inc/Core/Steps/FlowStepConfig.php';
require_once $root . '/inc/Core/Steps/FlowStepConfigFactory.php';
require_once $root . '/inc/Core/Steps/WorkflowConfigFactory.php';
$runner_reflection = new ReflectionClass( DataMachine\Engine\Bundle\AgentBundleRunner::class );
$runner_instance   = $runner_reflection->newInstanceWithoutConstructor();

echo "\n[2a] Runner accepts direct workflow overrides\n";
$workflow_override = $runner_reflection->getMethod( 'workflow_override_from_input' );
$inline_override   = $workflow_override->invoke(
	$runner_instance,
	array(
		'execute_workflow' => array(
			'workflow'     => array(
				'steps' => array(
					array(
						'step_type' => 'ai',
						'label'     => 'Inline override',
					),
				),
			),
			'initial_data' => array( 'job_source' => 'inline_override' ),
		),
	)
);
datamachine_bundle_runner_assert( true === ( $inline_override['success'] ?? false ), 'inline workflow override resolves', $failures, $passes );
datamachine_bundle_runner_assert( 'Inline override' === ( $inline_override['workflow']['steps'][0]['label'] ?? null ), 'inline workflow override unwraps workflow payload', $failures, $passes );
datamachine_bundle_runner_assert( 'inline_override' === ( $inline_override['initial_data']['job_source'] ?? null ), 'inline workflow override preserves initial_data', $failures, $passes );

$workflow_path = sys_get_temp_dir() . '/datamachine-agent-bundle-workflow-override-' . uniqid( '', true ) . '.json';
file_put_contents(
	$workflow_path,
	json_encode(
		array(
			'workflow' => array(
				'steps' => array(
					array(
						'step_type' => 'ai',
						'label'     => 'Path override',
					),
				),
			),
		)
	)
);
$path_override = $workflow_override->invoke( $runner_instance, array( 'execute_workflow_path' => $workflow_path ) );
@unlink( $workflow_path );
datamachine_bundle_runner_assert( true === ( $path_override['success'] ?? false ), 'file workflow override resolves', $failures, $passes );
datamachine_bundle_runner_assert( 'Path override' === ( $path_override['workflow']['steps'][0]['label'] ?? null ), 'file workflow override unwraps workflow payload', $failures, $passes );

$initial_data_from_workflow_input = $runner_reflection->getMethod( 'initial_data_from_workflow_input' );
$runtime_initial_data             = $initial_data_from_workflow_input->invoke(
	$runner_instance,
	array(
		'input' => array(
			'wait_for_completion' => true,
			'site_kind'           => 'store',
			'artifacts'           => array(
				'concept_packet' => array(
					'payload' => array( 'title' => 'Kiln Shelf Supply' ),
				),
			),
			'concept_packet'     => array( 'title' => 'Kiln Shelf Supply' ),
		),
	)
);
datamachine_bundle_runner_assert( 'store' === ( $runtime_initial_data['site_kind'] ?? null ), 'runtime workflow input scalar is promoted to initial_data', $failures, $passes );
datamachine_bundle_runner_assert( 'Kiln Shelf Supply' === ( $runtime_initial_data['concept_packet']['title'] ?? null ), 'runtime workflow artifact alias is promoted to initial_data', $failures, $passes );
datamachine_bundle_runner_assert( 'Kiln Shelf Supply' === ( $runtime_initial_data['artifacts']['concept_packet']['payload']['title'] ?? null ), 'runtime workflow artifacts map is promoted to initial_data', $failures, $passes );
datamachine_bundle_runner_assert( ! isset( $runtime_initial_data['wait_for_completion'] ), 'runtime workflow control field is not promoted to initial_data', $failures, $passes );

$output_projection = $runner_reflection->getMethod( 'output_projection' );
$projected_outputs = $output_projection->invoke(
	$runner_instance,
	array(
		'engine_data' => array(
			'completion_assertions_required' => array(
				'engine_data_keys' => array( 'issue_number', 'issue_url', 'missing_result_url' ),
				'artifact_outputs' => array(
					array(
						'output_key' => 'concept_packet',
						'schema'     => 'example-agent/ConceptPacket/v1',
						'artifact'   => 'ConceptPacket',
					),
				),
			),
			'issue_number'                   => 123,
			'issue_url'                      => 'https://github.com/Extra-Chill/data-machine/issues/2519',
			'result_path'                    => 'artifacts/result.json',
			'agent_id'                       => 7,
			'store_idea_agent'              => array(
				'issue_number' => 456,
				'issue_url'    => 'https://github.com/Extra-Chill/example-agent/issues/456',
			),
			'outputs'                        => array(
				'summary_title'    => 'semantic output projection',
				'typed_artifacts'  => array(
					'concept_packet' => array(
						'schema'   => 'example-agent/ConceptPacket/v1',
						'artifact' => 'ConceptPacket',
						'payload'  => array( 'title' => 'Projected typed artifact' ),
					),
				),
			),
		),
	),
	array(
		'required_outputs'    => array( 'issue_number', 'issue_url' ),
		'required_artifacts'   => array(),
		'engine_data_outputs' => array(
			'store_issue_number' => 'metadata.engine_data.store_idea_agent.issue_number',
			'store_issue_url'    => 'metadata.engine_data.store_idea_agent.issue_url',
			'missing_store_url'  => 'metadata.engine_data.store_idea_agent.missing_url',
			'concept_packet'     => 'metadata.engine_data.outputs.typed_artifacts.concept_packet.payload',
		),
	)
);
datamachine_bundle_runner_assert( 123 === ( $projected_outputs['outputs']['issue_number'] ?? null ), 'declared issue_number output is projected', $failures, $passes );
datamachine_bundle_runner_assert( 'https://github.com/Extra-Chill/data-machine/issues/2519' === ( $projected_outputs['outputs']['issue_url'] ?? null ), 'declared issue_url output is projected', $failures, $passes );
datamachine_bundle_runner_assert( 'artifacts/result.json' === ( $projected_outputs['outputs']['result_path'] ?? null ), 'common scalar task output is projected', $failures, $passes );
datamachine_bundle_runner_assert( 'semantic output projection' === ( $projected_outputs['outputs']['summary_title'] ?? null ), 'explicit outputs map is projected', $failures, $passes );
datamachine_bundle_runner_assert( 456 === ( $projected_outputs['outputs']['store_issue_number'] ?? null ), 'declared nested engine_data output number is projected', $failures, $passes );
datamachine_bundle_runner_assert( 'https://github.com/Extra-Chill/example-agent/issues/456' === ( $projected_outputs['outputs']['store_issue_url'] ?? null ), 'declared nested engine_data output URL is projected', $failures, $passes );
datamachine_bundle_runner_assert( array( 'title' => 'Projected typed artifact' ) === ( $projected_outputs['outputs']['concept_packet'] ?? null ), 'declared typed artifact payload path is projected', $failures, $passes );
datamachine_bundle_runner_assert( ! isset( $projected_outputs['outputs']['agent_id'] ), 'runtime identity fields are not projected as outputs', $failures, $passes );
datamachine_bundle_runner_assert( array( 'issue_number', 'issue_url', 'missing_result_url' ) === ( $projected_outputs['diagnostics']['required_outputs'] ?? null ), 'required semantic outputs are diagnosed', $failures, $passes );
datamachine_bundle_runner_assert( array( 'concept_packet' ) === ( $projected_outputs['diagnostics']['required_artifacts'] ?? null ), 'required typed artifacts are diagnosed', $failures, $passes );
datamachine_bundle_runner_assert( array( 'missing_result_url', 'missing_store_url' ) === ( $projected_outputs['diagnostics']['missing_outputs'] ?? null ), 'missing declared outputs are diagnosed semantically', $failures, $passes );

echo "\n[3a] Failed terminal status families are not successful\n";
$success_status = $runner_reflection->getMethod( 'is_success_status' );
datamachine_bundle_runner_assert( false === $success_status->invoke( null, 'failed - ai_response_without_tool_result' ), 'failed-prefixed status is terminal failure', $failures, $passes );
datamachine_bundle_runner_assert( false === $success_status->invoke( null, 'cancelled - operator aborted' ), 'cancelled-prefixed status is terminal failure', $failures, $passes );
datamachine_bundle_runner_assert( true === $success_status->invoke( null, 'completed' ), 'completed status remains successful', $failures, $passes );
datamachine_bundle_runner_assert( true === $success_status->invoke( null, 'completed_no_items' ), 'completed_no_items follows JobStatus success semantics', $failures, $passes );
datamachine_bundle_runner_assert( true === $success_status->invoke( null, 'agent_skipped - no matching source items' ), 'agent_skipped-prefixed status follows JobStatus success semantics', $failures, $passes );

echo "\n[3b] Runtime ability tools consume generic metadata only\n";
$apply_runtime_ability_tools = $runner_reflection->getMethod( 'apply_runtime_ability_tools' );
$initial_data                = array();
$apply_runtime_ability_tools->invokeArgs(
	$runner_instance,
	array(
		&$initial_data,
		array(),
		array(
			'metadata' => array(
				'agent_runtime' => array(
					'ability_tools' => array( array( 'name' => 'datamachine/generic-tool' ) ),
				),
			),
		)
	)
);
datamachine_bundle_runner_assert( 'datamachine/generic-tool' === ( $initial_data['job']['ability_tools'][0]['name'] ?? null ), 'generic agent_runtime ability tools are consumed from bundle metadata', $failures, $passes );

$initial_data = array();
$apply_runtime_ability_tools->invokeArgs(
	$runner_instance,
	array(
		&$initial_data,
		array(),
		array(
			'metadata' => array(
				'runtime' => array(
					'agent_runtime' => array(
						'ability_tools' => array( array( 'name' => 'datamachine/runtime-specific-tool' ) ),
					),
				),
			),
		)
	)
);
datamachine_bundle_runner_assert( empty( $initial_data['job']['ability_tools'] ?? array() ), 'runtime-specific metadata namespaces are ignored by the generic runner', $failures, $passes );

echo "\n[3c] Host tool policy is captured into durable job snapshots\n";
$apply_runtime_host_tool_policy = $runner_reflection->getMethod( 'apply_runtime_host_tool_policy' );
$initial_data                   = array();
$apply_runtime_host_tool_policy->invokeArgs(
	$runner_instance,
	array(
		&$initial_data,
		array(
			'host_tool_policy' => array(
				'schema'           => 'datamachine/host-tool-policy/v1',
				'default_location' => 'runner',
				'tools'            => array(
					'workspace_read' => array( 'execution_location' => 'control_plane' ),
				),
			),
		),
	)
);
datamachine_bundle_runner_assert( 'control_plane' === ( $initial_data['job']['host_tool_policy']['tools']['workspace_read']['execution_location'] ?? null ), 'explicit host tool policy is projected into job snapshot', $failures, $passes );

$previous_host_policy = getenv( 'DATAMACHINE_HOST_TOOL_POLICY_JSON' );
putenv(
	'DATAMACHINE_HOST_TOOL_POLICY_JSON=' . json_encode(
		array(
			'schema'           => 'generic/host-tool-policy/v1',
			'default_location' => 'runner',
			'tools'            => array(
				'workspace_grep' => array( 'execution_location' => 'control_plane' ),
			),
		)
	)
);
$initial_data = array();
$apply_runtime_host_tool_policy->invokeArgs( $runner_instance, array( &$initial_data, array() ) );
if ( false === $previous_host_policy ) {
	putenv( 'DATAMACHINE_HOST_TOOL_POLICY_JSON' );
} else {
	putenv( 'DATAMACHINE_HOST_TOOL_POLICY_JSON=' . $previous_host_policy );
}
datamachine_bundle_runner_assert( 'control_plane' === ( $initial_data['job']['host_tool_policy']['tools']['workspace_grep']['execution_location'] ?? null ), 'environment host tool policy is snapshotted for queued bundle jobs', $failures, $passes );

echo "\n[3d] Successful tool results can be recorded into engine data\n";
require_once $root . '/inc/Engine/AI/conversation-loop.php';
$GLOBALS['datamachine_bundle_runner_engine_data_merges'] = array();
\DataMachine\Engine\AI\datamachine_record_tool_results_to_engine_data(
	array(
		'job_id'         => 77,
		'tool_recorders' => array(
			array(
				'tool'   => 'github_pull_request_publish',
				'record' => array(
					'engine_key' => 'static_site_agent',
					'fields'     => array(
						'branch' => 'data.head',
						'pr_url' => 'data.html_url',
						'slug'   => array(
							'paths'        => array( 'data.head' ),
							'strip_prefix' => 'static/',
						),
					),
				),
			),
		),
	),
	array(
		array(
			'tool_name' => 'github_pull_request_publish',
			'result'    => array(
				'success' => true,
				'data'    => array(
					'head'     => 'static/issue-460-design-direction',
					'html_url' => 'https://github.com/Extra-Chill/example-agent/pull/461',
				),
			),
		),
	)
);
$recorded_merge = $GLOBALS['datamachine_bundle_runner_engine_data_merges'][0] ?? array();
datamachine_bundle_runner_assert( 77 === ( $recorded_merge['job_id'] ?? null ), 'tool recorder writes to the owning job', $failures, $passes );
datamachine_bundle_runner_assert( 'tool_result_recorded' === ( $recorded_merge['type'] ?? null ), 'tool recorder uses versioned append path when available', $failures, $passes );
datamachine_bundle_runner_assert( 'static/issue-460-design-direction' === ( $recorded_merge['data']['static_site_agent']['branch'] ?? null ), 'tool recorder maps branch from result data', $failures, $passes );
datamachine_bundle_runner_assert( 'https://github.com/Extra-Chill/example-agent/pull/461' === ( $recorded_merge['data']['static_site_agent']['pr_url'] ?? null ), 'tool recorder maps PR URL from result data', $failures, $passes );
datamachine_bundle_runner_assert( 'issue-460-design-direction' === ( $recorded_merge['data']['static_site_agent']['slug'] ?? null ), 'tool recorder applies strip_prefix transforms', $failures, $passes );

$GLOBALS['datamachine_bundle_runner_engine_data_merges'] = array();
\DataMachine\Engine\AI\datamachine_record_tool_results_to_engine_data(
	array(
		'job_id'         => 78,
		'tool_recorders' => array(
			array(
				'tool'   => 'github_pull_request_publish',
				'record' => array(
					'engine_key' => 'static_site_agent',
					'fields'     => array(
						'branch' => 'data.head',
						'pr_url' => 'data.html_url',
					),
				),
			),
		),
	),
	array(
		array(
			'tool_name'        => 'github_pull_request_publish',
			'result'           => array( 'success' => true ),
			'tool_result_data' => array(
				'data' => array(
					'head'     => 'static/issue-468-design-direction',
					'html_url' => 'https://github.com/Extra-Chill/example-agent/pull/470',
				),
			),
		),
	)
);
$recorded_envelope_merge = $GLOBALS['datamachine_bundle_runner_engine_data_merges'][0] ?? array();
datamachine_bundle_runner_assert( 'static/issue-468-design-direction' === ( $recorded_envelope_merge['data']['static_site_agent']['branch'] ?? null ), 'tool recorder maps branch from wrapped tool_result_data', $failures, $passes );
datamachine_bundle_runner_assert( 'https://github.com/Extra-Chill/example-agent/pull/470' === ( $recorded_envelope_merge['data']['static_site_agent']['pr_url'] ?? null ), 'tool recorder maps PR URL from wrapped tool_result_data', $failures, $passes );

$GLOBALS['datamachine_bundle_runner_engine_data_merges'] = array();
\DataMachine\Engine\AI\datamachine_record_tool_results_to_engine_data(
	array(
		'job_id'         => 79,
		'tool_recorders' => array(
			array(
				'tool'   => 'create_github_issue',
				'record' => array(
					'engine_key' => 'design_agent',
					'fields'     => array(
						'issue_number' => 'tool_result_data.issue_number',
						'issue_url'    => 'tool_result_data.issue_url',
					),
				),
			),
		),
	),
	array(
		array(
			'tool_name' => 'create_github_issue',
			'result'    => array(
				'success'  => true,
				'metadata' => array(
					'tool_result_data' => array(
						'issue_number' => 516,
						'issue_url'    => 'https://github.com/Extra-Chill/example-agent/issues/516',
					),
				),
			),
		),
	)
);
$recorded_direct_tool_merge = $GLOBALS['datamachine_bundle_runner_engine_data_merges'][0] ?? array();
datamachine_bundle_runner_assert( 516 === ( $recorded_direct_tool_merge['data']['design_agent']['issue_number'] ?? null ), 'tool recorder maps issue number from direct tool metadata payload', $failures, $passes );
datamachine_bundle_runner_assert( 'https://github.com/Extra-Chill/example-agent/issues/516' === ( $recorded_direct_tool_merge['data']['design_agent']['issue_url'] ?? null ), 'tool recorder maps issue URL from direct tool metadata payload', $failures, $passes );

$GLOBALS['datamachine_bundle_runner_engine_data_merges'] = array();
\DataMachine\Engine\AI\datamachine_record_tool_results_to_engine_data(
	array(
		'job_id'         => 80,
		'tool_recorders' => array(
			array(
				'tool'   => 'create_github_issue',
				'record' => array(
					'engine_key' => 'design_agent',
					'fields'     => array(
						'issue_number' => 'tool_result_data.issue_number',
						'issue_url'    => 'tool_result_data.issue_url',
					),
				),
			),
		),
	),
	array(
		array(
			'tool_name' => 'create_github_issue',
			'result'    => array(
				'success' => true,
				'result'  => array(
					'kind'         => 'issue',
					'issue_number' => 522,
					'issue_url'    => 'https://github.com/Extra-Chill/example-agent/issues/522',
				),
			),
		),
	)
);
$recorded_direct_envelope_merge = $GLOBALS['datamachine_bundle_runner_engine_data_merges'][0] ?? array();
datamachine_bundle_runner_assert( 522 === ( $recorded_direct_envelope_merge['data']['design_agent']['issue_number'] ?? null ), 'tool recorder maps issue number from direct result envelope payload', $failures, $passes );
datamachine_bundle_runner_assert( 'https://github.com/Extra-Chill/example-agent/issues/522' === ( $recorded_direct_envelope_merge['data']['design_agent']['issue_url'] ?? null ), 'tool recorder maps issue URL from direct result envelope payload', $failures, $passes );

echo "\n[3d] Runner applies run-scoped flow step patches\n";
$workflow_from_bundle_flow = $runner_reflection->getMethod( 'workflow_from_bundle_flow' );
$patched_workflow          = $workflow_from_bundle_flow->invoke(
	$runner_instance,
	array(
		'steps' => array(
			array(
				'flow_step_id'     => 'fetch-issue',
				'pipeline_step_id' => 'fetch-pipeline',
				'step_position'    => 0,
				'step_type'        => 'fetch',
				'handler_configs'  => array(
					'github' => array(
						'repo'   => 'Extra-Chill/example-agent',
						'labels' => 'status:idea-ready',
					),
				),
			),
			array(
				'flow_step_id'     => 'publish-static-site',
				'pipeline_step_id' => 'publish-pipeline',
				'step_position'    => 1,
				'step_type'        => 'ai',
			),
		),
	),
	array(
		'steps' => array(
			array(
				'step_position' => 0,
				'step_type'     => 'fetch',
				'step_config'   => array( 'queue_mode' => 'static' ),
			),
			array(
				'step_position' => 1,
				'step_type'     => 'ai',
				'step_config'   => array( 'queue_mode' => 'static' ),
			),
		),
	),
	array(
		'flow_step_patches' => array(
			array(
				'step_type' => 'fetch',
				'merge'     => array(
					'handler_configs' => array(
						'github' => array( 'issue_number' => 429 ),
					),
				),
			),
		),
		'tool_recorders'     => array(
			array(
				'tool'   => 'github_pull_request_publish',
				'record' => array(
					'engine_key' => 'static_site_agent',
					'fields'     => array( 'branch' => 'data.head' ),
				),
			),
		),
	)
);
datamachine_bundle_runner_assert( 429 === ( $patched_workflow['steps'][0]['handler_configs']['github']['issue_number'] ?? null ), 'flow step patch merges nested handler config', $failures, $passes );
datamachine_bundle_runner_assert( 'Extra-Chill/example-agent' === ( $patched_workflow['steps'][0]['handler_configs']['github']['repo'] ?? null ), 'flow step patch preserves existing handler config', $failures, $passes );
datamachine_bundle_runner_assert( 'github_pull_request_publish' === ( $patched_workflow['steps'][1]['tool_recorders'][0]['tool'] ?? null ), 'run-scoped tool recorders are projected onto AI workflow steps', $failures, $passes );

$ephemeral_configs = DataMachine\Core\Steps\WorkflowConfigFactory::buildEphemeralConfigs( $patched_workflow );
datamachine_bundle_runner_assert( 'github_pull_request_publish' === ( $ephemeral_configs['flow_config']['ephemeral_step_1']['tool_recorders'][0]['tool'] ?? null ), 'ephemeral flow config preserves AI tool recorders', $failures, $passes );
datamachine_bundle_runner_assert( 'github_pull_request_publish' === ( $ephemeral_configs['pipeline_config']['ephemeral_pipeline_1']['tool_recorders'][0]['tool'] ?? null ), 'ephemeral pipeline config preserves AI tool recorders', $failures, $passes );

echo "\n[3e] Runner treats no-item terminal states as JobStatus success\n";
$response_method = $runner_reflection->getMethod( 'response' );
$no_item_response = $response_method->invoke(
	$runner_instance,
	array(
		'success'    => true,
		'job_status' => 'completed_no_items',
	),
	array( 'wait_for_completion' => true )
);
datamachine_bundle_runner_assert( 'completed_no_items' === ( $no_item_response['status'] ?? null ), 'no-item terminal status is preserved', $failures, $passes );
datamachine_bundle_runner_assert( true === ( $no_item_response['success'] ?? null ), 'no-item terminal status is a successful bundle run', $failures, $passes );
datamachine_bundle_runner_assert( true === ( $no_item_response['completion_outcome']['success'] ?? null ), 'no-item completion outcome is successful', $failures, $passes );

$incomplete_wait_response = $response_method->invoke(
	$runner_instance,
	array(
		'success'             => true,
		'wait_for_completion' => true,
		'wait_result'         => array(
			'success'           => false,
			'terminal_state'    => null,
			'remaining_actions' => 1,
		),
		'job_status'          => 'processing',
	),
	array( 'wait_for_completion' => true )
);
datamachine_bundle_runner_assert( false === ( $incomplete_wait_response['success'] ?? null ), 'non-terminal wait result fails the bundle run', $failures, $passes );
datamachine_bundle_runner_assert( 'failed' === ( $incomplete_wait_response['status'] ?? null ), 'non-terminal wait result exposes failed status', $failures, $passes );
datamachine_bundle_runner_assert( 'wait_incomplete' === ( $incomplete_wait_response['error_type'] ?? null ), 'non-terminal wait result exposes typed error', $failures, $passes );

echo "\n[3f] Completed bundle runs enforce required semantic outputs\n";
$missing_artifact_response = $response_method->invoke(
	$runner_instance,
	array(
		'success'     => true,
		'job_status'  => 'completed',
		'engine_data' => array(
			'outputs' => array(
				'concept_packet' => array(),
			),
		),
	),
	array(
		'wait_for_completion' => true,
		'required_artifacts'   => array( 'concept_packet' ),
	)
);
datamachine_bundle_runner_assert( false === ( $missing_artifact_response['success'] ?? null ), 'empty required artifact output fails a completed run', $failures, $passes );
datamachine_bundle_runner_assert( array( 'concept_packet' ) === ( $missing_artifact_response['output_diagnostics']['missing_required'] ?? null ), 'missing required artifact is identified', $failures, $passes );
datamachine_bundle_runner_assert( false !== strpos( (string) ( $missing_artifact_response['error'] ?? '' ), 'concept_packet' ), 'failure message names missing artifact output', $failures, $passes );

$present_artifact_response = $response_method->invoke(
	$runner_instance,
	array(
		'success'     => true,
		'job_status'  => 'completed',
		'engine_data' => array(
			'outputs' => array(
				'concept_packet' => array( 'title' => 'Concept Packet' ),
			),
		),
	),
	array(
		'wait_for_completion' => true,
		'required_artifacts'   => array( 'concept_packet' ),
	)
);
datamachine_bundle_runner_assert( true === ( $present_artifact_response['success'] ?? null ), 'non-empty required artifact output keeps completed run successful', $failures, $passes );

$scheduled_required_response = $response_method->invoke(
	$runner_instance,
	array(
		'success' => true,
	),
	array(
		'required_outputs' => array( 'future_result_url' ),
	)
);
datamachine_bundle_runner_assert( true === ( $scheduled_required_response['success'] ?? null ), 'required outputs do not fail an async scheduled run before completion', $failures, $passes );
datamachine_bundle_runner_assert( array( 'future_result_url' ) === ( $scheduled_required_response['output_diagnostics']['missing_outputs'] ?? null ), 'async scheduled run still exposes missing output diagnostics', $failures, $passes );

echo "\n[4] WP-CLI wraps the same ability through the shared runner path\n";
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
	"'ability_tools'       => array("                                           => 'run-agent-bundle schema accepts runtime ability tools',
	'apply_runtime_model_config'                                              => 'runner projects provider/model into initial data',
	'apply_runtime_ability_tools'                                             => 'runner projects ability tools into initial data',
	'apply_runtime_host_tool_policy'                                         => 'runner projects host tool policy into initial data',
	"\$job_snapshot['ability_tools']"                                         => 'runner stamps job-scoped ability tool declarations',
	"\$job_snapshot['host_tool_policy']"                                     => 'runner stamps job-scoped host tool policy',
	"\$job_snapshot['default_provider']"                                      => 'runner stamps job-scoped default provider',
	"\$job_snapshot['default_model']"                                         => 'runner stamps job-scoped default model',
	"\$mode_models['pipeline']"                                               => 'runner stamps pipeline mode model config',
	"'ability_tools'        => is_array( \$job_snapshot['ability_tools'] ?? null )" => 'AI step passes job-scoped ability tools to resolver',
	"'host_tool_policy'     => is_array( \$job_snapshot['host_tool_policy'] ?? null )" => 'AI step passes job-scoped host tool policy to resolver',
	'resolveModelFromJobSnapshot'                                             => 'AI step reads run-scoped model config',
	'resolveModelForExecutionModes( $agent_id, $execution_modes, $job_snapshot )' => 'AI validation uses job-scoped model config',
) as $needle => $label ) {
	datamachine_bundle_runner_contains( $abilities . $runner . $ai_step, $needle, $label, $failures, $passes );
}

echo "\n[6] Runner stays host-neutral\n";
foreach ( array( 'DataMachine\\Core\\Database\\Agents', 'DataMachine\\Core\\Database\\Flows', 'DataMachine\\Core\\Database\\Pipelines' ) as $runner_dependency ) {
	datamachine_bundle_runner_assert( false === strpos( $runner, $runner_dependency ), "runtime runner uses shared ability access instead of {$runner_dependency}", $failures, $passes );
}

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " bundle runner assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAgent bundle runner contract smoke passed ({$passes} assertions).\n";
