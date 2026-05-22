<?php
/**
 * Pure-PHP smoke test for pipeline-step scoped system_prompt queues (#1295).
 *
 * Run with: php tests/pipeline-system-prompt-queue-smoke.php
 *
 * Validates the 1.0 field-level queueability slice:
 * - QueueAbility declares pipeline scoped system_prompt queue constants.
 * - QueueAbility exposes consumeFromPipelineQueueSlot().
 * - PipelineSystemPromptDirective resolves system_prompt via that scoped queue.
 * - Existing static system_prompt fallback remains when no queue is configured.
 * - Drain / loop / static semantics match the existing flow-step queue model.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

function assert_pipeline_queue( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

$root_dir      = dirname( __DIR__ );
$qa_src        = (string) file_get_contents( $root_dir . '/inc/Abilities/Flow/QueueAbility.php' );
$directive_src = (string) file_get_contents( $root_dir . '/inc/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php' );

echo "=== Pipeline System Prompt Queue Smoke (#1295) ===\n";

echo "\n[ability:1] QueueAbility declares pipeline system_prompt queue storage\n";
assert_pipeline_queue(
	'SLOT_SYSTEM_PROMPT_QUEUE constant exists',
	false !== strpos( $qa_src, "const SLOT_SYSTEM_PROMPT_QUEUE = 'system_prompt_queue';" )
);
assert_pipeline_queue(
	'MODE_SYSTEM_PROMPT_QUEUE constant exists',
	false !== strpos( $qa_src, "const MODE_SYSTEM_PROMPT_QUEUE = 'system_prompt_queue_mode';" )
);
assert_pipeline_queue(
	'consumeFromPipelineQueueSlot() is public static',
	(bool) preg_match(
		'/public static function consumeFromPipelineQueueSlot\(\s*int \$pipeline_id,\s*string \$pipeline_step_id,\s*string \$slot,\s*string \$queue_mode/s',
		$qa_src
	)
);

echo "\n[directive:1] PipelineSystemPromptDirective reads the scoped queue\n";
assert_pipeline_queue(
	'directive imports QueueAbility',
	false !== strpos( $directive_src, 'use DataMachine\\Abilities\\Flow\\QueueAbility;' )
);
assert_pipeline_queue(
	'directive resolves system_prompt through resolveSystemPrompt()',
	false !== strpos( $directive_src, 'self::resolveSystemPrompt(' )
);
assert_pipeline_queue(
	'directive calls consumeFromPipelineQueueSlot()',
	false !== strpos( $directive_src, 'QueueAbility::consumeFromPipelineQueueSlot(' )
);
assert_pipeline_queue(
	'directive uses system_prompt fallback when no queue is configured',
	false !== strpos( $directive_src, "return (string) ( \$step_config['system_prompt'] ?? '' );" )
);

/**
 * Mirror the production pipeline queue semantics in memory.
 *
 * @param array  $pipeline_config Pipeline config, mutated for drain / loop.
 * @param string $pipeline_step_id Pipeline step ID.
 * @param string $slot Queue slot.
 * @param string $queue_mode drain|loop|static.
 * @return array{entry: ?array, mutated: bool, remaining_count: int}
 */
function simulate_pipeline_queue_consume( array &$pipeline_config, string $pipeline_step_id, string $slot, string $queue_mode ): array {
	if ( ! isset( $pipeline_config[ $pipeline_step_id ] ) || ! is_array( $pipeline_config[ $pipeline_step_id ] ) ) {
		return array( 'entry' => null, 'mutated' => false, 'remaining_count' => 0 );
	}

	$queue = $pipeline_config[ $pipeline_step_id ][ $slot ] ?? array();
	if ( empty( $queue ) || ! is_array( $queue ) ) {
		return array( 'entry' => null, 'mutated' => false, 'remaining_count' => 0 );
	}

	if ( 'static' === $queue_mode ) {
		return array( 'entry' => $queue[0], 'mutated' => false, 'remaining_count' => count( $queue ) );
	}

	$entry = array_shift( $queue );
	if ( 'loop' === $queue_mode ) {
		$queue[] = $entry;
	}
	$pipeline_config[ $pipeline_step_id ][ $slot ] = $queue;

	return array( 'entry' => $entry, 'mutated' => true, 'remaining_count' => count( $queue ) );
}

echo "\n[semantics:1] pipeline queues preserve drain / loop / static semantics\n";
$pc = array(
	'ai_step' => array(
		'system_prompt_queue' => array(
			array( 'prompt' => 'Variant A', 'added_at' => 't0' ),
			array( 'prompt' => 'Variant B', 'added_at' => 't1' ),
		),
	),
);
$result = simulate_pipeline_queue_consume( $pc, 'ai_step', 'system_prompt_queue', 'static' );
assert_pipeline_queue( 'static peeks Variant A', 'Variant A' === ( $result['entry']['prompt'] ?? null ) );
assert_pipeline_queue( 'static does not mutate queue', false === $result['mutated'] && 2 === count( $pc['ai_step']['system_prompt_queue'] ) );

$pc = array(
	'ai_step' => array(
		'system_prompt_queue' => array(
			array( 'prompt' => 'Variant A', 'added_at' => 't0' ),
			array( 'prompt' => 'Variant B', 'added_at' => 't1' ),
		),
	),
);
$result = simulate_pipeline_queue_consume( $pc, 'ai_step', 'system_prompt_queue', 'drain' );
assert_pipeline_queue( 'drain pops Variant A', 'Variant A' === ( $result['entry']['prompt'] ?? null ) );
assert_pipeline_queue( 'drain leaves Variant B as head', 1 === count( $pc['ai_step']['system_prompt_queue'] ) && 'Variant B' === $pc['ai_step']['system_prompt_queue'][0]['prompt'] );

$pc = array(
	'ai_step' => array(
		'system_prompt_queue' => array(
			array( 'prompt' => 'Variant A', 'added_at' => 't0' ),
			array( 'prompt' => 'Variant B', 'added_at' => 't1' ),
		),
	),
);
$result = simulate_pipeline_queue_consume( $pc, 'ai_step', 'system_prompt_queue', 'loop' );
assert_pipeline_queue( 'loop returns Variant A', 'Variant A' === ( $result['entry']['prompt'] ?? null ) );
assert_pipeline_queue( 'loop rotates Variant A to tail', 2 === count( $pc['ai_step']['system_prompt_queue'] ) && 'Variant B' === $pc['ai_step']['system_prompt_queue'][0]['prompt'] && 'Variant A' === $pc['ai_step']['system_prompt_queue'][1]['prompt'] );

echo "\n";
if ( 0 === $failed ) {
	echo "=== pipeline-system-prompt-queue-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== pipeline-system-prompt-queue-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
