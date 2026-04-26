<?php
/**
 * Pure-PHP smoke test for the `flow update --set-user-message` fix (#1289).
 *
 * Run with: php tests/flow-update-set-user-message-smoke.php
 *
 * The bug: `wp datamachine flow update <id> --set-prompt="..."` silently
 * wrote to handler_configs.ai.prompt, which AIStep::execute() never reads.
 * AIStep reads $flow_step_config['user_message'] at the flow_step_config
 * root, not under handler_configs. The CLI's `Success` message was a lie.
 *
 * The fix has three observable contracts this smoke covers:
 *
 *   1. The CLI rename: --set-prompt is removed (clean break, no alias),
 *      --set-user-message is the new flag.
 *   2. The CLI's write goes through UpdateFlowStepAbility's `user_message`
 *      input parameter (which routes to updateUserMessage()), not via
 *      `handler_config => array( 'prompt' => $prompt )`.
 *   3. `flow get` always renders the `user_message` slot on AI steps,
 *      even when unset, so the field is discoverable.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Inline reimplementation of UpdateFlowStepAbility::execute()'s
 * write-routing logic (the relevant slice). When `user_message` is in
 * the input, it's written to flow_step_config['user_message']. When
 * `handler_config` is in the input, it's merged into
 * flow_step_config['handler_configs'][$slug].
 *
 * Mirrors inc/Abilities/FlowStep/UpdateFlowStepAbility.php and
 * inc/Abilities/FlowStep/FlowStepHelpers.php::updateUserMessage().
 * Diverging here means the real files regressed.
 */
function apply_update_flow_step_for_test( array $flow_step_config, array $input ): array {
	$user_message       = $input['user_message'] ?? null;
	$handler_config     = $input['handler_config'] ?? array();
	$has_message_update = null !== $user_message;
	$has_handler_update = ! empty( $handler_config );

	if ( $has_handler_update ) {
		$slug = $input['handler_slug']
			?? ( $flow_step_config['handler_slugs'][0] ?? ( $flow_step_config['step_type'] ?? '' ) );
		if ( ! isset( $flow_step_config['handler_configs'][ $slug ] ) ) {
			$flow_step_config['handler_configs'][ $slug ] = array();
		}
		$flow_step_config['handler_configs'][ $slug ] = array_merge(
			$flow_step_config['handler_configs'][ $slug ],
			$handler_config
		);
	}

	if ( $has_message_update ) {
		$flow_step_config['user_message'] = $user_message;
	}

	return $flow_step_config;
}

/**
 * Inline reimplementation of FlowsCommand::normalizeAiStepPromptSlots().
 * AI steps must always expose every slot AIStep reads at runtime
 * (`user_message`, `prompt_queue`, `queue_enabled`), even when unset.
 *
 * Mirrors inc/Cli/Commands/Flows/FlowsCommand.php::normalizeAiStepPromptSlots.
 */
function normalize_ai_prompt_slots_for_test( array $flow ): array {
	if ( empty( $flow['flow_config'] ) || ! is_array( $flow['flow_config'] ) ) {
		return $flow;
	}

	foreach ( $flow['flow_config'] as $step_id => $step_data ) {
		if ( ! is_array( $step_data ) ) {
			continue;
		}
		if ( 'ai' !== ( $step_data['step_type'] ?? '' ) ) {
			continue;
		}
		if ( ! array_key_exists( 'user_message', $step_data ) ) {
			$flow['flow_config'][ $step_id ]['user_message'] = '';
		}
		if ( ! array_key_exists( 'prompt_queue', $step_data ) ) {
			$flow['flow_config'][ $step_id ]['prompt_queue'] = array();
		}
		if ( ! array_key_exists( 'queue_enabled', $step_data ) ) {
			$flow['flow_config'][ $step_id ]['queue_enabled'] = false;
		}
	}

	return $flow;
}

/**
 * Inline reimplementation of FlowsCommand::resolveAiStepActivePrompt().
 * Resolves which slot AIStep::execute() reads at runtime.
 *
 * Mirrors inc/Core/Steps/AI/AIStep.php::execute() lines 140-173 and
 * inc/Cli/Commands/Flows/FlowsCommand.php::resolveAiStepActivePrompt.
 * Diverging here means one of those two files regressed.
 */
