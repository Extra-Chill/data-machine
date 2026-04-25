<?php
/**
 * Pure-PHP smoke test for SystemTask agent-context propagation.
 *
 * Run with: php tests/system-task-agent-context-smoke.php
 *
 * Verifies the engine_data shapes produced by:
 *
 * 1. TaskScheduler::schedule() — the initial_data it passes to
 *    datamachine/execute-workflow includes agent_id/user_id at top
 *    level AND a 'job' snapshot mirroring RunFlowAbility's shape.
 * 2. ExecuteWorkflowAbility::execute() — the engine_data['job']
 *    snapshot it builds populates job_id, user_id, and agent_id from
 *    initial_data.
 * 3. SystemTaskStep::executeStep() — the child engine_data carries
 *    parent's agent_id/user_id both as flat keys and under 'job'.
 * 4. The recurring schedule fan-out — per_agent => true emits one
 *    schedule call per active agent with that agent's identity in
 *    $context.
 *
 * The full live paths require WordPress + Action Scheduler + DB, so
 * each case isolates the array-construction logic in a harness that
 * mirrors the production code path byte-for-byte.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, $gmt = 0 ): string {
		return '2026-04-25 00:00:00';
	}
}

// ─── Harness functions mirroring production paths ───────────────────

/**
 * Mirror of TaskScheduler::schedule() initial_data construction.
 */
function build_task_scheduler_initial_data(
	string $task_type,
	array $params,
	array $context,
	int $parent_job_id
): array {
	$context_user_id  = (int) ( $context['user_id'] ?? 0 );
	$context_agent_id = (int) ( $context['agent_id'] ?? 0 );

	$job_snapshot = array(
		'user_id' => $context_user_id,
	);
	if ( $context_agent_id > 0 ) {
		$job_snapshot['agent_id'] = $context_agent_id;
	}

	return array(
		'task_type'     => $task_type,
		'task_params'   => $params,
		'task_context'  => $context,
		'parent_job_id' => $parent_job_id,
		'user_id'       => $context_user_id,
		'agent_id'      => $context_agent_id,
		'job'           => $job_snapshot,
	);
}

/**
 * Mirror of ExecuteWorkflowAbility::execute() engine_data['job'] shape.
 */
function build_engine_data_job_snapshot( int $job_id, array $initial_data ): array {
	$engine_data = array(
		'flow_config'     => array(),
		'pipeline_config' => array(),
	);

	if ( ! empty( $initial_data ) && is_array( $initial_data ) ) {
		$engine_data = array_merge( $engine_data, $initial_data );
	}

	$job_snapshot = is_array( $engine_data['job'] ?? null ) ? $engine_data['job'] : array();
	$job_snapshot = array_merge(
		array(
			'job_id'  => $job_id,
			'user_id' => (int) ( $initial_data['user_id'] ?? 0 ),
		),
		$job_snapshot,
		array( 'job_id' => $job_id )
	);
	if ( ! empty( $initial_data['agent_id'] ) && empty( $job_snapshot['agent_id'] ) ) {
		$job_snapshot['agent_id'] = (int) $initial_data['agent_id'];
	}
	$engine_data['job'] = $job_snapshot;

	return $engine_data;
}

/**
 * Mirror of SystemTaskStep::executeStep() child engine_data shape.
 */
function build_system_task_child_engine_data(
	int $parent_job_id,
	array $parent_engine_data,
	int $child_job_id,
	string $task_type,
	array $task_params
): array {
	$parent_job_snapshot = $parent_engine_data['job'] ?? array();
	$parent_agent_id     = (int) ( $parent_job_snapshot['agent_id'] ?? 0 );
	$parent_user_id      = (int) ( $parent_job_snapshot['user_id'] ?? 0 );

	$child_engine_data = array_merge( $task_params, array(
		'task_type'        => $task_type,
		'pipeline_job_id'  => $parent_job_id,
		'pipeline_step_id' => 'step_1',
		'scheduled_at'     => current_time( 'mysql' ),
	) );

	if ( $parent_agent_id > 0 ) {
		$child_engine_data['agent_id'] = $parent_agent_id;
	}
	if ( $parent_user_id > 0 ) {
		$child_engine_data['user_id'] = $parent_user_id;
	}
	$child_job_snapshot = array(
		'job_id'        => $child_job_id,
		'user_id'       => $parent_user_id,
		'parent_job_id' => $parent_job_id,
	);
	if ( $parent_agent_id > 0 ) {
		$child_job_snapshot['agent_id'] = $parent_agent_id;
	}
	$child_engine_data['job'] = $child_job_snapshot;

	return $child_engine_data;
}

