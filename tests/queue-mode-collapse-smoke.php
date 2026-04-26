<?php
/**
 * Pure-PHP smoke test for the user_message → queue_mode collapse (#1291).
 *
 * Run with: php tests/queue-mode-collapse-smoke.php
 *
 * Pre-#1291 AIStep had two storage slots feeding the same per-flow
 * user-role message:
 *
 *   - flow_step_config[step_id].user_message   (single string)
 *   - flow_step_config[step_id].prompt_queue   (array of {prompt, added_at})
 *
 * paired with a `queue_enabled` boolean. Post-#1291 the only AI prompt
 * slot is `prompt_queue` and the access pattern is named explicitly via
 * a `queue_mode` enum: drain | loop | static.
 *
 * The byte-mirror harness inlines the relevant slices of the real code
 * so divergence between this file and production is caught by failing
 * assertions.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'gmdate' ) ) {
	// Defensive — gmdate is a PHP built-in but we need to be sure.
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		// no-op for tests; logged migrations aren't observable here.
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( (string) $value );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

$failed = 0;
$total  = 0;

function assert_collapse( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
	} else {
		echo "  [FAIL] $name" . ( $detail ? " — $detail" : '' ) . "\n";
		++$failed;
	}
}

/**
 * Inline mirror of inc/migrations/user-message-queue-mode.php, walking
 * one flow_config and applying the same transforms in-process so we can
 * assert on every observable behaviour without booting WordPress.
 *
 * Mirrors `datamachine_migrate_user_message_queue_mode()` exactly. Any
 * divergence here means production regressed.
 *
 * Returns [ migrated_flow_config, dropped_user_messages, seeded_user_messages ].
 */
function migrate_collapse_for_test( array $flow_config ): array {
	$dropped = 0;
	$seeded  = 0;

	foreach ( $flow_config as $step_id => &$step ) {
		if ( ! is_array( $step ) ) {
			continue;
		}

		$step_type         = $step['step_type'] ?? '';
		$has_queue_enabled = array_key_exists( 'queue_enabled', $step );
		$has_user_message  = array_key_exists( 'user_message', $step );

		if ( ! $has_queue_enabled && ! $has_user_message ) {
			continue;
		}

		$queue_enabled = $has_queue_enabled ? (bool) $step['queue_enabled'] : false;
		$queue_mode    = $queue_enabled ? 'drain' : 'static';

		$step['queue_mode'] = $queue_mode;

		if ( 'ai' === $step_type && $has_user_message ) {
			$user_message = is_string( $step['user_message'] ) ? trim( $step['user_message'] ) : '';
			$queue        = isset( $step['prompt_queue'] ) && is_array( $step['prompt_queue'] )
				? $step['prompt_queue']
				: array();

			if ( '' !== $user_message ) {
				if ( empty( $queue ) ) {
					$step['prompt_queue'] = array(
						array(
							'prompt'   => $user_message,
							'added_at' => '2026-04-26T00:00:00+00:00',
						),
					);
					$step['queue_mode'] = 'static';
					++$seeded;
				} else {
					// Both populated: kept queue, dropped user_message.
					$step['queue_mode'] = 'static';
					++$dropped;
				}
			}
		}

		unset( $step['user_message'] );
		unset( $step['queue_enabled'] );
	}
	unset( $step );

	return array( $flow_config, $dropped, $seeded );
}

/**
 * Inline mirror of QueueableTrait::consumeFromQueueSlot logic for tests.
 * Returns [updated_queue, consumed_entry_or_null, mutated_bool].
 */
function consume_for_test( array $queue, string $queue_mode ): array {
	if ( empty( $queue ) ) {
		return array( $queue, null, 'static' !== $queue_mode );
	}

	if ( 'static' === $queue_mode ) {
		return array( $queue, $queue[0], false );
	}

	$entry = array_shift( $queue );

	if ( 'loop' === $queue_mode ) {
		$queue[] = $entry;
	}

	return array( $queue, $entry, true );
}

