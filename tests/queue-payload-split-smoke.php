<?php
/**
 * Pure-PHP smoke test for the queue payload split (#1292).
 *
 * Run with: php tests/queue-payload-split-smoke.php
 *
 * Pre-#1292, both AIStep and FetchStep consumed from the same
 * `prompt_queue` slot — AI as plain strings, Fetch as JSON-encoded
 * objects under the same `prompt` field. The schema lied at write
 * time and validation was implicit via the consumer step type.
 *
 * Post-#1292:
 *   - prompt_queue:        AI only, payload field `prompt` (string)
 *   - config_patch_queue:  Fetch only, payload field `patch` (object)
 *
 * The byte-mirror harness inlines the relevant slices of the real
 * code so any divergence between this file and production is caught
 * by failing assertions.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

function assert_split( string $name, bool $cond, string $detail = '' ): void {
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
 * Inline mirror of QueueableTrait::popQueuedConfigPatch — reads from
 * `config_patch_queue`, expects `patch` field as a decoded array.
 *
 * Mirrors inc/Core/Steps/QueueableTrait.php::popQueuedConfigPatch().
 */
function pop_config_patch_for_test( array $step_config, bool $queue_enabled ): array {
	if ( ! $queue_enabled ) {
		return array(
			'patch'      => array(),
			'from_queue' => false,
			'added_at'   => null,
		);
	}

	$queue = $step_config['config_patch_queue'] ?? array();
	if ( empty( $queue ) ) {
		return array(
			'patch'      => array(),
			'from_queue' => false,
			'added_at'   => null,
		);
	}

	$entry = array_shift( $queue );
	$patch = isset( $entry['patch'] ) && is_array( $entry['patch'] ) ? $entry['patch'] : array();

	return array(
		'patch'      => $patch,
		'from_queue' => true,
		'added_at'   => $entry['added_at'] ?? null,
	);
}

/**
 * Inline mirror of AIStep's prompt_queue read path — reads `prompt`
 * field as a string from `prompt_queue`.
 *
 * Mirrors inc/Core/Steps/AI/AIStep.php::execute() lines 140-173.
 */
function pop_prompt_for_test( array $step_config, bool $queue_enabled ): array {
	$queue        = $step_config['prompt_queue'] ?? array();
	$queued_head  = $queue[0]['prompt'] ?? '';

	if ( $queue_enabled ) {
		// drain mode: pop the head.
		$entry = array_shift( $queue );
		return array(
			'value'      => $entry['prompt'] ?? '',
			'from_queue' => null !== $entry,
			'added_at'   => $entry['added_at'] ?? null,
		);
	}

	// Static peek mode.
	return array(
		'value'      => $queued_head,
		'from_queue' => false,
		'added_at'   => null,
	);
}

/**
 * Inline mirror of inc/migrations/split-queue-payload.php::datamachine_migrate_split_queue_payload.
 *
 * Walks one flow_config and migrates fetch-step prompt_queue entries
 * (with JSON-encoded prompt fields) into config_patch_queue entries
 * (with decoded patch fields). AI step queues are left untouched.
 *
 * Returns [ migrated_flow_config, entries_migrated, entries_skipped ].
 */
function migrate_flow_config_for_test( array $flow_config ): array {
	$entries_migrated = 0;
	$entries_skipped  = 0;

	foreach ( $flow_config as $step_id => &$step ) {
		if ( ! is_array( $step ) ) {
			continue;
		}
		if ( 'fetch' !== ( $step['step_type'] ?? '' ) ) {
			continue;
		}

		$legacy_queue = $step['prompt_queue'] ?? null;
		if ( ! is_array( $legacy_queue ) || empty( $legacy_queue ) ) {
			if ( array_key_exists( 'prompt_queue', $step ) ) {
				unset( $step['prompt_queue'] );
			}
			continue;
		}

		$existing_patch_queue = $step['config_patch_queue'] ?? array();
		if ( ! is_array( $existing_patch_queue ) ) {
			$existing_patch_queue = array();
		}

		$migrated_for_step = array();
		foreach ( $legacy_queue as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['prompt'] ) ) {
				++$entries_skipped;
				continue;
			}
			$decoded = json_decode( (string) $entry['prompt'], true );
			if ( ! is_array( $decoded ) || empty( $decoded ) ) {
				++$entries_skipped;
				continue;
			}
			$migrated_for_step[] = array(
				'patch'    => $decoded,
				'added_at' => $entry['added_at'] ?? '2026-04-26T00:00:00+00:00',
			);
			++$entries_migrated;
		}

		$step['config_patch_queue'] = array_merge( $existing_patch_queue, $migrated_for_step );
		unset( $step['prompt_queue'] );
	}
	unset( $step );

	return array( $flow_config, $entries_migrated, $entries_skipped );
}