/**
 * Mirror of SystemAgentServiceProvider per-agent fan-out logic.
 */
function fan_out_per_agent_schedule( array $params, array $agents ): array {
	$calls = array();
	if ( empty( $agents ) ) {
		$calls[] = array(
			'params'  => $params,
			'context' => array(),
		);
		return $calls;
	}

	foreach ( $agents as $agent ) {
		$agent_id = (int) ( $agent['agent_id'] ?? 0 );
		$owner_id = (int) ( $agent['owner_id'] ?? 0 );

		if ( $agent_id <= 0 ) {
			continue;
		}

		$agent_params             = $params;
		$agent_params['agent_id'] = $agent_id;
		$agent_params['user_id']  = $owner_id;

		$calls[] = array(
			'params'  => $agent_params,
			'context' => array(
				'agent_id' => $agent_id,
				'user_id'  => $owner_id,
			),
		);
	}

	return $calls;
}

// ─── Tiny assertion helpers ─────────────────────────────────────────

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $cond ) use ( &$failures, &$total ): void {
	$total++;
	if ( $cond ) {
		echo "  [PASS] {$label}\n";
	} else {
		$failures++;
		echo "  [FAIL] {$label}\n";
	}
};

// ─── Test cases ─────────────────────────────────────────────────────

echo "\n[1] TaskScheduler initial_data with agent context\n";
$initial = build_task_scheduler_initial_data(
	'daily_memory_generation',
	array( 'date' => '2026-04-25' ),
	array( 'agent_id' => 2, 'user_id' => 1 ),
	0
);
$assert( 'task_type set', 'daily_memory_generation' === $initial['task_type'] );
$assert( 'flat agent_id present', 2 === $initial['agent_id'] );
$assert( 'flat user_id present', 1 === $initial['user_id'] );
$assert( 'job snapshot present', is_array( $initial['job'] ) );
$assert( 'job.agent_id present', 2 === $initial['job']['agent_id'] );
$assert( 'job.user_id present', 1 === $initial['job']['user_id'] );

echo "\n[2] TaskScheduler initial_data without agent context (back-compat)\n";
$initial = build_task_scheduler_initial_data( 'image_optimization', array(), array(), 0 );
$assert( 'flat agent_id is 0', 0 === $initial['agent_id'] );
$assert( 'flat user_id is 0', 0 === $initial['user_id'] );
$assert( 'job snapshot still present', is_array( $initial['job'] ) );
$assert( 'job.agent_id absent (no agent set)', ! isset( $initial['job']['agent_id'] ) );
$assert( 'job.user_id is 0', 0 === $initial['job']['user_id'] );

echo "\n[3] ExecuteWorkflowAbility engine_data['job'] populated from initial_data\n";
$initial   = build_task_scheduler_initial_data(
	'daily_memory_generation',
	array( 'date' => '2026-04-25' ),
	array( 'agent_id' => 2, 'user_id' => 1 ),
	0
);
$engine    = build_engine_data_job_snapshot( 100, $initial );
$assert( 'engine_data.job exists', is_array( $engine['job'] ) );
$assert( 'engine_data.job.job_id set', 100 === $engine['job']['job_id'] );
$assert( 'engine_data.job.agent_id carried through', 2 === $engine['job']['agent_id'] );
$assert( 'engine_data.job.user_id carried through', 1 === $engine['job']['user_id'] );

