<?php
/**
 * Pure-PHP smoke test for the SystemTask passthrough contract (#1297).
 *
 * Run with: php tests/systemtask-passthrough-smoke.php
 *
 * Pre-#1297 the SystemTaskStep::execute_pipeline_step() method had a
 * hardcoded `if ( 'agent_ping' === $task_type )` block that injected
 * pipeline-context fields and queue_mode into the child engine_data
 * for one specific task type. That block:
 *
 *   - Coupled SystemTaskStep to a single task's needs.
 *   - Required paired edits across two unrelated files when a queueable
 *     system task's contract changed (the trigger for filing #1297 was
 *     the queue_enabled → queue_mode rename in PR #1296).
 *   - Made it impossible for new tasks to opt into the same context
 *     without editing the engine.
 *
 * The fix promotes the contract into two declarative methods on the
 * SystemTask base class:
 *
 *   - needsPipelineContext(): bool — opt into the pipeline-execution
 *     bundle (flow_id, pipeline_id, flow_step_id, data_packets,
 *     engine_data, job_id).
 *
 *   - getFlowStepConfigPassthrough(): array — list of flow_step_config
 *     keys to copy into engine_data so executeTask() reads them from
 *     $params directly.
 *
 * Default implementations return "no extra passthrough" so
 * InternalLinkingTask, AltTextTask, MetaDescriptionTask, etc. remain
 * unchanged. AgentPingTask overrides both to declare its needs.
 *
 * This smoke validates:
 *   1. Base class defaults.
 *   2. AgentPingTask overrides return the right shapes.
 *   3. The dead `if ('agent_ping' === $task_type)` block is gone from
 *      SystemTaskStep source.
 *   4. SystemTaskStep calls the new declarative methods (grep-level).
 *   5. Resolution simulation: AgentPingTask's contract produces the
 *      expected engine_data shape; a default-passthrough task does NOT
 *      get pipeline context or flow_step_config keys.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

/**
 * Assert helper.
 *
 * @param string $name      Test case name.
 * @param bool   $condition Pass/fail.
 */
function assert_passthrough( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

// ---------------------------------------------------------------
// Test fixtures: minimal SystemTask subclasses.
// ---------------------------------------------------------------

abstract class FixtureSystemTask {

	abstract public function getTaskType(): string;

	/**
	 * Default — no pipeline context bundle. Mirrors the production
	 * SystemTask::needsPipelineContext() default. Concrete tasks like
	 * InternalLinkingTask, AltTextTask, MetaDescriptionTask inherit
	 * this and don't override.
	 */
	public function needsPipelineContext(): bool {
		return false;
	}

	/**
	 * Default — no flow_step_config keys copied. Mirrors the production
	 * SystemTask::getFlowStepConfigPassthrough() default.
	 */
	public function getFlowStepConfigPassthrough(): array {
		return array();
	}
}

/**
 * Stand-in for AgentPingTask. The two override methods below mirror the
 * production class's overrides exactly — if the production AgentPingTask
 * contract drifts (e.g. someone forgets to declare a new field as
 * passthrough), the SECTION 5 grep below catches the file-level drift.
 */
final class FixtureAgentPingTask extends FixtureSystemTask {

	public function getTaskType(): string {
		return 'agent_ping';
	}

	public function needsPipelineContext(): bool {
		return true;
	}

	public function getFlowStepConfigPassthrough(): array {
		return array( 'queue_mode' );
	}
}

/**
 * Stand-in for any task that doesn't need pipeline context — e.g.
 * InternalLinkingTask. Inherits the base defaults; should NOT receive
 * the pipeline-context bundle from SystemTaskStep's resolver.
 */
final class FixtureInternalLinkingTask extends FixtureSystemTask {
	public function getTaskType(): string {
		return 'internal_linking';
	}
}

// ---------------------------------------------------------------
// Resolution simulator: mirrors SystemTaskStep's passthrough section.
// ---------------------------------------------------------------

/**
 * Mirror SystemTaskStep::execute_pipeline_step()'s passthrough section
 * (lines that resolve needsPipelineContext + getFlowStepConfigPassthrough
 * post-#1297). Returns the resulting child_engine_data delta — only the
 * keys the passthrough mechanism would have added.
 *
 * NOT a byte-mirror of every line in SystemTaskStep — just the
 * passthrough resolver. The full step also does universal merge,
 * agent identity propagation, etc. that aren't part of #1297's
 * contract.
 *
 * @param FixtureSystemTask $task             Task instance.
 * @param array             $job_context      `Engine::getJobContext()` shape.
 * @param array             $engine_all       `Engine::all()` shape.
 * @param array             $data_packets     The step's incoming packets.
 * @param array             $flow_step_config Step's flow_step_config row.
 * @param string            $flow_step_id     Step ID.
 * @param int               $job_id           Parent job ID.
 * @return array Engine data delta added by the passthrough resolver.
 */
function resolve_passthrough_delta(
	FixtureSystemTask $task,
	array $job_context,
	array $engine_all,
	array $data_packets,
	array $flow_step_config,
	string $flow_step_id,
	int $job_id
): array {
	$delta = array();

	if ( $task->needsPipelineContext() ) {
		$delta['flow_id']      = $job_context['flow_id'] ?? null;
		$delta['flow_step_id'] = $flow_step_id;
		$delta['data_packets'] = $data_packets;
		$delta['engine_data']  = $engine_all;
		$delta['job_id']       = $job_id;
		$delta['pipeline_id']  = $job_context['pipeline_id'] ?? null;
	}

	foreach ( $task->getFlowStepConfigPassthrough() as $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			continue;
		}
		if ( array_key_exists( $key, $flow_step_config ) ) {
			$delta[ $key ] = $flow_step_config[ $key ];
		}
	}

	return $delta;
}

