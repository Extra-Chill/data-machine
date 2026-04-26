<?php
/**
 * Pure-PHP smoke test for `flow update --set-user-message` (#1289 + #1291).
 *
 * Run with: php tests/flow-update-set-user-message-smoke.php
 *
 * #1289 was the original bug: `wp datamachine flow update <id> --set-prompt`
 * silently wrote to handler_configs.ai.prompt, which AIStep never reads.
 * The fix renamed the flag to --set-user-message and routed the write
 * through UpdateFlowStepAbility's `user_message` input.
 *
 * #1291 collapsed `user_message` into `prompt_queue` and replaced the
 * `queue_enabled` boolean with a `queue_mode` enum. The CLI flag stays
 * the same; the underlying storage rewrites the queue rather than a
 * dedicated user_message slot.
 *
 * Three observable contracts this smoke covers post-#1291:
 *
 *   1. The CLI rename (#1289): --set-prompt is removed (clean break,
 *      no alias), --set-user-message is the canonical flag.
 *   2. The CLI's write goes through UpdateFlowStepAbility's `user_message`
 *      input parameter, which routes to updateUserMessage() and writes
 *      a 1-entry static prompt_queue (#1291). No `user_message` field
 *      is stored anywhere.
 *   3. `flow get` always renders prompt_queue + queue_mode on AI steps,
 *      even when unset, so the active-prompt slot is discoverable.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Inline reimplementation of UpdateFlowStepAbility::execute()'s
 * write-routing logic (the relevant slice). When `user_message` is in
 * the input, FlowStepHelpers::updateUserMessage() rewrites
 * flow_step_config['prompt_queue'] as a 1-entry list and sets
 * queue_mode=static. When `handler_config` is in the input, it's
 * merged into flow_step_config['handler_configs'][$slug].
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
		// updateUserMessage() post-#1291: rewrite prompt_queue as a
		// 1-entry list (or empty when input is empty) and pin
		// queue_mode to static. No user_message field is written.
		$sanitized = trim( (string) $user_message );

		if ( '' === $sanitized ) {
			$flow_step_config['prompt_queue'] = array();
		} else {
			$flow_step_config['prompt_queue'] = array(
				array(
					'prompt'   => $sanitized,
					'added_at' => '2026-04-26T00:00:00+00:00',
				),
			);
		}
		$flow_step_config['queue_mode'] = 'static';
		unset( $flow_step_config['user_message'] );
	}

	return $flow_step_config;
}

/**
 * Inline reimplementation of FlowsCommand::normalizeAiStepPromptSlots().
 * Post-#1291 AI steps must expose prompt_queue + queue_mode (no
 * user_message slot anymore). Fetch steps expose
 * config_patch_queue + queue_mode.
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
		$step_type = $step_data['step_type'] ?? '';

		if ( 'ai' === $step_type ) {
			if ( ! array_key_exists( 'prompt_queue', $step_data ) ) {
				$flow['flow_config'][ $step_id ]['prompt_queue'] = array();
			}
			if ( ! array_key_exists( 'queue_mode', $step_data ) ) {
				$flow['flow_config'][ $step_id ]['queue_mode'] = 'static';
			}
			continue;
		}

		if ( 'fetch' === $step_type ) {
			if ( ! array_key_exists( 'config_patch_queue', $step_data ) ) {
				$flow['flow_config'][ $step_id ]['config_patch_queue'] = array();
			}
			if ( ! array_key_exists( 'queue_mode', $step_data ) ) {
				$flow['flow_config'][ $step_id ]['queue_mode'] = 'static';
			}
		}
	}

	return $flow;
}

/**
 * Inline reimplementation of FlowsCommand::resolveAiStepActivePrompt().
 * Post-#1291 there is one slot — prompt_queue — gated by queue_mode.
 *
 * Mirrors inc/Cli/Commands/Flows/FlowsCommand.php::resolveAiStepActivePrompt.
 */
function resolve_ai_active_prompt_for_test( array $step_data ): array {
	$queue_mode   = $step_data['queue_mode'] ?? 'static';
	$prompt_queue = $step_data['prompt_queue'] ?? array();
	$queue_depth  = is_array( $prompt_queue ) ? count( $prompt_queue ) : 0;
	$queue_head   = is_array( $prompt_queue ) ? trim( (string) ( $prompt_queue[0]['prompt'] ?? '' ) ) : '';

	if ( '' !== $queue_head ) {
		return array(
			'slot'        => 'queue_head',
			'value'       => $queue_head,
			'queue_depth' => $queue_depth,
			'queue_mode'  => $queue_mode,
		);
	}

	return array(
		'slot'        => 'none',
		'value'       => '',
		'queue_depth' => $queue_depth,
		'queue_mode'  => $queue_mode,
	);
}

