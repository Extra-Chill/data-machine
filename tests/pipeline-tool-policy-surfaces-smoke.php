<?php
/**
 * Pure-PHP smoke test for the first #1443 tool-policy surface cleanup slice.
 *
 * Run with: php tests/pipeline-tool-policy-surfaces-smoke.php
 *
 * This pins two source-boundary contracts that do not need a JS runner:
 *
 * - Flow step cards display the AI user message from prompt_queue[0].prompt,
 *   matching the post-#1291 runtime shape. The `user_message` input remains
 *   allowed only as the public write-side shim to UpdateFlowStepAbility.
 * - Pipeline CLI --config no longer maps dead provider/model keys into
 *   UpdatePipelineStepAbility. The ability rejects them and REST already
 *   stopped exposing them.
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

/**
 * Assert helper.
 *
 * @param string $name      Test case name.
 * @param bool   $condition Pass/fail.
 */
function assert_tool_policy_surface( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

/**
 * Read a repository file.
 *
 * @param string $relative Path relative to the repository root.
 * @return string File contents.
 */
function read_tool_policy_surface_file( string $relative ): string {
	$path = dirname( __DIR__ ) . '/' . ltrim( $relative, '/' );
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Missing source file: {$path}\n" );
		exit( 2 );
	}
	return (string) file_get_contents( $path );
}

echo "=== Pipeline Tool Policy Surfaces Smoke (#1443) ===\n";

// ---------------------------------------------------------------
// SECTION 1: FlowStepCard.jsx reads the runtime prompt_queue shape.
// ---------------------------------------------------------------

echo "\n[FlowStepCard.jsx] AI prompt display reads prompt_queue head\n";
$flow_step_card = read_tool_policy_surface_file(
	'inc/Core/Admin/Pages/Pipelines/assets/react/components/flows/FlowStepCard.jsx'
);

assert_tool_policy_surface(
	'AI currentPrompt reads promptQueue[0].prompt',
	false !== strpos( $flow_step_card, 'return promptQueue[ 0 ]?.prompt || \'\';' )
);
assert_tool_policy_surface(
	'AI display path does not read legacy flowStepConfig.user_message',
	false === strpos( $flow_step_card, 'flowStepConfig.user_message' )
);
assert_tool_policy_surface(
	'write-side shim still sends user_message input to the ability',
	false !== strpos( $flow_step_card, 'config = { user_message: value };' )
);
assert_tool_policy_surface(
	'write-side shim comment documents prompt_queue server storage',
	false !== strpos( $flow_step_card, 'one-item static prompt_queue entry server-side' )
);

$queueable_prompt_field = read_tool_policy_surface_file(
	'inc/Core/Admin/Pages/Pipelines/assets/react/components/flows/QueueablePromptField.jsx'
);
assert_tool_policy_surface(
	'QueueablePromptField prop docs name prompt_queue head, not user_message',
	false !== strpos( $queueable_prompt_field, 'from handler_config or prompt_queue head' )
		&& false === strpos( $queueable_prompt_field, 'from handler_config or user_message' )
);

// ---------------------------------------------------------------
// SECTION 2: PipelinesCommand.php no longer maps dead provider/model.
// ---------------------------------------------------------------

echo "\n[PipelinesCommand.php] CLI --config maps only live update fields\n";
$pipelines_command = read_tool_policy_surface_file( 'inc/Cli/Commands/PipelinesCommand.php' );
preg_match( '/\$field_map\s*=\s*array\((.*?)\);/s', $pipelines_command, $field_map_match );
$field_map = $field_map_match[1] ?? '';

assert_tool_policy_surface(
	'pipeline step --config field_map block was found',
	'' !== $field_map
);
assert_tool_policy_surface(
	'field_map keeps system_prompt',
	false !== strpos( $field_map, "'system_prompt'  => 'system_prompt'" )
);
assert_tool_policy_surface(
	'field_map keeps disabled_tools',
	false !== strpos( $field_map, "'disabled_tools' => 'disabled_tools'" )
);
assert_tool_policy_surface(
	'field_map no longer maps provider',
	false === strpos( $field_map, "'provider'" )
);
assert_tool_policy_surface(
	'field_map no longer maps model',
	false === strpos( $field_map, "'model'" )
);

echo "\n";
if ( 0 === $failed ) {
	echo "=== pipeline-tool-policy-surfaces-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}

echo "=== pipeline-tool-policy-surfaces-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
