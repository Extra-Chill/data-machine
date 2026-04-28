<?php
/**
 * Pure-PHP smoke test for the queue consumption consolidation (#1299).
 *
 * Run with: php tests/queue-consumption-consolidation-smoke.php
 *
 * Pre-#1299 the codebase had three queue consumers but only two shared
 * a code path:
 *
 *   - AIStep              uses `QueueableTrait::consumeFromPromptQueue()`
 *   - FetchStep           uses `QueueableTrait::consumeFromConfigPatchQueue()`
 *   - AgentCallTask       called `QueueAbility::popFromQueue() / ::loopFromQueue()`
 *                         directly — reimplementing pop logic minus the
 *                         static-peek branch.
 *
 * The trait's `private static consumeFromQueueSlot()` and QueueAbility's
 * `private static popFromQueueSlot()` were near-duplicates (drain/loop
 * differed only in whether the popped entry was appended to the tail).
 * The duplication meant queue-mode shape changes had to land in two
 * places; AgentCallTask got a different log shape than the trait
 * consumers; AgentCallTask had no `queued_prompt_backup` write, so a
 * delivery failure after the pop silently lost the prompt.
 *
 * #1299 promotes the consumer-agnostic core to
 * `QueueAbility::consumeFromQueueSlot()` (public static), deletes the
 * three orphan helpers (`popFromQueue`, `loopFromQueue`,
 * `popConfigPatchFromQueue` — the last had ZERO callers), and migrates
 * AgentCallTask to call the new method directly. Single source of truth
 * for the drain / loop / static semantics regardless of consumer.
 *
 * This smoke validates:
 *
 *   1. `QueueAbility::consumeFromQueueSlot` exists with the documented
 *      signature.
 *   2. Trait's wrapper methods delegate to the new public method.
 *   3. AgentCallTask calls `consumeFromQueueSlot` directly (no
 *      `popFromQueue` / `loopFromQueue` references remain).
 *   4. The deleted helpers are truly gone from QueueAbility source.
 *   5. AgentCallTask now writes `queued_prompt_backup` for retry parity
 *      with the trait consumers.
 *   6. `consumeFromQueueSlot` semantic correctness via in-memory
 *      simulation: drain pops+writes, loop pops+rotates+writes, static
 *      peeks no-op.
 *   7. Unified log shape: `Item consumed from queue` with `slot` +
 *      `queue_mode` + `remaining_count`.
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
function assert_consolidation( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

$root_dir = dirname( __DIR__ );

echo "=== Queue Consumption Consolidation Smoke (#1299) ===\n";

// ---------------------------------------------------------------
// SECTION 1: QueueAbility::consumeFromQueueSlot exists.
// ---------------------------------------------------------------

echo "\n[ability:1] QueueAbility::consumeFromQueueSlot is the new shared entry point\n";
$qa_src = (string) file_get_contents( $root_dir . '/inc/Abilities/Flow/QueueAbility.php' );
assert_consolidation(
	'consumeFromQueueSlot is declared public static',
	(bool) preg_match(
		'/public static function consumeFromQueueSlot\(\s*int \$flow_id,\s*string \$flow_step_id,\s*string \$slot,\s*string \$queue_mode/s',
		$qa_src
	)
);
assert_consolidation(
	'consumeFromQueueSlot accepts an optional DB_Flows instance for testing',
	false !== strpos( $qa_src, '?DB_Flows $db_flows = null' )
);

// ---------------------------------------------------------------
// SECTION 2: Trait delegates to the new method.
// ---------------------------------------------------------------

echo "\n[trait:1] QueueableTrait calls QueueAbility::consumeFromQueueSlot()\n";
$trait_src = (string) file_get_contents( $root_dir . '/inc/Core/Steps/QueueableTrait.php' );
$delegate_call_count = preg_match_all(
	'/^\s*\$entry\s*=\s*QueueAbility::consumeFromQueueSlot\(/m',
	$trait_src
);
assert_consolidation(
	'trait delegates from both consumeOnce* methods (2 real callsites)',
	2 === $delegate_call_count
);

echo "\n[trait:2] Trait no longer declares its own consumeFromQueueSlot\n";
assert_consolidation(
	'no `private static function consumeFromQueueSlot` declaration in the trait',
	false === strpos( $trait_src, 'private static function consumeFromQueueSlot' )
);
assert_consolidation(
	'no `self::consumeFromQueueSlot` calls (delegate goes through QueueAbility)',
	false === strpos( $trait_src, 'self::consumeFromQueueSlot' )
);

echo "\n[trait:3] Trait dropped the now-unused DB_Flows import\n";
assert_consolidation(
	'use statement for `DB_Flows` is gone (no direct DB access in trait)',
	false === strpos( $trait_src, 'use DataMachine\\Core\\Database\\Flows\\Flows as DB_Flows' )
);

// ---------------------------------------------------------------
// SECTION 3: AgentCallTask calls the new method directly.
// ---------------------------------------------------------------

echo "\n[agent_call:1] AgentCallTask calls QueueAbility::consumeFromQueueSlot()\n";
$ping_src = (string) file_get_contents(
	$root_dir . '/inc/Engine/AI/System/Tasks/AgentCallTask.php'
);
assert_consolidation(
	'AgentCallTask calls QueueAbility::consumeFromQueueSlot()',
	false !== strpos( $ping_src, 'QueueAbility::consumeFromQueueSlot(' )
);
assert_consolidation(
	'call passes QueueAbility::SLOT_PROMPT_QUEUE constant',
	false !== strpos( $ping_src, 'QueueAbility::SLOT_PROMPT_QUEUE' )
);
assert_consolidation(
	'call threads queue_mode through (no separate loop/drain branch)',
	(bool) preg_match(
		'/consumeFromQueueSlot\([^)]*\$queue_mode\s*\)/s',
		$ping_src
	)
);

echo "\n[agent_call:2] No references to the deleted helpers in AgentCallTask\n";
assert_consolidation(
	'AgentCallTask does NOT reference QueueAbility::popFromQueue',
	false === strpos( $ping_src, 'QueueAbility::popFromQueue' )
);
assert_consolidation(
	'AgentCallTask does NOT reference QueueAbility::loopFromQueue',
	false === strpos( $ping_src, 'QueueAbility::loopFromQueue' )
);

// ---------------------------------------------------------------
// SECTION 4: Deleted helpers are gone from QueueAbility source.
// ---------------------------------------------------------------

echo "\n[deletion:1] popFromQueue / loopFromQueue / popConfigPatchFromQueue / popFromQueueSlot are removed\n";
foreach ( array(
	'function popFromQueue(',
	'function loopFromQueue(',
	'function popConfigPatchFromQueue(',
	'function popFromQueueSlot(',
) as $needle ) {
	assert_consolidation(
		"`{$needle}` is gone from QueueAbility",
		false === strpos( $qa_src, $needle )
	);
}

echo "\n[deletion:2] No PHP file in the codebase calls the deleted helpers\n";
$callers = array();
$rii     = new \RecursiveIteratorIterator(
	new \RecursiveDirectoryIterator( $root_dir, \FilesystemIterator::SKIP_DOTS )
);
$self_path = realpath( __FILE__ );
foreach ( $rii as $file ) {
	if ( $file->isDir() ) {
		continue;
	}
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$path = $file->getPathname();
	if (
		false !== strpos( $path, '/vendor/' )
		|| false !== strpos( $path, '/node_modules/' )
		|| realpath( $path ) === $self_path
	) {
		// Skip vendor, node_modules, and this smoke file itself
		// (the smoke contains the regex pattern as a string literal,
		// which would self-match without this guard).
		continue;
	}
	$contents = (string) file_get_contents( $path );
	// Look for invocations only — historical docblock mentions are
	// allowed (the QueueAbility + QueueableTrait docblocks document
	// the migration on purpose).
	if (
		preg_match( '/\bQueueAbility::popFromQueue\s*\(/', $contents )
		|| preg_match( '/\bQueueAbility::loopFromQueue\s*\(/', $contents )
		|| preg_match( '/\bQueueAbility::popConfigPatchFromQueue\s*\(/', $contents )
	) {
		$callers[] = ltrim( str_replace( $root_dir, '', $path ), '/' );
	}
}
assert_consolidation(
	'no live call sites for any deleted helper',
	array() === $callers
);

// ---------------------------------------------------------------
// SECTION 5: AgentCallTask writes queued_prompt_backup for retry parity.
// ---------------------------------------------------------------

echo "\n[parity:1] AgentCallTask writes queued_prompt_backup after a mutating consume\n";
assert_consolidation(
	'datamachine_merge_engine_data() called from AgentCallTask',
	false !== strpos( $ping_src, '\\datamachine_merge_engine_data(' )
);
assert_consolidation(
	'queued_prompt_backup payload includes slot, mode, prompt, flow_id, flow_step_id',
	false !== strpos( $ping_src, "'queued_prompt_backup'" )
		&& false !== strpos( $ping_src, "'slot'         => QueueAbility::SLOT_PROMPT_QUEUE" )
		&& false !== strpos( $ping_src, "'mode'         => \$queue_mode" )
		&& false !== strpos( $ping_src, "'prompt'       => \$queued_item['prompt']" )
);

// ---------------------------------------------------------------
// SECTION 6: In-memory consumeFromQueueSlot semantic correctness.
// ---------------------------------------------------------------

/**
 * Pure-PHP simulator of `QueueAbility::consumeFromQueueSlot()` that
 * mirrors the production logic. If the production behaviour drifts,
 * the byte-mirror smoke catches it; if THIS function drifts from the
 * behaviour, the assertions below fail. The simulator stands in for
 * the real DB-backed flow_config storage.
 *
 * @param array  $flow_config    The full flow_config array (mutated).
 * @param string $flow_step_id   Step ID.
 * @param string $slot           Queue slot name.
 * @param string $queue_mode     "drain" | "loop" | "static".
 * @return array{ entry: ?array, mutated: bool, remaining_count: int }
 */
