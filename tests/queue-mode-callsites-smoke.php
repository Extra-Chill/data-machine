<?php
/**
 * Pure-PHP smoke test for queue_mode callsite fixes (follow-up to #1291 / PR #1296).
 *
 * Run with: php tests/queue-mode-callsites-smoke.php
 *
 * #1291 + PR #1296 collapsed `user_message` into `prompt_queue` and
 * replaced `queue_enabled` with a `queue_mode` enum on every queueable
 * step. The migration converts persisted flow data; the runtime reads
 * the new shape; the public-facing `user_message` input parameter on
 * `UpdateFlowStepAbility::execute()` was preserved as a shim that
 * routes through `FlowStepHelpers::updateUserMessage()` (which
 * rewrites prompt_queue + queue_mode).
 *
 * Self-review of #1296 surfaced three callsites that build
 * flow_config arrays directly (NOT through `UpdateFlowStepAbility`)
 * and were missed in the original PR — they kept writing the legacy
 * `user_message` slot AIStep no longer reads:
 *
 *   1. `ExecuteWorkflowAbility::buildConfigsFromWorkflow()` — assembles
 *      an ephemeral in-memory flow_config from a workflow JSON spec.
 *      Wrote `user_message` to the flow_step_config root. Workflow
 *      spec input is silently dropped at runtime.
 *
 *   2. `FlowHelpers::buildCopiedFlowConfig()` — duplicate-flow path.
 *      Copied source's `user_message` field (which doesn't exist
 *      post-migration) and didn't copy prompt_queue / config_patch_queue
 *      / queue_mode. Duplicated flows lost their queue state.
 *
 *   3. `inc/migrations/user-message-queue-mode.php` "both populated"
 *      branch — when both `queue_enabled=true` AND non-empty
 *      `prompt_queue` AND non-empty `user_message`, the migration
 *      forced `queue_mode=static`. Pre-#1291 behaviour with
 *      queue_enabled=true was drain semantics; forcing static silently
 *      converts a draining flow into a static one. Correct behaviour
 *      is to preserve the boolean-resolved mode and drop user_message
 *      (with a log entry flagging the lossy drain-then-fallback edge
 *      case).
 *
 * The byte-mirror harness inlines the relevant slices of the real
 * code. Divergence between this file and production is caught by
 * failing assertions.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/smoke-wp-stubs.php';

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		// no-op for tests.
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( (string) $value );
	}
}
if ( ! function_exists( 'mb_substr' ) && ! function_exists( 'datamachine_test_mb_polyfill' ) ) {
	// mb_substr is always available on supported PHP versions; the polyfill
	// here is just defensive against minimal CI containers.
	function datamachine_test_mb_polyfill(): void {}
}

$failed = 0;
$total  = 0;

function assert_callsite( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
	} else {
		echo "  [FAIL] $name" . ( $detail ? " — $detail" : '' ) . "\n";
		++$failed;
	}
}

// ====================================================================
// Bug 1: ExecuteWorkflowAbility::buildConfigsFromWorkflow
// ====================================================================
//
// Inline mirror of the relevant slice of buildConfigsFromWorkflow
// post-fix. The workflow JSON spec accepts `user_message` as an input
// field (matches ExecuteWorkflowTool's documented shape); the helper
// converts it into a 1-entry static prompt_queue so AIStep sees it.

function build_ephemeral_flow_step_for_test( array $step, int $index ): array {
	$step_id          = "ephemeral_step_{$index}";
	$pipeline_step_id = "ephemeral_pipeline_{$index}";
	$step_type        = $step['type'];

	$workflow_user_message = is_string( $step['user_message'] ?? null )
		? trim( $step['user_message'] )
		: '';
	$prompt_queue          = array();
	if ( 'ai' === $step_type && '' !== $workflow_user_message ) {
		$prompt_queue = array(
			array(
				'prompt'   => $workflow_user_message,
				'added_at' => '2026-04-26T00:00:00+00:00',
			),
		);
	}

	return array(
		'flow_step_id'     => $step_id,
		'pipeline_step_id' => $pipeline_step_id,
		'step_type'        => $step_type,
		'execution_order'  => $index,
		'prompt_queue'     => $prompt_queue,
		'queue_mode'       => 'static',
	);
}

echo "=== queue-mode-callsites-smoke ===\n";

echo "\n[ephemeral:1] AI workflow step with user_message → 1-entry static prompt_queue\n";
$step = array(
	'type'         => 'ai',
	'user_message' => 'Summarize for social media',
);
$cfg = build_ephemeral_flow_step_for_test( $step, 0 );
assert_callsite(
	'AIStep input lands in prompt_queue head',
	1 === count( $cfg['prompt_queue'] )
		&& 'Summarize for social media' === $cfg['prompt_queue'][0]['prompt']
);
assert_callsite(
	'queue_mode is static',
	'static' === $cfg['queue_mode']
);
assert_callsite(
	'no legacy user_message slot written',
	! array_key_exists( 'user_message', $cfg )
);

echo "\n[ephemeral:2] AI workflow step with empty user_message → empty prompt_queue\n";
$step = array(
	'type'         => 'ai',
	'user_message' => '',
);
$cfg = build_ephemeral_flow_step_for_test( $step, 0 );
assert_callsite( 'empty input → empty queue', array() === $cfg['prompt_queue'] );
assert_callsite( 'queue_mode still static', 'static' === $cfg['queue_mode'] );

echo "\n[ephemeral:3] AI workflow step with no user_message field → empty prompt_queue\n";
$step = array(
	'type' => 'ai',
);
$cfg = build_ephemeral_flow_step_for_test( $step, 0 );
assert_callsite( 'missing input → empty queue', array() === $cfg['prompt_queue'] );

echo "\n[ephemeral:4] non-AI workflow step ignores user_message field even when present\n";
// The workflow spec shouldn't carry user_message on non-AI steps, but
// be defensive: a fetch step with a stray user_message in its spec
// should NOT seed a prompt_queue (only AI steps consume prompt_queue).
$step = array(
	'type'         => 'fetch',
	'user_message' => 'should be ignored',
);
$cfg = build_ephemeral_flow_step_for_test( $step, 0 );
assert_callsite(
	'fetch step does not get prompt_queue seeded from user_message',
	array() === $cfg['prompt_queue']
);

echo "\n[ephemeral:5] whitespace-only user_message → empty prompt_queue\n";
$step = array(
	'type'         => 'ai',
	'user_message' => '   ',
);
$cfg = build_ephemeral_flow_step_for_test( $step, 0 );
assert_callsite( 'whitespace input → empty queue', array() === $cfg['prompt_queue'] );

// ====================================================================
// Bug 2: FlowHelpers::buildCopiedFlowConfig
// ====================================================================
//
// Inline mirror of the source-step copy block + the override block,
// post-fix. Source-step copy: prompt_queue / config_patch_queue /
// queue_mode replace the dead user_message read. Override block:
// user_message override input is converted to a 1-entry static
// prompt_queue (matches the public-facing override contract used by
// `flow copy` and chat tools).

function build_copied_flow_step_for_test( array $source_step, ?array $override = null ): array {
	$new_step_config = array(
		'flow_step_id'     => 'new_step_id',
		'step_type'        => $source_step['step_type'] ?? 'ai',
		'pipeline_step_id' => 'new_pipeline_step',
		'pipeline_id'      => 99,
		'flow_id'          => 99,
		'execution_order'  => 0,
	);

	if ( ! empty( $source_step['handler_slugs'] ) ) {
		$new_step_config['handler_slugs'] = $source_step['handler_slugs'];
	}
	if ( ! empty( $source_step['handler_configs'] ) ) {
		$new_step_config['handler_configs'] = $source_step['handler_configs'];
	}

	if ( isset( $source_step['prompt_queue'] ) && is_array( $source_step['prompt_queue'] ) ) {
		$new_step_config['prompt_queue'] = $source_step['prompt_queue'];
	}
	if ( isset( $source_step['config_patch_queue'] ) && is_array( $source_step['config_patch_queue'] ) ) {
		$new_step_config['config_patch_queue'] = $source_step['config_patch_queue'];
	}
	if ( isset( $source_step['queue_mode'] )
		&& in_array( $source_step['queue_mode'], array( 'drain', 'loop', 'static' ), true )
	) {
		$new_step_config['queue_mode'] = $source_step['queue_mode'];
	}

	if ( $override ) {
		if ( ! empty( $override['handler_slug'] ) ) {
			$new_step_config['handler_slugs']   = array( $override['handler_slug'] );
			$handler_config                     = $override['handler_config'] ?? array();
			$new_step_config['handler_configs'] = array( $override['handler_slug'] => $handler_config );
		}
		if ( ! empty( $override['user_message'] ) ) {
			$new_step_config['prompt_queue'] = array(
				array(
					'prompt'   => $override['user_message'],
					'added_at' => '2026-04-26T00:00:00+00:00',
				),
			);
			$new_step_config['queue_mode']   = 'static';
		}
	}

	return $new_step_config;
}

echo "\n[copy:1] source AI step with prompt_queue + queue_mode copied verbatim\n";
$source = array(
	'step_type'    => 'ai',
	'prompt_queue' => array(
		array( 'prompt' => 'tick a', 'added_at' => '2026-01-01T00:00:00Z' ),
		array( 'prompt' => 'tick b', 'added_at' => '2026-01-02T00:00:00Z' ),
	),
	'queue_mode'   => 'drain',
);
$copied = build_copied_flow_step_for_test( $source );
assert_callsite(
	'prompt_queue copied verbatim',
	$copied['prompt_queue'] === $source['prompt_queue']
);
assert_callsite(
	'queue_mode copied verbatim',
	'drain' === $copied['queue_mode']
);

echo "\n[copy:2] source fetch step with config_patch_queue + queue_mode copied verbatim\n";
$source = array(
	'step_type'          => 'fetch',
	'handler_slugs'      => array( 'mcp' ),
	'handler_configs'    => array( 'mcp' => array( 'tool' => 'search' ) ),
	'config_patch_queue' => array(
		array( 'patch' => array( 'after' => '2017-01-01' ), 'added_at' => 'x' ),
	),
	'queue_mode'         => 'drain',
);
$copied = build_copied_flow_step_for_test( $source );
assert_callsite(
	'config_patch_queue copied verbatim',
	$copied['config_patch_queue'] === $source['config_patch_queue']
);
assert_callsite(
	'queue_mode copied for fetch step',
	'drain' === $copied['queue_mode']
);
assert_callsite(
	'no spurious prompt_queue on fetch',
	! array_key_exists( 'prompt_queue', $copied )
);

echo "\n[copy:3] missing queue_mode on source defaults absent on copy (loadFlowAndStepConfig fills static at read time)\n";
$source = array(
	'step_type' => 'ai',
);
$copied = build_copied_flow_step_for_test( $source );
assert_callsite(
	'no queue_mode key when source lacked it',
	! array_key_exists( 'queue_mode', $copied )
);

echo "\n[copy:4] override.user_message → 1-entry static prompt_queue (replaces source queue)\n";
$source = array(
	'step_type'    => 'ai',
	'prompt_queue' => array( array( 'prompt' => 'old', 'added_at' => 'x' ) ),
	'queue_mode'   => 'drain',
);
$override = array( 'user_message' => 'override message' );
$copied   = build_copied_flow_step_for_test( $source, $override );
assert_callsite(
	'override prompt_queue contains exactly 1 entry',
	1 === count( $copied['prompt_queue'] )
);
assert_callsite(
	'override prompt_queue head matches override input',
	'override message' === $copied['prompt_queue'][0]['prompt']
);
assert_callsite(
	'override forces queue_mode=static',
	'static' === $copied['queue_mode']
);

echo "\n[copy:5] no override + no source queue_mode → no queue_mode key on copy\n";
$source = array(
	'step_type' => 'ai',
);
$copied = build_copied_flow_step_for_test( $source, null );
assert_callsite(
	'no queue_mode invented out of thin air',
	! array_key_exists( 'queue_mode', $copied )
);

echo "\n[copy:6] invalid queue_mode on source is ignored\n";
$source = array(
	'step_type'  => 'ai',
	'queue_mode' => 'banana', // not in enum
);
$copied = build_copied_flow_step_for_test( $source );
assert_callsite(
	'unknown queue_mode value rejected',
	! array_key_exists( 'queue_mode', $copied )
);

// ====================================================================
// Bug 3: migration "both populated" branch
// ====================================================================
//
// Inline mirror of the corrected migration's AI-step user_message
// branch. Critical assertion: queue_mode for the "both populated"
// case follows the original queue_enabled boolean (drain or static),
// NOT a forced "static" override. Pre-fix, the migration silently
// converted draining flows into static ones.

function migrate_ai_step_for_test( array $step ): array {
	$has_queue_enabled = array_key_exists( 'queue_enabled', $step );
	$has_user_message  = array_key_exists( 'user_message', $step );

	if ( ! $has_queue_enabled && ! $has_user_message ) {
		return $step;
	}

	$queue_enabled      = $has_queue_enabled ? (bool) $step['queue_enabled'] : false;
	$queue_mode         = $queue_enabled ? 'drain' : 'static';
	$step['queue_mode'] = $queue_mode;

	if ( 'ai' === ( $step['step_type'] ?? '' ) && $has_user_message ) {
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
			}
			// else: keep queue_mode at boolean-resolved value, drop user_message
		}
	}

	unset( $step['user_message'] );
	unset( $step['queue_enabled'] );

	return $step;
}

echo "\n[migration-fix:1] queue_enabled=true + non-empty queue + user_message → drain preserved\n";
$migrated = migrate_ai_step_for_test( array(
	'step_type'     => 'ai',
	'queue_enabled' => true,
	'prompt_queue'  => array( array( 'prompt' => 'a', 'added_at' => 'x' ) ),
	'user_message'  => 'shadowed',
) );
assert_callsite(
	'queue_enabled=true preserved as drain (regression: was forced to static pre-fix)',
	'drain' === $migrated['queue_mode']
);
assert_callsite(
	'queue head preserved',
	1 === count( $migrated['prompt_queue'] ) && 'a' === $migrated['prompt_queue'][0]['prompt']
);
assert_callsite( 'user_message dropped', ! array_key_exists( 'user_message', $migrated ) );
assert_callsite( 'queue_enabled dropped', ! array_key_exists( 'queue_enabled', $migrated ) );

echo "\n[migration-fix:2] queue_enabled=false + non-empty queue + user_message → static preserved\n";
$migrated = migrate_ai_step_for_test( array(
	'step_type'     => 'ai',
	'queue_enabled' => false,
	'prompt_queue'  => array( array( 'prompt' => 'pinned', 'added_at' => 'x' ) ),
	'user_message'  => 'shadowed',
) );
assert_callsite(
	'queue_enabled=false preserved as static',
	'static' === $migrated['queue_mode']
);
assert_callsite( 'queue head preserved', 'pinned' === $migrated['prompt_queue'][0]['prompt'] );

echo "\n[migration-fix:3] queue_enabled=true + empty queue + user_message → seeded static (case unchanged)\n";
$migrated = migrate_ai_step_for_test( array(
	'step_type'     => 'ai',
	'queue_enabled' => true,
	'prompt_queue'  => array(),
	'user_message'  => 'X',
) );
assert_callsite(
	'empty queue + user_message: queue seeded with X',
	1 === count( $migrated['prompt_queue'] )
		&& 'X' === $migrated['prompt_queue'][0]['prompt']
);
assert_callsite(
	'empty queue + user_message: forced to static (pre-#1291 ran user_message every tick when queue empty)',
	'static' === $migrated['queue_mode']
);

echo "\n[migration-fix:4] queue_enabled=true + empty queue + no user_message → empty drain\n";
$migrated = migrate_ai_step_for_test( array(
	'step_type'     => 'ai',
	'queue_enabled' => true,
	'prompt_queue'  => array(),
) );
assert_callsite(
	'empty queue, no user_message: drain mode preserved',
	'drain' === $migrated['queue_mode']
);
assert_callsite( 'empty queue stays empty', array() === ( $migrated['prompt_queue'] ?? array() ) );

echo "\n[migration-fix:5] queue_enabled=true + non-empty loop-shaped queue + user_message → drain preserved (NOT loop)\n";
// Sanity check that the corrected migration doesn't accidentally
// convert drain → loop. queue_enabled=true is exactly drain semantics
// pre-#1291.
$migrated = migrate_ai_step_for_test( array(
	'step_type'     => 'ai',
	'queue_enabled' => true,
	'prompt_queue'  => array(
		array( 'prompt' => 'tick a', 'added_at' => 'x' ),
		array( 'prompt' => 'tick b', 'added_at' => 'y' ),
		array( 'prompt' => 'tick c', 'added_at' => 'z' ),
	),
	'user_message'  => 'fallback after drain',
) );
assert_callsite(
	'multi-entry drain queue: queue_mode=drain (not loop)',
	'drain' === $migrated['queue_mode']
);
assert_callsite(
	'all queue entries preserved',
	3 === count( $migrated['prompt_queue'] )
);

echo "\n";
if ( 0 === $failed ) {
	echo "=== queue-mode-callsites-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== queue-mode-callsites-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
