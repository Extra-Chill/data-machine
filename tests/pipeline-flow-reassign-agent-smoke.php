<?php
/**
 * Smoke test for pipeline/flow agent_id reassign methods and CLI wiring.
 *
 * Validates that:
 * - Pipelines::update_pipeline() accepts agent_id in its whitelist
 * - Flows::update_flow() accepts agent_id in its whitelist
 * - New repo methods exist with correct signatures
 * - CLI command classes have the new reassign methods
 * - UpdatePipelineAbility and UpdateFlowAbility accept agent_id in schema
 * - DuplicateFlowAbility propagates agent_id from source flow
 *
 * Run with: php tests/pipeline-flow-reassign-agent-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

function smoke_assert( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
}

echo "\n=== Pipeline/Flow agent_id reassign smoke tests ===\n\n";

// ── 1. Pipelines repo methods ──────────────────────────────────────────

echo "--- Pipelines repo ---\n";

$pipelines_class = 'DataMachine\\Core\\Database\\Pipelines\\Pipelines';

// Check class is autoloadable (won't instantiate since no DB).
$pipelines_file = __DIR__ . '/../inc/Core/Database/Pipelines/Pipelines.php';
$pipelines_src  = file_get_contents( $pipelines_file );

// update_pipeline accepts agent_id
smoke_assert(
	str_contains( $pipelines_src, "array_key_exists( 'agent_id', \$pipeline_data )" ),
	'Pipelines::update_pipeline() has agent_id whitelist entry',
	$failures,
	$passes
);

// reassign_agent_id method exists
smoke_assert(
	str_contains( $pipelines_src, 'public function reassign_agent_id( ?int $from_agent_id, int $to_agent_id ): int' ),
	'Pipelines::reassign_agent_id() method exists with correct signature',
	$failures,
	$passes
);

// count_by_agent_id method exists
smoke_assert(
	str_contains( $pipelines_src, 'public function count_by_agent_id( ?int $agent_id ): int' ),
	'Pipelines::count_by_agent_id() method exists with correct signature',
	$failures,
	$passes
);

// reassign_agent_id handles NULL source
smoke_assert(
	str_contains( $pipelines_src, 'WHERE agent_id IS NULL' ),
	'Pipelines::reassign_agent_id() handles NULL source (WHERE agent_id IS NULL)',
	$failures,
	$passes
);

// ── 2. Flows repo methods ──────────────────────────────────────────────

echo "\n--- Flows repo ---\n";

$flows_file = __DIR__ . '/../inc/Core/Database/Flows/Flows.php';
$flows_src  = file_get_contents( $flows_file );

// update_flow accepts agent_id
smoke_assert(
	str_contains( $flows_src, "array_key_exists( 'agent_id', \$flow_data )" ),
	'Flows::update_flow() has agent_id whitelist entry',
	$failures,
	$passes
);

// reassign_agent_id method exists
smoke_assert(
	str_contains( $flows_src, 'public function reassign_agent_id( ?int $from_agent_id, int $to_agent_id ): int' ),
	'Flows::reassign_agent_id() method exists with correct signature',
	$failures,
	$passes
);

// count_by_agent_id method exists
smoke_assert(
	str_contains( $flows_src, 'public function count_by_agent_id( ?int $agent_id ): int' ),
	'Flows::count_by_agent_id() method exists with correct signature',
	$failures,
	$passes
);

// reassign_agent_id_for_pipeline method exists
smoke_assert(
	str_contains( $flows_src, 'public function reassign_agent_id_for_pipeline( int $pipeline_id, ?int $from_agent_id, int $to_agent_id ): int' ),
	'Flows::reassign_agent_id_for_pipeline() method exists with correct signature',
	$failures,
	$passes
);

// ── 3. PipelinesCommand CLI wiring ─────────────────────────────────────

echo "\n--- PipelinesCommand CLI ---\n";

$pipelines_cmd_file = __DIR__ . '/../inc/Cli/Commands/PipelinesCommand.php';
$pipelines_cmd_src  = file_get_contents( $pipelines_cmd_file );

// reassign dispatch in __invoke
smoke_assert(
	str_contains( $pipelines_cmd_src, "'reassign' === \$args[0]" ),
	'PipelinesCommand dispatches reassign subcommand',
	$failures,
	$passes
);

// reassignPipelines method exists
smoke_assert(
	str_contains( $pipelines_cmd_src, 'private function reassignPipelines(' ),
	'PipelinesCommand::reassignPipelines() method exists',
	$failures,
	$passes
);

// --agent wired into updatePipeline
smoke_assert(
	str_contains( $pipelines_cmd_src, "AgentResolver::resolve( \$assoc_args )" )
	&& str_contains( $pipelines_cmd_src, "update_pipeline( \$pipeline_id, array( 'agent_id' => \$new_agent_id ) )" ),
	'PipelinesCommand::updatePipeline() handles --agent flag',
	$failures,
	$passes
);

// --cascade-flows support
smoke_assert(
	str_contains( $pipelines_cmd_src, 'cascade-flows' ),
	'PipelinesCommand supports --cascade-flows flag',
	$failures,
	$passes
);

// --from-agent accepts raw integer (not via AgentResolver)
smoke_assert(
	str_contains( $pipelines_cmd_src, "absint( \$assoc_args['from-agent'] )" ),
	'PipelinesCommand --from-agent uses raw absint (not AgentResolver)',
	$failures,
	$passes
);

// --to-agent goes through AgentResolver
smoke_assert(
	str_contains( $pipelines_cmd_src, "AgentResolver::resolve( array( 'agent' => \$assoc_args['to-agent'] ) )" ),
	'PipelinesCommand --to-agent uses AgentResolver',
	$failures,
	$passes
);

// --dry-run support
smoke_assert(
	str_contains( $pipelines_cmd_src, 'Dry-run complete' ),
	'PipelinesCommand supports --dry-run',
	$failures,
	$passes
);

// ── 4. FlowsCommand CLI wiring ────────────────────────────────────────

echo "\n--- FlowsCommand CLI ---\n";

$flows_cmd_file = __DIR__ . '/../inc/Cli/Commands/Flows/FlowsCommand.php';
$flows_cmd_src  = file_get_contents( $flows_cmd_file );

// reassign dispatch in __invoke
smoke_assert(
	str_contains( $flows_cmd_src, "'reassign' === \$args[0]" ),
	'FlowsCommand dispatches reassign subcommand',
	$failures,
	$passes
);

// reassignFlows method exists
smoke_assert(
	str_contains( $flows_cmd_src, 'private function reassignFlows(' ),
	'FlowsCommand::reassignFlows() method exists',
	$failures,
	$passes
);

// --agent wired into updateFlow
smoke_assert(
	str_contains( $flows_cmd_src, "\$has_agent      = isset( \$assoc_args['agent'] )" ),
	'FlowsCommand::updateFlow() checks --agent flag',
	$failures,
	$passes
);

// ── 5. Ability layer ──────────────────────────────────────────────────

echo "\n--- Ability layer ---\n";

$update_pipeline_ability_file = __DIR__ . '/../inc/Abilities/Pipeline/UpdatePipelineAbility.php';
$update_pipeline_ability_src  = file_get_contents( $update_pipeline_ability_file );

smoke_assert(
	str_contains( $update_pipeline_ability_src, "'agent_id'" )
	&& str_contains( $update_pipeline_ability_src, "'type'        => 'integer'" ),
	'UpdatePipelineAbility schema includes agent_id (integer)',
	$failures,
	$passes
);

$update_flow_ability_file = __DIR__ . '/../inc/Abilities/Flow/UpdateFlowAbility.php';
$update_flow_ability_src  = file_get_contents( $update_flow_ability_file );

smoke_assert(
	str_contains( $update_flow_ability_src, "'agent_id'" )
	&& str_contains( $update_flow_ability_src, "update_flow(\n\t\t\t\t\$flow_id,\n\t\t\t\tarray( 'agent_id' => absint( \$agent_id ) )" ),
	'UpdateFlowAbility handles agent_id in execute()',
	$failures,
	$passes
);

// ── 6. DuplicateFlowAbility bug fix ───────────────────────────────────

echo "\n--- DuplicateFlowAbility bug fix ---\n";

$dup_flow_file = __DIR__ . '/../inc/Abilities/Flow/DuplicateFlowAbility.php';
$dup_flow_src  = file_get_contents( $dup_flow_file );

smoke_assert(
	str_contains( $dup_flow_src, "\$agent_id = \$input['agent_id'] ?? ( \$source_flow['agent_id'] ?? null )" ),
	'DuplicateFlowAbility propagates agent_id from source flow',
	$failures,
	$passes
);

// ── 7. PostTracking docblock update ───────────────────────────────────

echo "\n--- PostTracking docblock ---\n";

$post_tracking_file = __DIR__ . '/../inc/Core/WordPress/PostTracking.php';
$post_tracking_src  = file_get_contents( $post_tracking_file );

smoke_assert(
	str_contains( $post_tracking_src, 'can be changed via update_flow or CLI reassign' ),
	'PostTracking docblock updated to reflect mutable agent_id',
	$failures,
	$passes
);

smoke_assert(
	! str_contains( $post_tracking_src, 'is not mutated by update_flow' ),
	'PostTracking no longer claims agent_id is immutable',
	$failures,
	$passes
);

// ── Summary ───────────────────────────────────────────────────────────

echo "\n" . str_repeat( '─', 60 ) . "\n";
$total = $passes + count( $failures );
echo sprintf( "Results: %d/%d passed", $passes, $total );

if ( count( $failures ) > 0 ) {
	echo sprintf( ", %d FAILED:\n", count( $failures ) );
	foreach ( $failures as $f ) {
		echo "  ✗ {$f}\n";
	}
	exit( 1 );
} else {
	echo " — all green ✓\n";
	exit( 0 );
}