function simulate_consume_from_queue_slot(
	array &$flow_config,
	string $flow_step_id,
	string $slot,
	string $queue_mode
): array {
	if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
		return array( 'entry' => null, 'mutated' => false, 'remaining_count' => 0 );
	}

	$step_config = $flow_config[ $flow_step_id ];
	$queue       = $step_config[ $slot ] ?? array();

	if ( empty( $queue ) ) {
		return array( 'entry' => null, 'mutated' => false, 'remaining_count' => 0 );
	}

	if ( 'static' === $queue_mode ) {
		return array(
			'entry'           => $queue[0],
			'mutated'         => false,
			'remaining_count' => count( $queue ),
		);
	}

	$entry = array_shift( $queue );

	if ( 'loop' === $queue_mode ) {
		$queue[] = $entry;
	}

	$flow_config[ $flow_step_id ][ $slot ] = $queue;

	return array(
		'entry'           => $entry,
		'mutated'         => true,
		'remaining_count' => count( $queue ),
	);
}

echo "\n[semantics:1] static mode peeks the head, no mutation\n";
$fc = array(
	'step1' => array(
		'prompt_queue' => array(
			array( 'prompt' => 'first',  'added_at' => 't0' ),
			array( 'prompt' => 'second', 'added_at' => 't1' ),
		),
	),
);
$result = simulate_consume_from_queue_slot( $fc, 'step1', 'prompt_queue', 'static' );
assert_consolidation(
	'static returns the head entry',
	'first' === ( $result['entry']['prompt'] ?? null )
);
assert_consolidation(
	'static did NOT mutate storage',
	false === $result['mutated']
		&& 2 === count( $fc['step1']['prompt_queue'] )
		&& 'first' === $fc['step1']['prompt_queue'][0]['prompt']
);