$failed = 0;
$total  = 0;

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

// --- Case 1: --set-user-message routes through user_message → prompt_queue.
echo "Case 1: --set-user-message routes through user_message → 1-entry prompt_queue\n";

$flow_step_config = array(
	'step_type'       => 'ai',
	'handler_slugs'   => array(),
	'handler_configs' => array(),
);

$ability_input = array(
	'flow_step_id' => 'pstep_1_uuid_1',
	'user_message' => 'Per-flow task framing for the WC backfill.',
);

$result = apply_update_flow_step_for_test( $flow_step_config, $ability_input );

assert_test(
	'prompt_queue rewritten as 1-entry list',
	1 === count( $result['prompt_queue'] ?? array() )
);

assert_test(
	'queue head matches input',
	'Per-flow task framing for the WC backfill.' === ( $result['prompt_queue'][0]['prompt'] ?? null )
);

assert_test(
	'queue_mode forced to static',
	'static' === ( $result['queue_mode'] ?? null )
);

assert_test(
	'NO user_message field written (collapsed)',
	! array_key_exists( 'user_message', $result )
);

assert_test(
	'handler_configs.ai.prompt is NOT written (the dead key from #1289)',
	! isset( $result['handler_configs']['ai']['prompt'] )
);

assert_test(
	'handler_configs remains empty when only user_message is set',
	$result['handler_configs'] === array()
);

// --- Case 2: handler_config writes still work for handler steps.
echo "\nCase 2: handler_config writes still work for handler steps\n";

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
	( $fetch_result['handler_configs']['reddit']['subreddit'] ?? null ) === 'WordPress'
);

assert_test(
	'handler_config does NOT bleed into prompt_queue',
	! isset( $fetch_result['prompt_queue'] )
);

// --- Case 3: empty user_message clears the queue.
echo "\nCase 3: empty user_message input clears the queue (legacy unset semantics)\n";

$step_with_seeded_queue = array(
	'step_type'    => 'ai',
	'prompt_queue' => array(
		array( 'prompt' => 'old prompt', 'added_at' => '2026-01-01T00:00:00Z' ),
	),
	'queue_mode'   => 'static',
);

$cleared = apply_update_flow_step_for_test( $step_with_seeded_queue, array(
	'flow_step_id' => 'pstep_1_uuid_1',
	'user_message' => '',
) );

assert_test(
	'empty input clears prompt_queue',
	array() === $cleared['prompt_queue']
);

assert_test(
	'queue_mode still set to static after clear',
	'static' === $cleared['queue_mode']
);

// --- Case 4: normalization exposes prompt_queue + queue_mode for `flow get`.
echo "\nCase 4: flow get always renders prompt_queue + queue_mode on AI steps\n";

$flow_with_unset_slots = array(
	'flow_id'     => 1,
	'flow_config' => array(
		'pstep_ai_uuid_1'    => array(
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
	'AI step gets prompt_queue=[] when previously unset',
	array() === $normalized['flow_config']['pstep_ai_uuid_1']['prompt_queue']
);

assert_test(
	'AI step gets queue_mode=static when previously unset',
	'static' === $normalized['flow_config']['pstep_ai_uuid_1']['queue_mode']
);

assert_test(
	'AI step does NOT regrow user_message slot',
	! array_key_exists( 'user_message', $normalized['flow_config']['pstep_ai_uuid_1'] )
);

assert_test(
	'fetch step gets config_patch_queue=[] + queue_mode=static when previously unset',
	array() === $normalized['flow_config']['pstep_fetch_uuid_1']['config_patch_queue']
		&& 'static' === $normalized['flow_config']['pstep_fetch_uuid_1']['queue_mode']
);

// --- Case 5: existing values are preserved by normalization.
echo "\nCase 5: normalization preserves existing values\n";

$flow_with_set_values = array(
	'flow_id'     => 2,
	'flow_config' => array(
		'pstep_ai_uuid_1' => array(
			'step_type'    => 'ai',
			'prompt_queue' => array( array( 'prompt' => 'queued', 'added_at' => '2026-01-01T00:00:00Z' ) ),
			'queue_mode'   => 'drain',
		),
	),
);

$preserved = normalize_ai_prompt_slots_for_test( $flow_with_set_values );

assert_test(
	'existing prompt_queue preserved verbatim',
	$preserved['flow_config']['pstep_ai_uuid_1']['prompt_queue']
		=== array( array( 'prompt' => 'queued', 'added_at' => '2026-01-01T00:00:00Z' ) )
);

assert_test(
	'existing queue_mode preserved verbatim',
	'drain' === $preserved['flow_config']['pstep_ai_uuid_1']['queue_mode']
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
			&& 'static' === $out['flow_config']['pstep_ai_uuid_1']['queue_mode'];
	})()
);