// ---------------------------------------------------------------

echo "=== SystemTask Passthrough Smoke (#1297) ===\n";

// SECTION 1: Base-class defaults are "no extra passthrough".
echo "\n[base:1] Base class defaults are inert\n";
$default_task = new FixtureInternalLinkingTask();
assert_passthrough(
	'needsPipelineContext() defaults to false',
	false === $default_task->needsPipelineContext()
);
assert_passthrough(
	'getFlowStepConfigPassthrough() defaults to empty array',
	array() === $default_task->getFlowStepConfigPassthrough()
);

// SECTION 2: AgentPingTask overrides declare its full contract.
echo "\n[agent_ping:1] AgentPingTask declares pipeline context + queue_mode\n";
$agent_ping = new FixtureAgentPingTask();
assert_passthrough(
	'needsPipelineContext() returns true',
	true === $agent_ping->needsPipelineContext()
);
assert_passthrough(
	'getFlowStepConfigPassthrough() returns ["queue_mode"]',
	array( 'queue_mode' ) === $agent_ping->getFlowStepConfigPassthrough()
);

// SECTION 3: Resolution for AgentPingTask produces the full bundle.
echo "\n[resolve:1] AgentPingTask gets pipeline-context bundle + queue_mode\n";
$delta = resolve_passthrough_delta(
	$agent_ping,
	array( 'flow_id' => 42, 'pipeline_id' => 7 ),
	array( 'post_id' => 123, 'job' => array( 'job_id' => 99 ) ),
	array( array( 'title' => 'pkt' ) ),
	array(
		'queue_mode'   => 'drain',
		'irrelevant'   => 'should-not-leak',
		'handler_slug' => 'agent_ping',
	),
	'42_step_uuid_3',
	99
);
assert_passthrough(
	'flow_id pulled from job_context',
	42 === $delta['flow_id']
);
assert_passthrough(
	'pipeline_id pulled from job_context',
	7 === $delta['pipeline_id']
);
assert_passthrough(
	'flow_step_id is the step ID',
	'42_step_uuid_3' === $delta['flow_step_id']
);
assert_passthrough(
	'data_packets passed through verbatim',
	array( array( 'title' => 'pkt' ) ) === $delta['data_packets']
);
assert_passthrough(
	'engine_data is the engine snapshot',
	array( 'post_id' => 123, 'job' => array( 'job_id' => 99 ) ) === $delta['engine_data']
);
assert_passthrough(
	'job_id is the parent job',
	99 === $delta['job_id']
);
assert_passthrough(
	'queue_mode copied from flow_step_config',
	'drain' === $delta['queue_mode']
);
assert_passthrough(
	'irrelevant flow_step_config key is NOT copied',
	! array_key_exists( 'irrelevant', $delta )
);
assert_passthrough(
	'handler_slug NOT copied (not in passthrough list)',
	! array_key_exists( 'handler_slug', $delta )
);

// SECTION 4: Resolution for default task produces empty delta.
echo "\n[resolve:2] InternalLinkingTask gets nothing extra (no pipeline context, no fsc keys)\n";
$delta_default = resolve_passthrough_delta(
	$default_task,
	array( 'flow_id' => 42, 'pipeline_id' => 7 ),
	array( 'post_id' => 123 ),
	array( array( 'title' => 'pkt' ) ),
	array( 'queue_mode' => 'drain' ),  // present, but not in passthrough list
	'42_step_uuid_4',
	99
);
assert_passthrough(
	'no pipeline-context keys leak when needsPipelineContext() is false',
	! array_key_exists( 'flow_id', $delta_default )
		&& ! array_key_exists( 'pipeline_id', $delta_default )
		&& ! array_key_exists( 'flow_step_id', $delta_default )
		&& ! array_key_exists( 'data_packets', $delta_default )
		&& ! array_key_exists( 'engine_data', $delta_default )
		&& ! array_key_exists( 'job_id', $delta_default )
);
assert_passthrough(
	'no queue_mode leak when getFlowStepConfigPassthrough() is empty',
	! array_key_exists( 'queue_mode', $delta_default )
);
assert_passthrough(
	'delta is completely empty for default-passthrough task',
	array() === $delta_default
);