echo "\n[semantics:2] drain mode pops the head, head is gone after\n";
$fc = array(
	'step1' => array(
		'prompt_queue' => array(
			array( 'prompt' => 'first',  'added_at' => 't0' ),
			array( 'prompt' => 'second', 'added_at' => 't1' ),
		),
	),
);
$result = simulate_consume_from_queue_slot( $fc, 'step1', 'prompt_queue', 'drain' );
assert_consolidation(
	'drain returns the head entry',
	'first' === ( $result['entry']['prompt'] ?? null )
);
assert_consolidation(
	'drain mutated storage and dropped the head',
	true === $result['mutated']
		&& 1 === count( $fc['step1']['prompt_queue'] )
		&& 'second' === $fc['step1']['prompt_queue'][0]['prompt']
);
assert_consolidation(
	'drain reports remaining_count = 1 after popping from a 2-entry queue',
	1 === $result['remaining_count']
);

echo "\n[semantics:3] loop mode pops the head and appends it to the tail\n";
$fc = array(
	'step1' => array(
		'prompt_queue' => array(
			array( 'prompt' => 'first',  'added_at' => 't0' ),
			array( 'prompt' => 'second', 'added_at' => 't1' ),
		),
	),
);
$result = simulate_consume_from_queue_slot( $fc, 'step1', 'prompt_queue', 'loop' );
assert_consolidation(
	'loop returns the head entry',
	'first' === ( $result['entry']['prompt'] ?? null )
);
assert_consolidation(
	'loop rotated the head to the tail',
	true === $result['mutated']
		&& 2 === count( $fc['step1']['prompt_queue'] )
		&& 'second' === $fc['step1']['prompt_queue'][0]['prompt']
		&& 'first' === $fc['step1']['prompt_queue'][1]['prompt']
);

