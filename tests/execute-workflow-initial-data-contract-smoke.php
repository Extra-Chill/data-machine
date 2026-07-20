<?php
/**
 * Pure-PHP smoke test for ExecuteWorkflowAbility initial_data contracts.
 *
 * Run with: php tests/execute-workflow-initial-data-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $title ), '-' ) );
	}
}

/**
 * Mirror of ExecuteWorkflowAbility::execute()'s create_args parent link.
 */
function build_execute_workflow_create_args_for_contract_test( array $initial_data ): array {
	$args = array(
		'pipeline_id' => 'direct',
		'flow_id'     => 'direct',
		'source'      => 'chat',
		'label'       => 'Chat Workflow',
	);

	$initial_parent_job_id = (int) ( $initial_data['parent_job_id'] ?? 0 );
	if ( $initial_parent_job_id > 0 ) {
		$args['parent_job_id'] = $initial_parent_job_id;
	}

	return $args;
}

/**
 * Mirror of ExecuteWorkflowAbility::execute()'s engine data assembly.
 */
function build_execute_workflow_engine_data_for_contract_test( int $job_id, array $configs, array $initial_data, array $ownership ): array {
	$engine_data                    = $initial_data;
	$engine_data['flow_config']     = $configs['flow_config'];
	$engine_data['pipeline_config'] = $configs['pipeline_config'];

	$caller_snapshot = is_array( $engine_data['job'] ?? null ) ? $engine_data['job'] : array();
	$job_snapshot            = $caller_snapshot;
	$job_snapshot['user_id'] = (int) $ownership['user_id'];
	$job_snapshot['job_id']  = $job_id;
	if ( ! empty( $ownership['agent_id'] ) ) {
		$job_snapshot['agent_id']   = (int) $ownership['agent_id'];
		$job_snapshot['agent_slug'] = sanitize_title( (string) $ownership['agent_slug'] );
	} else {
		unset( $job_snapshot['agent_id'], $job_snapshot['agent_slug'] );
	}
	$engine_data['job'] = $job_snapshot;

	return $engine_data;
}

function datamachine_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected === $actual ) {
		echo "  [PASS] {$msg}\n";
		return;
	}

	echo "  [FAIL] {$msg}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
	exit( 1 );
}

echo "=== execute-workflow-initial-data-contract-smoke ===\n";

$generated_flow_config = array(
	'ephemeral_step_0' => array(
		'flow_step_id'     => 'ephemeral_step_0',
		'pipeline_step_id' => 'ephemeral_pipeline_0',
		'step_type'        => 'system_task',
		'execution_order'  => 0,
	),
);

$generated_pipeline_config = array(
	'ephemeral_pipeline_0' => array(
		'system_prompt' => 'generated system prompt',
	),
);

$configs = array(
	'flow_config'     => $generated_flow_config,
	'pipeline_config' => $generated_pipeline_config,
);

echo "\n[1] engine-owned configs win over initial_data\n";
$initial_data = array(
	'flow_config'     => array( 'poisoned_flow' => array( 'execution_order' => 999 ) ),
	'pipeline_config' => array( 'poisoned_pipeline' => array( 'system_prompt' => 'caller override' ) ),
	'parent_job_id'   => 64,
	'agent_id'        => 2,
	'agent_slug'      => 'Wayward Son',
	'user_id'         => 1,
	'job'             => array(
		'user_id' => 1,
	),
	'task_type'       => 'wiki_maintain_article',
);

$engine_data = build_execute_workflow_engine_data_for_contract_test(
	100,
	$configs,
	$initial_data,
	array(
		'user_id'    => 1,
		'agent_id'   => 2,
		'agent_slug' => 'Wayward Son',
	)
);
datamachine_assert_same( $generated_flow_config, $engine_data['flow_config'], 'generated flow_config is preserved' );
datamachine_assert_same( false, isset( $engine_data['flow_config']['poisoned_flow'] ), 'caller flow_config is not retained' );
datamachine_assert_same( $generated_pipeline_config, $engine_data['pipeline_config'], 'generated pipeline_config is preserved' );
datamachine_assert_same( false, isset( $engine_data['pipeline_config']['poisoned_pipeline'] ), 'caller pipeline_config is not retained' );
datamachine_assert_same( 'wiki_maintain_article', $engine_data['task_type'], 'non-reserved initial_data remains available' );

echo "\n[2] parent_job_id still routes to create_job args\n";
$create_args = build_execute_workflow_create_args_for_contract_test( $initial_data );
datamachine_assert_same( 64, $create_args['parent_job_id'] ?? null, 'parent_job_id preserved for indexed job linkage' );

echo "\n[3] flat identity fields still route into the job snapshot\n";
datamachine_assert_same( 2, $engine_data['agent_id'], 'flat agent_id remains in engine_data' );
datamachine_assert_same( 'Wayward Son', $engine_data['agent_slug'], 'flat agent_slug remains in engine_data' );
datamachine_assert_same( 1, $engine_data['user_id'], 'flat user_id remains in engine_data' );
datamachine_assert_same( 100, $engine_data['job']['job_id'], 'authoritative job_id wins in job snapshot' );
datamachine_assert_same( 2, $engine_data['job']['agent_id'], 'flat agent_id fills missing job.agent_id' );
datamachine_assert_same( 'wayward-son', $engine_data['job']['agent_slug'], 'flat agent_slug fills missing job.agent_slug' );
datamachine_assert_same( 1, $engine_data['job']['user_id'], 'job.user_id preserved from caller snapshot' );

echo "\n[4] authoritative ownership overrides caller job snapshot identity\n";
$initial_data_with_snapshot = array(
	'agent_id' => 2,
	'user_id'  => 1,
	'job'      => array(
		'agent_id' => 9,
		'user_id'  => 8,
		'job_id'   => 777,
	),
);
$engine_data_with_snapshot = build_execute_workflow_engine_data_for_contract_test(
	101,
	$configs,
	$initial_data_with_snapshot,
	array(
		'user_id'    => 42,
		'agent_id'   => 3,
		'agent_slug' => 'Effective Agent',
	)
);
datamachine_assert_same( 3, $engine_data_with_snapshot['job']['agent_id'], 'effective agent overrides caller job.agent_id' );
datamachine_assert_same( 42, $engine_data_with_snapshot['job']['user_id'], 'acting user overrides caller job.user_id' );
datamachine_assert_same( 101, $engine_data_with_snapshot['job']['job_id'], 'engine job_id remains authoritative over caller job.job_id' );

echo "\n[5] direct workflow packet handoff is engine-backed\n";
$schedule_next_step_source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Engine/ScheduleNextStepAbility.php' ) ?: '';
$execute_step_source       = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Engine/ExecuteStepAbility.php' ) ?: '';
datamachine_assert_same( true, str_contains( $schedule_next_step_source, "'direct' === \$raw_flow_id" ), 'direct schedule path is detected before file storage' );
datamachine_assert_same( true, str_contains( $schedule_next_step_source, 'direct_step_data_packets' ), 'direct schedule path stores data packets on engine data' );
datamachine_assert_same( true, str_contains( $execute_step_source, "empty( \$dataPackets ) && 'direct' === \$flow_id" ), 'direct execute path falls back when file storage has no packets' );
datamachine_assert_same( true, str_contains( $execute_step_source, 'direct_step_data_packets' ), 'direct execute path reloads engine-backed packets' );

echo "\n=== execute-workflow-initial-data-contract-smoke: ALL PASS ===\n";