echo "\n[4] ExecuteWorkflowAbility engine_data['job'] without agent context\n";
$initial = build_task_scheduler_initial_data( 'image_optimization', array(), array(), 0 );
$engine  = build_engine_data_job_snapshot( 200, $initial );
$assert( 'engine_data.job exists', is_array( $engine['job'] ) );
$assert( 'engine_data.job.job_id set', 200 === $engine['job']['job_id'] );
$assert( 'engine_data.job.agent_id absent', ! isset( $engine['job']['agent_id'] ) );
$assert( 'engine_data.job.user_id is 0', 0 === $engine['job']['user_id'] );

echo "\n[5] SystemTaskStep child engine_data carries parent agent_id\n";
$parent_engine = array(
	'job' => array(
		'job_id'   => 500,
		'agent_id' => 2,
		'user_id'  => 1,
	),
);
$child = build_system_task_child_engine_data( 500, $parent_engine, 501, 'alt_text_generation', array( 'attachment_id' => 42 ) );
$assert( 'child task_type set', 'alt_text_generation' === $child['task_type'] );
$assert( 'child has flat agent_id from parent', 2 === $child['agent_id'] );
$assert( 'child has flat user_id from parent', 1 === $child['user_id'] );
$assert( 'child has job snapshot', is_array( $child['job'] ) );
$assert( 'child.job.job_id is child id', 501 === $child['job']['job_id'] );
$assert( 'child.job.parent_job_id linked', 500 === $child['job']['parent_job_id'] );
$assert( 'child.job.agent_id from parent', 2 === $child['job']['agent_id'] );
$assert( 'task params preserved', 42 === $child['attachment_id'] );

echo "\n[6] SystemTaskStep child without agent context (legacy flow)\n";
$parent_engine = array( 'job' => array( 'job_id' => 600, 'user_id' => 0 ) );
$child         = build_system_task_child_engine_data( 600, $parent_engine, 601, 'image_optimization', array() );
$assert( 'child has no flat agent_id', ! isset( $child['agent_id'] ) );
$assert( 'child has no flat user_id', ! isset( $child['user_id'] ) );
$assert( 'child.job.agent_id absent', ! isset( $child['job']['agent_id'] ) );
$assert( 'child.job.user_id is 0', 0 === $child['job']['user_id'] );

echo "\n[7] Recurring schedule per_agent fan-out\n";
$agents = array(
	array( 'agent_id' => 1, 'owner_id' => 1 ),
	array( 'agent_id' => 2, 'owner_id' => 1 ),
	array( 'agent_id' => 3, 'owner_id' => 2 ),
);
$calls = fan_out_per_agent_schedule( array( 'date' => '2026-04-25' ), $agents );
$assert( 'one call per active agent', 3 === count( $calls ) );
$assert( 'first call carries agent 1', 1 === $calls[0]['context']['agent_id'] );
$assert( 'second call carries agent 2', 2 === $calls[1]['context']['agent_id'] );
$assert( 'third call carries agent 3 with owner 2', 2 === $calls[2]['context']['user_id'] );
$assert( 'agent_id surfaces in params', 2 === $calls[1]['params']['agent_id'] );
$assert( 'date param preserved', '2026-04-25' === $calls[1]['params']['date'] );

echo "\n[8] Recurring schedule per_agent fan-out with no agents (back-compat)\n";
$calls = fan_out_per_agent_schedule( array( 'date' => '2026-04-25' ), array() );
$assert( 'falls back to single call', 1 === count( $calls ) );
$assert( 'fallback call has no agent context', empty( $calls[0]['context'] ) );

echo "\n[9] Recurring schedule per_agent fan-out skips invalid agents\n";
$agents = array(
	array( 'agent_id' => 0, 'owner_id' => 1 ),
	array( 'agent_id' => 5, 'owner_id' => 1 ),
);
$calls = fan_out_per_agent_schedule( array(), $agents );
$assert( 'invalid agent_id skipped', 1 === count( $calls ) );
$assert( 'valid agent fired', 5 === $calls[0]['context']['agent_id'] );

echo "\n";
if ( $failures > 0 ) {
	echo "=== system-task-agent-context-smoke: {$failures}/{$total} FAILED ===\n";
	exit( 1 );
}
echo "=== system-task-agent-context-smoke: ALL PASS ({$total} assertions) ===\n";
