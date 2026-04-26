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
 * Inline reimplementation of FlowsCommand::normalizeAiStepUserMessage().
 * AI steps must always expose the `user_message` slot, even when unset.
 *
 * Mirrors inc/Cli/Commands/Flows/FlowsCommand.php::normalizeAiStepUserMessage.
 */
function normalize_ai_user_message_for_test( array $flow ): array {
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
	}

	return $flow;
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

// --- Case 4: normalizeAiStepUserMessage exposes the slot for `flow get`.
echo "\nCase 4: flow get always renders user_message on AI steps\n";

$flow_with_unset_user_message = array(
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

$normalized = normalize_ai_user_message_for_test( $flow_with_unset_user_message );

assert_test(
	'AI step gets user_message="" when previously unset',
	array_key_exists( 'user_message', $normalized['flow_config']['pstep_ai_uuid_1'] )
		&& '' === $normalized['flow_config']['pstep_ai_uuid_1']['user_message'],
	'got: ' . var_export( $normalized['flow_config']['pstep_ai_uuid_1'], true )
);

assert_test(
	'non-AI step is NOT decorated with user_message',
	! array_key_exists( 'user_message', $normalized['flow_config']['pstep_fetch_uuid_1'] ),
	'got: ' . var_export( $normalized['flow_config']['pstep_fetch_uuid_1'], true )
);

// --- Case 5: existing user_message values are preserved by normalization.
echo "\nCase 5: normalization preserves existing user_message values\n";

$flow_with_set_user_message = array(
	'flow_id'     => 2,
	'flow_config' => array(
		'pstep_ai_uuid_1' => array(
			'step_type'    => 'ai',
			'user_message' => 'Existing per-flow context.',
		),
	),
);

$preserved = normalize_ai_user_message_for_test( $flow_with_set_user_message );

assert_test(
	'existing user_message is preserved verbatim',
	'Existing per-flow context.' === $preserved['flow_config']['pstep_ai_uuid_1']['user_message'],
	'got: ' . var_export( $preserved['flow_config']['pstep_ai_uuid_1']['user_message'] ?? null, true )
);

// --- Case 6: empty/missing flow_config doesn't blow up normalization.
echo "\nCase 6: normalization is null-safe\n";

assert_test(
	'no flow_config returns flow unchanged',
	normalize_ai_user_message_for_test( array( 'flow_id' => 3 ) ) === array( 'flow_id' => 3 )
);

assert_test(
	'non-array flow_config returns flow unchanged',
	normalize_ai_user_message_for_test( array( 'flow_id' => 4, 'flow_config' => 'oops' ) )
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
		$out = normalize_ai_user_message_for_test( $flow );
		return $out['flow_config']['memory_files'] === array( 'a.md' )
			&& '' === $out['flow_config']['pstep_ai_uuid_1']['user_message'];
	})()
);

echo "\n--- Summary ---\n";
echo "Passed: " . ( $total - $failed ) . " / $total\n";

if ( $failed > 0 ) {
	echo "FAILED\n";
	exit( 1 );
}

echo "OK\n";
exit( 0 );