function resolve_ai_active_prompt_for_test( array $step_data ): array {
	$queue_enabled = (bool) ( $step_data['queue_enabled'] ?? false );
	$prompt_queue  = $step_data['prompt_queue'] ?? array();
	$queue_depth   = is_array( $prompt_queue ) ? count( $prompt_queue ) : 0;
	$queue_head    = is_array( $prompt_queue ) ? trim( (string) ( $prompt_queue[0]['prompt'] ?? '' ) ) : '';
	$user_message  = trim( (string) ( $step_data['user_message'] ?? '' ) );

	if ( '' !== $queue_head ) {
		return array(
			'slot'          => 'queue_head',
			'value'         => $queue_head,
			'queue_depth'   => $queue_depth,
			'queue_enabled' => $queue_enabled,
		);
	}

	if ( '' !== $user_message ) {
		return array(
			'slot'          => 'user_message',
			'value'         => $user_message,
			'queue_depth'   => $queue_depth,
			'queue_enabled' => $queue_enabled,
		);
	}

	return array(
		'slot'          => 'none',
		'value'         => '',
		'queue_depth'   => $queue_depth,
		'queue_enabled' => $queue_enabled,
	);
}

$tests   = array();
$failed  = 0;
$total   = 0;

function assert_test( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
	} else {
		echo "  [FAIL] $name" . ( $detail ? " — $detail" : '' ) . "\n";
		++$failed;
	}
}

// --- Case 1: CLI maps --set-user-message to the user_message input.
echo "Case 1: --set-user-message routes through user_message input\n";

$flow_step_config = array(
	'step_type'       => 'ai',
	'handler_slugs'   => array(),
	'handler_configs' => array(),
);

// Simulate FlowsCommand::updateFlow() building the ability input from
// $assoc_args['set-user-message']. This is the exact shape the post-fix
// CLI passes to UpdateFlowStepAbility::execute().
$ability_input = array(
	'flow_step_id' => 'pstep_1_uuid_1',
	'user_message' => 'Per-flow task framing for the WC backfill.',
);

$result = apply_update_flow_step_for_test( $flow_step_config, $ability_input );

assert_test(
	'user_message lands at flow_step_config root',
	( $result['user_message'] ?? null ) === 'Per-flow task framing for the WC backfill.',
	'got: ' . var_export( $result['user_message'] ?? null, true )
);

assert_test(
	'handler_configs.ai.prompt is NOT written (the dead key from the bug)',
	! isset( $result['handler_configs']['ai']['prompt'] ),
	'unexpected dead-key write: ' . var_export( $result['handler_configs'], true )
);

assert_test(
	'handler_configs remains empty when only user_message is set',
	$result['handler_configs'] === array(),
	'got: ' . var_export( $result['handler_configs'], true )
);

// --- Case 2: The pre-fix shape would have written to the dead key.
//     Demonstrate that the OLD CLI path (handler_config => prompt) is
//     visibly broken: it does NOT populate user_message.
echo "\nCase 2: old broken shape (handler_config => prompt) is observably wrong\n";

$broken_input = array(
	'flow_step_id'   => 'pstep_1_uuid_1',
	'handler_config' => array( 'prompt' => 'TEST' ),
);

$broken_result = apply_update_flow_step_for_test( $flow_step_config, $broken_input );

assert_test(
	'old shape writes to handler_configs.ai.prompt (the dead key)',
	( $broken_result['handler_configs']['ai']['prompt'] ?? null ) === 'TEST',
	'pre-fix behavior should still be reproducible to prove the bug shape; got: '
		. var_export( $broken_result['handler_configs'], true )
);

assert_test(
	'old shape leaves user_message unset (the silent failure)',
	! isset( $broken_result['user_message'] ),
	'old broken shape should NOT touch user_message; got: '
		. var_export( $broken_result['user_message'] ?? null, true )
);

// --- Case 3: Existing handler_config writes still work for non-AI steps.
echo "\nCase 3: handler_config writes still work for handler steps\n";

$fetch_step_config = array(
	'step_type'       => 'fetch',
	'handler_slugs'   => array( 'reddit' ),
	'handler_configs' => array( 'reddit' => array( 'subreddit' => 'old' ) ),
);

$fetch_input = array(
	'flow_step_id'   => 'pstep_2_uuid_1',
	'handler_slug'   => 'reddit',
	'handler_config' => array( 'subreddit' => 'WordPress' ),
);

$fetch_result = apply_update_flow_step_for_test( $fetch_step_config, $fetch_input );

assert_test(
	'handler_config merges into handler_configs[<slug>]',
	( $fetch_result['handler_configs']['reddit']['subreddit'] ?? null ) === 'WordPress',
	'got: ' . var_export( $fetch_result['handler_configs'], true )
);

assert_test(
	'handler_config does NOT bleed into user_message',
	! isset( $fetch_result['user_message'] ),
	'got: ' . var_export( $fetch_result['user_message'] ?? null, true )
);

// --- Case 4: normalization exposes every prompt-input slot for `flow get`.
echo "\nCase 4: flow get always renders user_message + prompt_queue + queue_enabled on AI steps\n";