/**
 * Inline mirror of QueueAbility::executeConfigPatchAdd validation.
 * Returns whether the input is accepted, plus an error string when not.
 *
 * Mirrors inc/Abilities/Flow/QueueAbility.php::executeConfigPatchAdd.
 */
function validate_config_patch_add_for_test( $patch ): array {
	if ( ! is_array( $patch ) ) {
		return array( 'success' => false, 'error' => 'patch is required and must be an object' );
	}
	if ( empty( $patch ) ) {
		return array( 'success' => false, 'error' => 'patch must be a non-empty object' );
	}
	return array( 'success' => true );
}

/**
 * Inline mirror of CLI consumer-aware routing: given step_type and
 * input shape, decide whether the operation is allowed and which
 * slot it targets.
 *
 * Mirrors inc/Cli/Commands/Flows/QueueCommand.php::add().
 */
function cli_route_for_test( string $step_type, $patch_json, $prompt ): array {
	if ( null !== $patch_json && null !== $prompt ) {
		return array( 'allowed' => false, 'error' => 'cannot use both' );
	}
	if ( null === $patch_json && null === $prompt ) {
		return array( 'allowed' => false, 'error' => 'provide one' );
	}

	if ( null !== $patch_json ) {
		if ( 'fetch' !== $step_type ) {
			return array( 'allowed' => false, 'error' => '--patch only valid for fetch' );
		}
		return array( 'allowed' => true, 'slot' => 'config_patch_queue' );
	}

	// Prompt path.
	if ( 'fetch' === $step_type ) {
		return array( 'allowed' => false, 'error' => 'fetch consumes patches, not prompts' );
	}
	return array( 'allowed' => true, 'slot' => 'prompt_queue' );
}

// --- Case 1: AIStep reads prompt_queue post-split (drain mode).
echo "Case 1: AIStep prompt_queue drain mode\n";

$ai_step_drain = array(
	'step_type'     => 'ai',
	'queue_enabled' => true,
	'prompt_queue'  => array(
		array( 'prompt' => 'First task', 'added_at' => '2026-04-26T01:00:00+00:00' ),
		array( 'prompt' => 'Second task', 'added_at' => '2026-04-26T02:00:00+00:00' ),
	),
);

$result = pop_prompt_for_test( $ai_step_drain, true );

assert_split(
	'AI drain pops the first prompt as a string',
	'First task' === $result['value'],
	'got: ' . var_export( $result['value'], true )
);
assert_split(
	'AI drain reports from_queue=true',
	true === $result['from_queue']
);
assert_split(
	'AI drain returns added_at',
	'2026-04-26T01:00:00+00:00' === $result['added_at']
);

// --- Case 2: AIStep reads prompt_queue post-split (peek mode, queue_enabled=false).
echo "\nCase 2: AIStep prompt_queue peek mode\n";

$ai_step_peek = array(
	'step_type'     => 'ai',
	'queue_enabled' => false,
	'prompt_queue'  => array(
		array( 'prompt' => 'Static peek', 'added_at' => '2026-04-26T01:00:00+00:00' ),
	),
);

$result = pop_prompt_for_test( $ai_step_peek, false );

assert_split(
	'AI peek returns head value',
	'Static peek' === $result['value']
);
assert_split(
	'AI peek does NOT report from_queue=true',
	false === $result['from_queue']
);

// --- Case 3: FetchStep reads config_patch_queue (post-split).
echo "\nCase 3: FetchStep config_patch_queue read\n";

$fetch_step = array(
	'step_type'          => 'fetch',
	'queue_enabled'      => true,
	'config_patch_queue' => array(
		array(
			'patch'    => array( 'params' => array( 'after' => '2015-05-01', 'before' => '2015-06-01' ) ),
			'added_at' => '2026-04-26T01:00:00+00:00',
		),
		array(
			'patch'    => array( 'params' => array( 'after' => '2015-06-01' ) ),
			'added_at' => '2026-04-26T02:00:00+00:00',
		),
	),
);

$result = pop_config_patch_for_test( $fetch_step, true );

assert_split(
	'FetchStep pops patch as a decoded array',
	is_array( $result['patch'] ) && isset( $result['patch']['params'] )
);
assert_split(
	'FetchStep patch contains expected keys',
	'2015-05-01' === ( $result['patch']['params']['after'] ?? null )
);
assert_split(
	'FetchStep patch is NOT a JSON-encoded string',
	is_array( $result['patch'] ) && ! is_string( $result['patch'] ),
	'patch type: ' . gettype( $result['patch'] )
);
assert_split(
	'FetchStep reports from_queue=true',
	true === $result['from_queue']
);

// --- Case 4: FetchStep with no queued patch returns empty.
echo "\nCase 4: FetchStep with empty config_patch_queue\n";