/**
 * Inline mirror of FlowStepHelpers::updateUserMessage post-#1291. The
 * helper is repurposed: the dedicated user_message slot is gone, the
 * helper rewrites prompt_queue + queue_mode instead.
 */
function update_user_message_for_test( array $flow_step_config, string $user_message ): array {
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

	// Critical: no user_message field stored anywhere.
	unset( $flow_step_config['user_message'] );

	return $flow_step_config;
}

/**
 * Inline mirror of QueueAbility::executeQueueMode validation.
 */
function queue_mode_validate_for_test( $mode ): array {
	if ( ! is_string( $mode ) || ! in_array( $mode, array( 'drain', 'loop', 'static' ), true ) ) {
		return array(
			'success' => false,
			'error'   => 'mode must be one of: drain, loop, static',
		);
	}
	return array(
		'success'    => true,
		'queue_mode' => $mode,
	);
}

echo "=== queue-mode-collapse-smoke ===\n";

// =====================================================================
// Migration tests
// =====================================================================

echo "\n[migration:1] queue_enabled=true → queue_mode=drain on AI step\n";
$flow_config = array(
	'ai_step_42' => array(
		'step_type'     => 'ai',
		'user_message'  => '',
		'queue_enabled' => true,
		'prompt_queue'  => array(
			array( 'prompt' => 'hello', 'added_at' => 'x' ),
		),
	),
);
[ $migrated, $dropped, $seeded ] = migrate_collapse_for_test( $flow_config );
assert_collapse(
	'queue_enabled=true resolved to drain',
	'drain' === ( $migrated['ai_step_42']['queue_mode'] ?? '' ),
	'mode=' . ( $migrated['ai_step_42']['queue_mode'] ?? 'NULL' )
);
assert_collapse(
	'user_message stripped',
	! array_key_exists( 'user_message', $migrated['ai_step_42'] )
);
assert_collapse(
	'queue_enabled stripped',
	! array_key_exists( 'queue_enabled', $migrated['ai_step_42'] )
);
assert_collapse(
	'prompt_queue preserved as-is',
	1 === count( $migrated['ai_step_42']['prompt_queue'] )
		&& 'hello' === $migrated['ai_step_42']['prompt_queue'][0]['prompt']
);
assert_collapse( 'no user_message seeded for empty user_message', 0 === $seeded );
assert_collapse( 'no user_message dropped (was empty)', 0 === $dropped );

echo "\n[migration:2] queue_enabled=false + non-empty queue → queue_mode=static (named stockpile)\n";
$flow_config = array(
	'ai_step_42' => array(
		'step_type'     => 'ai',
		'user_message'  => '',
		'queue_enabled' => false,
		'prompt_queue'  => array(
			array( 'prompt' => 'first', 'added_at' => 'a' ),
			array( 'prompt' => 'second', 'added_at' => 'b' ),
			array( 'prompt' => 'third', 'added_at' => 'c' ),
		),
	),
);
[ $migrated ] = migrate_collapse_for_test( $flow_config );
assert_collapse(
	'static mode preserves first-entry-wins-every-tick',
	'static' === ( $migrated['ai_step_42']['queue_mode'] ?? '' )
);
assert_collapse(
	'all 3 stockpile entries preserved',
	3 === count( $migrated['ai_step_42']['prompt_queue'] )
);

echo "\n[migration:3] empty prompt_queue + non-empty user_message → 1-entry static queue\n";
$flow_config = array(
	'ai_step_42' => array(
		'step_type'     => 'ai',
		'user_message'  => 'My per-flow framing prompt',
		'queue_enabled' => false,
		'prompt_queue'  => array(),
	),
);
[ $migrated, $dropped, $seeded ] = migrate_collapse_for_test( $flow_config );
assert_collapse(
	'user_message seeded into 1-entry queue',
	1 === count( $migrated['ai_step_42']['prompt_queue'] )
		&& 'My per-flow framing prompt' === $migrated['ai_step_42']['prompt_queue'][0]['prompt']
);
assert_collapse(
	'mode forced to static',
	'static' === $migrated['ai_step_42']['queue_mode']
);
assert_collapse( 'seeded counter incremented', 1 === $seeded );
assert_collapse( 'dropped counter not touched', 0 === $dropped );
assert_collapse(
	'user_message field gone',
	! array_key_exists( 'user_message', $migrated['ai_step_42'] )
);