// SECTION 5: Edge cases — defensive behaviour against bad input.
echo "\n[resolve:3] Bad passthrough declarations don't crash the resolver\n";

final class FixtureBadPassthroughTask extends FixtureSystemTask {
	public function getTaskType(): string {
		return 'bad';
	}
	public function getFlowStepConfigPassthrough(): array {
		// Mix of valid + invalid keys; resolver should silently skip invalid.
		return array( 'queue_mode', '', 0, null, 'unknown_key' );
	}
}

$bad   = new FixtureBadPassthroughTask();
$delta_bad = resolve_passthrough_delta(
	$bad,
	array(),
	array(),
	array(),
	array( 'queue_mode' => 'static' ),
	'step',
	1
);
assert_passthrough(
	'valid keys still resolved when bad keys are mixed in',
	'static' === ( $delta_bad['queue_mode'] ?? null )
);
assert_passthrough(
	'unknown_key not in flow_step_config is skipped silently',
	! array_key_exists( 'unknown_key', $delta_bad )
);

// SECTION 6: Production-source grep contracts.
echo "\n[source:1] Per-task `if` block is gone from SystemTaskStep\n";
$step_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/inc/Core/Steps/SystemTask/SystemTaskStep.php'
);
assert_passthrough(
	'no `if ( \'agent_ping\' === $task_type )` block remains',
	false === strpos( $step_src, "'agent_ping' === \$task_type" )
);
assert_passthrough(
	'SystemTaskStep calls needsPipelineContext()',
	false !== strpos( $step_src, 'needsPipelineContext()' )
);
assert_passthrough(
	'SystemTaskStep calls getFlowStepConfigPassthrough()',
	false !== strpos( $step_src, 'getFlowStepConfigPassthrough()' )
);

echo "\n[source:2] SystemTask base declares both passthrough methods\n";
$base_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/inc/Engine/AI/System/Tasks/SystemTask.php'
);
assert_passthrough(
	'needsPipelineContext() declared on base class',
	false !== strpos( $base_src, 'public function needsPipelineContext(): bool' )
);
assert_passthrough(
	'getFlowStepConfigPassthrough() declared on base class',
	false !== strpos( $base_src, 'public function getFlowStepConfigPassthrough(): array' )
);
assert_passthrough(
	'both base methods default to inert (false / empty array)',
	false !== strpos( $base_src, "return false;\n\t}\n\n\t/**\n\t * Declare flow_step_config keys" )
		&& false !== strpos( $base_src, "return array();\n\t}\n\n\t// ─── Job lifecycle helpers" )
);

echo "\n[source:3] AgentPingTask overrides match the test fixture\n";
$ping_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/inc/Engine/AI/System/Tasks/AgentPingTask.php'
);
assert_passthrough(
	'AgentPingTask overrides needsPipelineContext()',
	false !== strpos( $ping_src, 'public function needsPipelineContext(): bool' )
);
assert_passthrough(
	'AgentPingTask returns true from needsPipelineContext()',
	false !== strpos( $ping_src, "needsPipelineContext(): bool {\n\t\treturn true;" )
);
assert_passthrough(
	'AgentPingTask overrides getFlowStepConfigPassthrough()',
	false !== strpos( $ping_src, 'public function getFlowStepConfigPassthrough(): array' )
);
assert_passthrough(
	"AgentPingTask declares 'queue_mode' as a flow_step_config passthrough",
	false !== strpos(
		$ping_src,
		"return array( 'queue_mode' );"
	)
);

echo "\n[source:4] No other SystemTask subclass needs to opt in today\n";
// Walk every concrete task file; if any declares the passthrough methods,
// the smoke either needs updating OR the new task should be reviewed.
$task_dir = dirname( __DIR__ ) . '/inc/Engine/AI/System/Tasks';
$entries  = glob( $task_dir . '/*.php' );
$opted_in = array();
foreach ( $entries as $file ) {
	$base = basename( $file );
	if ( 'SystemTask.php' === $base || 'AgentPingTask.php' === $base ) {
		continue;
	}
	$src = (string) file_get_contents( $file );
	if (
		false !== strpos( $src, 'function needsPipelineContext' )
		|| false !== strpos( $src, 'function getFlowStepConfigPassthrough' )
	) {
		$opted_in[] = $base;
	}
}
assert_passthrough(
	'AgentPingTask is the only task that opts into passthrough today',
	array() === $opted_in
);

echo "\n";
if ( 0 === $failed ) {
	echo "=== systemtask-passthrough-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== systemtask-passthrough-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