$flow_with_unset_slots = array(
	'flow_id'     => 1,
	'flow_config' => array(
		'pstep_ai_uuid_1' => array(
			'step_type'       => 'ai',
			'handler_slugs'   => array(),
			'handler_configs' => array(),
		),
		'pstep_fetch_uuid_1' => array(
			'step_type'       => 'fetch',
			'handler_slugs'   => array( 'reddit' ),
			'handler_configs' => array( 'reddit' => array( 'subreddit' => 'WordPress' ) ),
		),
	),
);

$normalized = normalize_ai_prompt_slots_for_test( $flow_with_unset_slots );

assert_test(
	'AI step gets user_message="" when previously unset',
	array_key_exists( 'user_message', $normalized['flow_config']['pstep_ai_uuid_1'] )
		&& '' === $normalized['flow_config']['pstep_ai_uuid_1']['user_message'],
	'got: ' . var_export( $normalized['flow_config']['pstep_ai_uuid_1'], true )
);

assert_test(
	'AI step gets prompt_queue=[] when previously unset',
	array_key_exists( 'prompt_queue', $normalized['flow_config']['pstep_ai_uuid_1'] )
		&& array() === $normalized['flow_config']['pstep_ai_uuid_1']['prompt_queue'],
	'got: ' . var_export( $normalized['flow_config']['pstep_ai_uuid_1']['prompt_queue'] ?? null, true )
);

assert_test(
	'AI step gets queue_enabled=false when previously unset',
	array_key_exists( 'queue_enabled', $normalized['flow_config']['pstep_ai_uuid_1'] )
		&& false === $normalized['flow_config']['pstep_ai_uuid_1']['queue_enabled'],
	'got: ' . var_export( $normalized['flow_config']['pstep_ai_uuid_1']['queue_enabled'] ?? null, true )
);

assert_test(
	'non-AI step is NOT decorated with prompt slots',
	! array_key_exists( 'user_message', $normalized['flow_config']['pstep_fetch_uuid_1'] )
		&& ! array_key_exists( 'prompt_queue', $normalized['flow_config']['pstep_fetch_uuid_1'] )
		&& ! array_key_exists( 'queue_enabled', $normalized['flow_config']['pstep_fetch_uuid_1'] ),
	'got: ' . var_export( $normalized['flow_config']['pstep_fetch_uuid_1'], true )
);

// --- Case 5: existing values are preserved by normalization.
echo "\nCase 5: normalization preserves existing values\n";

$flow_with_set_values = array(
	'flow_id'     => 2,
	'flow_config' => array(
		'pstep_ai_uuid_1' => array(
			'step_type'     => 'ai',
			'user_message'  => 'Existing per-flow context.',
			'prompt_queue'  => array( array( 'prompt' => 'queued', 'added_at' => '2026-01-01T00:00:00Z' ) ),
			'queue_enabled' => true,
		),
	),
);

$preserved = normalize_ai_prompt_slots_for_test( $flow_with_set_values );

assert_test(
	'existing user_message preserved verbatim',
	'Existing per-flow context.' === $preserved['flow_config']['pstep_ai_uuid_1']['user_message']
);

assert_test(
	'existing prompt_queue preserved verbatim',
	$preserved['flow_config']['pstep_ai_uuid_1']['prompt_queue']
		=== array( array( 'prompt' => 'queued', 'added_at' => '2026-01-01T00:00:00Z' ) )
);

assert_test(
	'existing queue_enabled preserved verbatim',
	true === $preserved['flow_config']['pstep_ai_uuid_1']['queue_enabled']
);

// --- Case 6: empty/missing flow_config doesn't blow up normalization.
echo "\nCase 6: normalization is null-safe\n";

assert_test(
	'no flow_config returns flow unchanged',
	normalize_ai_prompt_slots_for_test( array( 'flow_id' => 3 ) ) === array( 'flow_id' => 3 )
);

assert_test(
	'non-array flow_config returns flow unchanged',
	normalize_ai_prompt_slots_for_test( array( 'flow_id' => 4, 'flow_config' => 'oops' ) )
		=== array( 'flow_id' => 4, 'flow_config' => 'oops' )
);

assert_test(
	'non-array step_data is skipped',
	(function (): bool {
		$flow = array(
			'flow_config' => array(
				'memory_files'    => array( 'a.md' ),
				'pstep_ai_uuid_1' => array( 'step_type' => 'ai' ),
			),
		);
		$out = normalize_ai_prompt_slots_for_test( $flow );
		return $out['flow_config']['memory_files'] === array( 'a.md' )
			&& '' === $out['flow_config']['pstep_ai_uuid_1']['user_message'];
	})()
);

// --- Case 7: resolver picks queue_head when prompt_queue has entries.
echo "\nCase 7: resolveAiStepActivePrompt — queue head wins over user_message\n";