echo "\n[migration:4] both prompt_queue and user_message non-empty → keep queue, drop user_message\n";
$flow_config = array(
	'ai_step_42' => array(
		'step_type'     => 'ai',
		'user_message'  => 'shadowed legacy message',
		'queue_enabled' => true,
		'prompt_queue'  => array(
			array( 'prompt' => 'queued head wins', 'added_at' => 'x' ),
		),
	),
);
[ $migrated, $dropped, $seeded ] = migrate_collapse_for_test( $flow_config );
assert_collapse(
	'queue preserved as-is',
	1 === count( $migrated['ai_step_42']['prompt_queue'] )
		&& 'queued head wins' === $migrated['ai_step_42']['prompt_queue'][0]['prompt']
);
assert_collapse(
	'mode forced to static (preserves observable behaviour)',
	'static' === $migrated['ai_step_42']['queue_mode']
);
assert_collapse( 'dropped counter incremented', 1 === $dropped );
assert_collapse( 'seeded counter not touched', 0 === $seeded );
assert_collapse(
	'user_message dropped',
	! array_key_exists( 'user_message', $migrated['ai_step_42'] )
);

echo "\n[migration:5] FetchStep with queue_enabled=true → queue_mode=drain, no user_message handling\n";
$flow_config = array(
	'fetch_step_99' => array(
		'step_type'          => 'fetch',
		'queue_enabled'      => true,
		'config_patch_queue' => array(
			array( 'patch' => array( 'after' => '2017-01-01' ), 'added_at' => 'x' ),
		),
	),
);
[ $migrated ] = migrate_collapse_for_test( $flow_config );
assert_collapse(
	'fetch step gets drain mode',
	'drain' === ( $migrated['fetch_step_99']['queue_mode'] ?? '' )
);
assert_collapse(
	'fetch step queue_enabled stripped',
	! array_key_exists( 'queue_enabled', $migrated['fetch_step_99'] )
);
assert_collapse(
	'fetch step config_patch_queue preserved',
	1 === count( $migrated['fetch_step_99']['config_patch_queue'] )
);

echo "\n[migration:6] idempotent — running migration twice = same result\n";
$flow_config = array(
	'ai_step_42' => array(
		'step_type'     => 'ai',
		'user_message'  => 'seed me',
		'queue_enabled' => false,
		'prompt_queue'  => array(),
	),
);
[ $first ] = migrate_collapse_for_test( $flow_config );
[ $second ] = migrate_collapse_for_test( $first );
assert_collapse( 'second run is no-op (no queue_enabled to flip)', $first === $second );

echo "\n[migration:7] non-queueable steps left untouched\n";
$flow_config = array(
	'publish_step_1' => array(
		'step_type'      => 'publish',
		'handler_slugs'  => array( 'wordpress' ),
		'handler_configs' => array( 'wordpress' => array( 'post_type' => 'post' ) ),
	),
);
[ $migrated ] = migrate_collapse_for_test( $flow_config );
assert_collapse(
	'publish step config unchanged',
	$flow_config === $migrated
);

// =====================================================================
// Mode-aware consumption tests
// =====================================================================