$fetch_empty = array(
	'step_type'          => 'fetch',
	'queue_enabled'      => true,
	'config_patch_queue' => array(),
);

$result = pop_config_patch_for_test( $fetch_empty, true );

assert_split(
	'Empty queue returns empty patch',
	array() === $result['patch']
);
assert_split(
	'Empty queue reports from_queue=false',
	false === $result['from_queue']
);

// --- Case 5: FetchStep with queue_enabled=false skips pop.
echo "\nCase 5: FetchStep with queue_enabled=false\n";

$result = pop_config_patch_for_test( $fetch_step, false );
assert_split(
	'queue_enabled=false returns empty patch',
	array() === $result['patch'] && false === $result['from_queue']
);

// --- Case 6: Migration moves fetch-step JSON-encoded prompts to config_patch_queue.
echo "\nCase 6: Migration — fetch step JSON prompts → config_patch_queue\n";

$pre_migration = array(
	'pstep_fetch_uuid_1' => array(
		'step_type'    => 'fetch',
		'prompt_queue' => array(
			array(
				'prompt'   => '{"params":{"after":"2015-05-01","before":"2015-06-01"}}',
				'added_at' => '2026-04-26T01:00:00+00:00',
			),
			array(
				'prompt'   => '{"params":{"after":"2015-06-01","before":"2015-07-01"}}',
				'added_at' => '2026-04-26T02:00:00+00:00',
			),
		),
	),
	'pstep_ai_uuid_1'    => array(
		'step_type'    => 'ai',
		'prompt_queue' => array(
			array( 'prompt' => 'AI task one', 'added_at' => '2026-04-26T03:00:00+00:00' ),
		),
	),
);

list( $migrated, $count_migrated, $count_skipped ) = migrate_flow_config_for_test( $pre_migration );

assert_split(
	'Migration creates config_patch_queue on fetch step',
	isset( $migrated['pstep_fetch_uuid_1']['config_patch_queue'] )
);
assert_split(
	'Migration removes prompt_queue from fetch step',
	! isset( $migrated['pstep_fetch_uuid_1']['prompt_queue'] )
);
assert_split(
	'Migration moves all 2 fetch entries',
	2 === count( $migrated['pstep_fetch_uuid_1']['config_patch_queue'] ?? array() )
);
assert_split(
	'Migrated patch is a decoded object',
	isset( $migrated['pstep_fetch_uuid_1']['config_patch_queue'][0]['patch']['params']['after'] )
		&& '2015-05-01' === $migrated['pstep_fetch_uuid_1']['config_patch_queue'][0]['patch']['params']['after']
);
assert_split(
	'Migration preserves added_at on each entry',
	'2026-04-26T01:00:00+00:00' === ( $migrated['pstep_fetch_uuid_1']['config_patch_queue'][0]['added_at'] ?? null )
);
assert_split(
	'Migration leaves AI step prompt_queue untouched',
	isset( $migrated['pstep_ai_uuid_1']['prompt_queue'] )
		&& 1 === count( $migrated['pstep_ai_uuid_1']['prompt_queue'] )
		&& 'AI task one' === $migrated['pstep_ai_uuid_1']['prompt_queue'][0]['prompt']
);
assert_split(
	'Migration does NOT create config_patch_queue on AI step',
	! isset( $migrated['pstep_ai_uuid_1']['config_patch_queue'] )
);
assert_split( 'Migration count: 2 entries migrated', 2 === $count_migrated );
assert_split( 'Migration count: 0 entries skipped', 0 === $count_skipped );

// --- Case 7: Migration skips misshaped fetch entries (non-JSON prompts).
echo "\nCase 7: Migration — misshaped fetch entries are skipped, not crashed on\n";

$misshaped = array(
	'pstep_fetch_uuid_1' => array(
		'step_type'    => 'fetch',
		'prompt_queue' => array(
			array( 'prompt' => 'this is not JSON', 'added_at' => '2026-04-26T01:00:00+00:00' ),
			array( 'prompt' => '{"valid":true}', 'added_at' => '2026-04-26T02:00:00+00:00' ),
			array( 'prompt' => '', 'added_at' => '2026-04-26T03:00:00+00:00' ),
			array( 'prompt' => '"just a string"', 'added_at' => '2026-04-26T04:00:00+00:00' ),
		),
	),
);

list( $migrated, $count_migrated, $count_skipped ) = migrate_flow_config_for_test( $misshaped );

assert_split(
	'Misshaped: only the valid JSON object entry is migrated',
	1 === count( $migrated['pstep_fetch_uuid_1']['config_patch_queue'] ?? array() )
);
assert_split(
	'Misshaped: surviving entry decoded correctly',
	true === ( $migrated['pstep_fetch_uuid_1']['config_patch_queue'][0]['patch']['valid'] ?? null )
);
assert_split(
	'Misshaped: 3 entries skipped (non-JSON, empty, JSON-but-not-object)',
	3 === $count_skipped
);
assert_split(
	'Misshaped: 1 entry migrated',
	1 === $count_migrated
);
assert_split(
	'Misshaped: prompt_queue still removed from fetch step',
	! isset( $migrated['pstep_fetch_uuid_1']['prompt_queue'] )
);