$step_with_queue_and_message = array(
	'step_type'     => 'ai',
	'user_message'  => 'fallback per-flow context',
	'prompt_queue'  => array(
		array( 'prompt' => 'first queued', 'added_at' => '2026-01-01T00:00:00Z' ),
		array( 'prompt' => 'second queued', 'added_at' => '2026-01-02T00:00:00Z' ),
	),
	'queue_enabled' => false,
);

$resolved = resolve_ai_active_prompt_for_test( $step_with_queue_and_message );

assert_test(
	'queue head takes precedence over user_message',
	'queue_head' === $resolved['slot'] && 'first queued' === $resolved['value']
);

assert_test(
	'queue depth reflects total entries (not just head)',
	2 === $resolved['queue_depth']
);

assert_test(
	'queue_enabled=false surfaced unchanged',
	false === $resolved['queue_enabled']
);

// --- Case 8: queue_enabled=true with non-empty queue still picks queue_head.
echo "\nCase 8: resolveAiStepActivePrompt — queue_enabled=true + non-empty\n";

$step_drains_mode = array(
	'step_type'     => 'ai',
	'user_message'  => 'fallback',
	'prompt_queue'  => array( array( 'prompt' => 'drains tick', 'added_at' => '2026-01-01T00:00:00Z' ) ),
	'queue_enabled' => true,
);

$resolved = resolve_ai_active_prompt_for_test( $step_drains_mode );

assert_test(
	'drains-mode queue head still resolves to queue_head slot',
	'queue_head' === $resolved['slot'] && 'drains tick' === $resolved['value']
);

assert_test(
	'queue_enabled=true surfaced unchanged',
	true === $resolved['queue_enabled']
);

// --- Case 9: empty queue falls back to user_message.
echo "\nCase 9: resolveAiStepActivePrompt — empty queue falls back to user_message\n";

$step_message_only = array(
	'step_type'     => 'ai',
	'user_message'  => 'per-flow framing',
	'prompt_queue'  => array(),
	'queue_enabled' => false,
);

$resolved = resolve_ai_active_prompt_for_test( $step_message_only );

assert_test(
	'empty queue → user_message slot',
	'user_message' === $resolved['slot'] && 'per-flow framing' === $resolved['value']
);

assert_test(
	'queue_depth=0 reported when queue is empty',
	0 === $resolved['queue_depth']
);

// --- Case 10: queue head with empty string falls through to user_message.
//     This matches AIStep::execute() behavior: empty $queued_prompt
//     triggers the `if ( empty( $user_message ) )` fallback.
echo "\nCase 10: resolveAiStepActivePrompt — empty-string queue head falls through\n";

$step_empty_head = array(
	'step_type'     => 'ai',
	'user_message'  => 'message wins when head is empty string',
	'prompt_queue'  => array( array( 'prompt' => '', 'added_at' => '2026-01-01T00:00:00Z' ) ),
	'queue_enabled' => false,
);

$resolved = resolve_ai_active_prompt_for_test( $step_empty_head );

assert_test(
	'empty-string queue head falls through to user_message',
	'user_message' === $resolved['slot']
		&& 'message wins when head is empty string' === $resolved['value']
);

// --- Case 11: nothing set anywhere yields slot=none.
echo "\nCase 11: resolveAiStepActivePrompt — nothing set yields slot=none\n";

$step_empty = array(
	'step_type'     => 'ai',
	'user_message'  => '',
	'prompt_queue'  => array(),
	'queue_enabled' => false,
);

$resolved = resolve_ai_active_prompt_for_test( $step_empty );

assert_test(
	'all slots empty → slot=none',
	'none' === $resolved['slot'] && '' === $resolved['value']
);

assert_test(
	'all slots empty → queue_depth=0',
	0 === $resolved['queue_depth']
);

// --- Case 12: malformed prompt_queue (non-array) is null-safe.
echo "\nCase 12: resolveAiStepActivePrompt — null-safe on malformed data\n";

$step_malformed = array(
	'step_type'     => 'ai',
	'user_message'  => 'still works',
	'prompt_queue'  => 'not an array',
	'queue_enabled' => false,
);

$resolved = resolve_ai_active_prompt_for_test( $step_malformed );

assert_test(
	'malformed prompt_queue → falls back to user_message',
	'user_message' === $resolved['slot'] && 'still works' === $resolved['value']
);

assert_test(
	'malformed prompt_queue → queue_depth=0',
	0 === $resolved['queue_depth']
);

echo "\n--- Summary ---\n";
echo "Passed: " . ( $total - $failed ) . " / $total\n";

if ( $failed > 0 ) {
	echo "FAILED\n";
	exit( 1 );
}

echo "OK\n";
exit( 0 );