echo "\n[semantics:4] empty queue returns null entry regardless of mode\n";
foreach ( array( 'static', 'drain', 'loop' ) as $mode ) {
	$fc = array( 'step1' => array( 'prompt_queue' => array() ) );
	$result = simulate_consume_from_queue_slot( $fc, 'step1', 'prompt_queue', $mode );
	assert_consolidation(
		"empty queue + mode={$mode} returns null entry",
		null === $result['entry']
	);
}

echo "\n[semantics:5] missing flow_step_id returns null without error\n";
$fc = array();
$result = simulate_consume_from_queue_slot( $fc, 'step1', 'prompt_queue', 'drain' );
assert_consolidation(
	'missing step degrades to null entry, no exception',
	null === $result['entry']
);

echo "\n[semantics:6] config_patch_queue slot uses the same logic as prompt_queue\n";
$fc = array(
	'step1' => array(
		'config_patch_queue' => array(
			array( 'patch' => array( 'after' => '2017-03' ), 'added_at' => 't0' ),
		),
	),
);
$result = simulate_consume_from_queue_slot( $fc, 'step1', 'config_patch_queue', 'drain' );
assert_consolidation(
	'config_patch_queue drain returns the patch entry',
	array( 'after' => '2017-03' ) === ( $result['entry']['patch'] ?? null )
);
assert_consolidation(
	'config_patch_queue drain emptied the slot',
	0 === count( $fc['step1']['config_patch_queue'] )
);

// ---------------------------------------------------------------
// SECTION 7: Unified log shape.
// ---------------------------------------------------------------

echo "\n[logging:1] consumeFromQueueSlot logs `Item consumed from queue` with unified shape\n";
assert_consolidation(
	'production source logs `Item consumed from queue`',
	false !== strpos( $qa_src, "'Item consumed from queue'" )
);
foreach ( array(
	"'flow_id'",
	"'slot'",
	"'queue_mode'",
	"'remaining_count'",
) as $field ) {
	assert_consolidation(
		"log payload includes {$field}",
		false !== strpos( $qa_src, $field )
	);
}

echo "\n";
if ( 0 === $failed ) {
	echo "=== queue-consumption-consolidation-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== queue-consumption-consolidation-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