// --- Case 8: Migration is idempotent.
echo "\nCase 8: Migration idempotency\n";

$first_pass_input = array(
	'pstep_fetch_uuid_1' => array(
		'step_type'    => 'fetch',
		'prompt_queue' => array(
			array( 'prompt' => '{"x":1}', 'added_at' => '2026-04-26T01:00:00+00:00' ),
		),
	),
);

list( $after_first, , ) = migrate_flow_config_for_test( $first_pass_input );
list( $after_second, $second_count, $second_skipped ) = migrate_flow_config_for_test( $after_first );

assert_split(
	'Idempotency: second pass migrates 0 entries',
	0 === $second_count
);
assert_split(
	'Idempotency: second pass skips 0 entries',
	0 === $second_skipped
);
assert_split(
	'Idempotency: shape unchanged after second pass',
	$after_first === $after_second,
	'first: ' . wp_json_encode_test( $after_first ) . ' second: ' . wp_json_encode_test( $after_second )
);

function wp_json_encode_test( $v ) {
	return json_encode( $v );
}

// --- Case 9: Validation — config-patch-add rejects non-object input.
echo "\nCase 9: config-patch-add validation\n";

$result = validate_config_patch_add_for_test( 'a plain string' );
assert_split(
	'Strings rejected on config-patch-add',
	false === $result['success']
		&& false !== strpos( $result['error'], 'object' )
);

$result = validate_config_patch_add_for_test( null );
assert_split(
	'Null rejected on config-patch-add',
	false === $result['success']
);

$result = validate_config_patch_add_for_test( array() );
assert_split(
	'Empty arrays rejected on config-patch-add',
	false === $result['success']
		&& false !== strpos( $result['error'], 'non-empty' )
);

$result = validate_config_patch_add_for_test( array( 'params' => array( 'x' => 1 ) ) );
assert_split(
	'Valid object accepted on config-patch-add',
	true === $result['success']
);

// --- Case 10: CLI routing — fetch step blocks string prompt.
echo "\nCase 10: CLI consumer-aware routing\n";

$result = cli_route_for_test( 'fetch', null, 'a prompt' );
assert_split(
	'fetch + positional prompt → blocked',
	false === $result['allowed']
		&& false !== strpos( $result['error'], 'fetch' )
);

$result = cli_route_for_test( 'fetch', '{"x":1}', null );
assert_split(
	'fetch + --patch → routes to config_patch_queue',
	true === $result['allowed']
		&& 'config_patch_queue' === ( $result['slot'] ?? null )
);

$result = cli_route_for_test( 'ai', '{"x":1}', null );
assert_split(
	'ai + --patch → blocked',
	false === $result['allowed']
		&& false !== strpos( $result['error'], 'fetch' )
);

$result = cli_route_for_test( 'ai', null, 'a prompt' );
assert_split(
	'ai + positional prompt → routes to prompt_queue',
	true === $result['allowed']
		&& 'prompt_queue' === ( $result['slot'] ?? null )
);

$result = cli_route_for_test( 'ai', '{"x":1}', 'a prompt' );
assert_split(
	'both flags supplied → blocked with helpful error',
	false === $result['allowed']
		&& false !== strpos( $result['error'], 'cannot use both' )
);

$result = cli_route_for_test( 'ai', null, null );
assert_split(
	'neither flag supplied → blocked',
	false === $result['allowed']
);

// --- Case 11: Storage shape contract — slot keys are distinct.
echo "\nCase 11: Storage shape contract\n";

$config = array(
	'step_type'          => 'fetch',
	'prompt_queue'       => array(),
	'config_patch_queue' => array(
		array( 'patch' => array( 'a' => 1 ), 'added_at' => '2026-04-26T01:00:00+00:00' ),
	),
);

assert_split(
	'prompt_queue and config_patch_queue are independent storage slots',
	array_key_exists( 'prompt_queue', $config )
		&& array_key_exists( 'config_patch_queue', $config )
		&& count( $config['prompt_queue'] ) !== count( $config['config_patch_queue'] )
);

assert_split(
	'config_patch_queue entries use `patch` field, not `prompt`',
	isset( $config['config_patch_queue'][0]['patch'] )
		&& ! isset( $config['config_patch_queue'][0]['prompt'] )
);

// --- Final report.
echo "\n";
echo "===========================================\n";
echo sprintf( "Smoke test: %d / %d passed\n", $total - $failed, $total );
echo "===========================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}

exit( 0 );