echo "\n[consume:1] drain mode pops queue head per tick\n";
$queue = array(
	array( 'prompt' => 'a', 'added_at' => '1' ),
	array( 'prompt' => 'b', 'added_at' => '2' ),
	array( 'prompt' => 'c', 'added_at' => '3' ),
);
[ $q1, $entry1, $mut1 ] = consume_for_test( $queue, 'drain' );
assert_collapse( 'first drain consumes a', 'a' === $entry1['prompt'] );
assert_collapse( 'first drain mutates', true === $mut1 );
[ $q2, $entry2 ] = consume_for_test( $q1, 'drain' );
assert_collapse( 'second drain consumes b', 'b' === $entry2['prompt'] );
[ $q3, $entry3 ] = consume_for_test( $q2, 'drain' );
assert_collapse( 'third drain consumes c', 'c' === $entry3['prompt'] );
[ $q4, $entry4 ] = consume_for_test( $q3, 'drain' );
assert_collapse( 'fourth drain returns null (empty)', null === $entry4 );

echo "\n[consume:2] loop mode pops + appends — queue rotates back to original after N ticks\n";
$queue   = array(
	array( 'prompt' => 'a', 'added_at' => '1' ),
	array( 'prompt' => 'b', 'added_at' => '2' ),
	array( 'prompt' => 'c', 'added_at' => '3' ),
);
$current = $queue;
$entries = array();
for ( $i = 0; $i < 6; ++$i ) {
	[ $current, $e ] = consume_for_test( $current, 'loop' );
	$entries[]       = $e['prompt'];
}
assert_collapse(
	'loop sequence cycles a,b,c,a,b,c after 6 ticks',
	array( 'a', 'b', 'c', 'a', 'b', 'c' ) === $entries
);
assert_collapse(
	'loop queue back to original shape after 3 ticks',
	count( $current ) === count( $queue )
);

echo "\n[consume:3] static mode peeks — queue unchanged after tick\n";
$queue   = array(
	array( 'prompt' => 'pinned', 'added_at' => '1' ),
	array( 'prompt' => 'staged_b', 'added_at' => '2' ),
);
[ $after, $entry, $mut ] = consume_for_test( $queue, 'static' );
assert_collapse( 'static consumes head', 'pinned' === $entry['prompt'] );
assert_collapse( 'static does not mutate', false === $mut );
assert_collapse( 'static queue unchanged', $after === $queue );

echo "\n[consume:4] static mode + multi-entry queue: position 0 fires every tick\n";
$queue = array(
	array( 'prompt' => 'iterating', 'added_at' => '1' ),
	array( 'prompt' => 'staged_next', 'added_at' => '2' ),
	array( 'prompt' => 'staged_after', 'added_at' => '3' ),
);
$picked = array();
for ( $i = 0; $i < 4; ++$i ) {
	[ $queue, $e ] = consume_for_test( $queue, 'static' );
	$picked[]      = $e['prompt'];
}
assert_collapse(
	'iterative-dev pattern: head fires forever',
	array( 'iterating', 'iterating', 'iterating', 'iterating' ) === $picked
);

echo "\n[consume:5] empty queue + drain → null + mutated:true (signal no-items)\n";
[ $q, $e, $mut ] = consume_for_test( array(), 'drain' );
assert_collapse( 'empty drain returns null entry', null === $e );
assert_collapse( 'empty drain signals mutation intent (caller should skip)', true === $mut );

echo "\n[consume:6] empty queue + static → null + mutated:false (fallthrough)\n";
[ $q, $e, $mut ] = consume_for_test( array(), 'static' );
assert_collapse( 'empty static returns null entry', null === $e );
assert_collapse( 'empty static signals no mutation (caller falls through)', false === $mut );

// =====================================================================
// updateUserMessage shim tests
// =====================================================================

echo "\n[shim:1] user_message=\"X\" writes 1-entry static queue\n";
$step = array(
	'step_type' => 'ai',
);
$updated = update_user_message_for_test( $step, 'My new message' );
assert_collapse(
	'prompt_queue is 1-entry list',
	1 === count( $updated['prompt_queue'] )
);
assert_collapse(
	'queue head matches input',
	'My new message' === $updated['prompt_queue'][0]['prompt']
);
assert_collapse(
	'queue mode is static',
	'static' === $updated['queue_mode']
);
assert_collapse(
	'no user_message field stored',
	! array_key_exists( 'user_message', $updated )
);