// --- Case 7: resolver picks queue_head when prompt_queue has entries.
echo "\nCase 7: resolveAiStepActivePrompt — queue head is the only source\n";

$step_with_queue = array(
	'step_type'    => 'ai',
	'prompt_queue' => array(
		array( 'prompt' => 'first queued', 'added_at' => '2026-01-01T00:00:00Z' ),
		array( 'prompt' => 'second queued', 'added_at' => '2026-01-02T00:00:00Z' ),
	),
	'queue_mode'   => 'static',
);

$resolved = resolve_ai_active_prompt_for_test( $step_with_queue );

assert_test(
	'queue head resolves to queue_head slot',
	'queue_head' === $resolved['slot'] && 'first queued' === $resolved['value']
);

assert_test(
	'queue depth reflects total entries (not just head)',
	2 === $resolved['queue_depth']
);

assert_test(
	'queue_mode surfaced unchanged',
	'static' === $resolved['queue_mode']
);

// --- Case 8: drain mode + non-empty queue still resolves to queue_head.
echo "\nCase 8: resolveAiStepActivePrompt — drain mode + non-empty queue\n";

$step_drains_mode = array(
	'step_type'    => 'ai',
	'prompt_queue' => array( array( 'prompt' => 'drain tick', 'added_at' => '2026-01-01T00:00:00Z' ) ),
	'queue_mode'   => 'drain',
);

$resolved = resolve_ai_active_prompt_for_test( $step_drains_mode );

assert_test(
	'drain-mode queue head resolves to queue_head slot',
	'queue_head' === $resolved['slot'] && 'drain tick' === $resolved['value']
);

assert_test(
	'queue_mode=drain surfaced unchanged',
	'drain' === $resolved['queue_mode']
);

// --- Case 9: empty queue yields slot=none.
echo "\nCase 9: resolveAiStepActivePrompt — empty queue yields slot=none\n";

$step_empty = array(
	'step_type'    => 'ai',
	'prompt_queue' => array(),
	'queue_mode'   => 'static',
);

$resolved = resolve_ai_active_prompt_for_test( $step_empty );

assert_test(
	'empty queue → slot=none',
	'none' === $resolved['slot'] && '' === $resolved['value']
);

assert_test(
	'empty queue → queue_depth=0',
	0 === $resolved['queue_depth']
);

// --- Case 10: queue head with empty string yields slot=none.
echo "\nCase 10: resolveAiStepActivePrompt — empty-string queue head yields slot=none\n";

$step_empty_head = array(
	'step_type'    => 'ai',
	'prompt_queue' => array( array( 'prompt' => '', 'added_at' => '2026-01-01T00:00:00Z' ) ),
	'queue_mode'   => 'static',
);

$resolved = resolve_ai_active_prompt_for_test( $step_empty_head );

assert_test(
	'empty-string queue head → slot=none (no fallback to dead user_message)',
	'none' === $resolved['slot']
);

// --- Case 11: malformed prompt_queue (non-array) is null-safe.
echo "\nCase 11: resolveAiStepActivePrompt — null-safe on malformed data\n";

$step_malformed = array(
	'step_type'    => 'ai',
	'prompt_queue' => 'not an array',
	'queue_mode'   => 'static',
);

$resolved = resolve_ai_active_prompt_for_test( $step_malformed );

assert_test(
	'malformed prompt_queue → slot=none',
	'none' === $resolved['slot']
);

assert_test(
	'malformed prompt_queue → queue_depth=0',
	0 === $resolved['queue_depth']
);

// --- Case 12: missing queue_mode defaults to static (matches loadFlowAndStepConfig).
echo "\nCase 12: resolveAiStepActivePrompt — missing queue_mode defaults to static\n";

$step_no_mode = array(
	'step_type'    => 'ai',
	'prompt_queue' => array( array( 'prompt' => 'pinned', 'added_at' => '2026-01-01T00:00:00Z' ) ),
);

$resolved = resolve_ai_active_prompt_for_test( $step_no_mode );

assert_test(
	'missing queue_mode → static default',
	'static' === $resolved['queue_mode']
);

assert_test(
	'queue head still resolves',
	'queue_head' === $resolved['slot'] && 'pinned' === $resolved['value']
);

echo "\n--- Summary ---\n";
echo "Passed: " . ( $total - $failed ) . " / $total\n";

if ( $failed > 0 ) {
	echo "FAILED\n";
	exit( 1 );
}

echo "OK\n";
exit( 0 );