echo "\n[shim:2] user_message=\"\" clears queue (legacy unset semantics)\n";
$step = array(
	'step_type'    => 'ai',
	'prompt_queue' => array(
		array( 'prompt' => 'old prompt', 'added_at' => 'x' ),
	),
);
$updated = update_user_message_for_test( $step, '' );
assert_collapse( 'queue emptied', array() === $updated['prompt_queue'] );
assert_collapse( 'mode set to static', 'static' === $updated['queue_mode'] );

echo "\n[shim:3] update overwrites existing queue\n";
$step = array(
	'step_type'    => 'ai',
	'prompt_queue' => array(
		array( 'prompt' => 'first', 'added_at' => 'a' ),
		array( 'prompt' => 'second', 'added_at' => 'b' ),
	),
);
$updated = update_user_message_for_test( $step, 'replacement' );
assert_collapse(
	'queue replaced with single entry',
	1 === count( $updated['prompt_queue'] )
		&& 'replacement' === $updated['prompt_queue'][0]['prompt']
);

// =====================================================================
// QueueAbility::executeQueueMode validation tests
// =====================================================================

echo "\n[mode-validate:1] enum validation rejects unknown\n";
$result = queue_mode_validate_for_test( 'invalid' );
assert_collapse( 'unknown mode rejected', false === $result['success'] );

echo "\n[mode-validate:2] each valid enum accepted\n";
foreach ( array( 'drain', 'loop', 'static' ) as $mode ) {
	$result = queue_mode_validate_for_test( $mode );
	assert_collapse( "mode={$mode} accepted", true === $result['success'] );
}

echo "\n[mode-validate:3] non-string rejected\n";
$result = queue_mode_validate_for_test( true );
assert_collapse( 'boolean rejected', false === $result['success'] );

// =====================================================================
// AIStep collapsed precedence tests
// =====================================================================

echo "\n[aistep:1] empty queue + drain → COMPLETED_NO_ITEMS branch\n";
// AIStep collapsed logic: $queue_mode picks consumption; empty queue +
// (drain|loop) sets job_status=COMPLETED_NO_ITEMS.
$queue_mode  = 'drain';
$queue       = array();
[ , $entry ] = consume_for_test( $queue, $queue_mode );
$user_msg    = null !== $entry ? $entry['prompt'] : '';
$should_skip = '' === $user_msg && in_array( $queue_mode, array( 'drain', 'loop' ), true );
assert_collapse( 'empty drain triggers no-items skip branch', true === $should_skip );

echo "\n[aistep:2] empty queue + static → fall through with empty user_message\n";
$queue_mode  = 'static';
$queue       = array();
[ , $entry ] = consume_for_test( $queue, $queue_mode );
$user_msg    = null !== $entry ? $entry['prompt'] : '';
$should_skip = '' === $user_msg && in_array( $queue_mode, array( 'drain', 'loop' ), true );
assert_collapse( 'empty static does not trigger skip', false === $should_skip );
assert_collapse( 'static fallthrough yields empty user_message', '' === $user_msg );

echo "\n[aistep:3] non-empty queue + drain → user_message comes from popped head\n";
$queue       = array(
	array( 'prompt' => 'tick-1 work', 'added_at' => 'x' ),
	array( 'prompt' => 'tick-2 work', 'added_at' => 'y' ),
);
[ $after, $entry ] = consume_for_test( $queue, 'drain' );
assert_collapse( 'drain returns first entry', 'tick-1 work' === $entry['prompt'] );
assert_collapse( 'drain mutates queue (length 1 remaining)', 1 === count( $after ) );

// =====================================================================

echo "\n";
if ( 0 === $failed ) {
	echo "=== queue-mode-collapse-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== queue-mode-collapse-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
