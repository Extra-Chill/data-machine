<?php
/**
 * Pure-PHP smoke test for PipelineBatchScheduler agent_id carry-over.
 *
 * Run with: php tests/batch-child-agent-id-smoke.php
 *
 * Verifies that the engine_data shape produced by createChildJob carries
 * agent_id and user_id from the parent into the child. Without this,
 * downstream consumers (CoreMemoryFilesDirective, model resolution,
 * permission scoping) fall back to the user_id default-agent lookup
 * and the child runs under the wrong agent's identity.
 *
 * The full createChildJob path requires a DB + Action Scheduler, so this
 * smoke isolates the engine_data construction logic via a harness that
 * mirrors the production code's array shape.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, $gmt = 0 ): string {
		return '2026-04-24 22:00:00';
	}
}

/**
 * Mirror of PipelineBatchScheduler::createChildJob's engine_data
 * construction. Exposes just the array-building logic so we can assert
 * agent_id and user_id flow through correctly.
 *
 * Kept literally byte-equivalent to the production code path so any drift
 * surfaces as a diff between this harness and the source.
 */
function build_child_engine_data(
	array $engine_snapshot,
	int $child_job_id,
	int $parent_job_id,
	?string $pipeline_id,
	?string $flow_id
): array {
	$parent_agent_id = (int) ( $engine_snapshot['job']['agent_id'] ?? 0 );
	$parent_user_id  = (int) ( $engine_snapshot['job']['user_id'] ?? 0 );

	$child_engine        = $engine_snapshot;
	$child_engine['job'] = array(
		'job_id'        => $child_job_id,
		'flow_id'       => $flow_id,
		'pipeline_id'   => $pipeline_id,
		'agent_id'      => $parent_agent_id > 0 ? $parent_agent_id : null,
		'user_id'       => $parent_user_id > 0 ? $parent_user_id : null,
		'created_at'    => current_time( 'mysql', true ),
		'parent_job_id' => $parent_job_id,
	);

	return $child_engine;
}

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "=== batch-child-agent-id-smoke ===\n";

// -----------------------------------------------------------------
echo "\n[1] parent has agent_id + user_id — both carry through to child\n";
$parent_snapshot = array(
	'job' => array(
		'job_id'      => 64,
		'flow_id'     => '2',
		'pipeline_id' => '2',
		'user_id'     => 1,
		'agent_id'    => 2,
		'created_at'  => '2026-04-24 22:00:00',
	),
	'flow_config'     => array( /* ... */ ),
	'pipeline_config' => array( /* ... */ ),
);

$child = build_child_engine_data( $parent_snapshot, 65, 64, '2', '2' );

dm_assert( 65 === $child['job']['job_id'], 'child job_id replaces parent' );
dm_assert( 64 === $child['job']['parent_job_id'], 'parent_job_id set' );
dm_assert( 2 === $child['job']['agent_id'], 'agent_id carried from parent' );
dm_assert( 1 === $child['job']['user_id'], 'user_id carried from parent' );
dm_assert( '2' === $child['job']['pipeline_id'], 'pipeline_id preserved' );
dm_assert( '2' === $child['job']['flow_id'], 'flow_id preserved' );
dm_assert( isset( $child['flow_config'] ), 'engine_snapshot keys preserved (flow_config)' );
dm_assert( isset( $child['pipeline_config'] ), 'engine_snapshot keys preserved (pipeline_config)' );

// -----------------------------------------------------------------
echo "\n[2] parent without agent_id — child gets null (not 0, not garbage)\n";
$parent_snapshot = array(
	'job' => array(
		'job_id'      => 100,
		'flow_id'     => '5',
		'pipeline_id' => '3',
		'user_id'     => 1,
		'created_at'  => '2026-04-24 22:00:00',
	),
);

$child = build_child_engine_data( $parent_snapshot, 101, 100, '3', '5' );

dm_assert( null === $child['job']['agent_id'], 'agent_id is null when absent on parent' );
dm_assert( 1 === $child['job']['user_id'], 'user_id still carried' );

// -----------------------------------------------------------------
echo "\n[3] parent with agent_id=0 — treated as absent (null on child)\n";
$parent_snapshot = array(
	'job' => array(
		'job_id'   => 200,
		'agent_id' => 0,
		'user_id'  => 1,
	),
);

$child = build_child_engine_data( $parent_snapshot, 201, 200, '1', '1' );

dm_assert( null === $child['job']['agent_id'], 'agent_id=0 on parent → null on child' );

// -----------------------------------------------------------------
echo "\n[4] parent with no user_id — null carried forward\n";
$parent_snapshot = array(
	'job' => array(
		'job_id'   => 300,
		'agent_id' => 2,
	),
);

$child = build_child_engine_data( $parent_snapshot, 301, 300, '1', '1' );

dm_assert( 2 === $child['job']['agent_id'], 'agent_id carried' );
dm_assert( null === $child['job']['user_id'], 'user_id null when absent' );

// -----------------------------------------------------------------
echo "\n[5] empty parent job — child has minimal context\n";
$parent_snapshot = array(
	'job' => array(),
);

$child = build_child_engine_data( $parent_snapshot, 401, 400, '1', '1' );

dm_assert( null === $child['job']['agent_id'], 'agent_id null' );
dm_assert( null === $child['job']['user_id'], 'user_id null' );
dm_assert( 401 === $child['job']['job_id'], 'job_id still set' );
dm_assert( 400 === $child['job']['parent_job_id'], 'parent_job_id still set' );

// -----------------------------------------------------------------
echo "\n[6] real-world WooCommerce flow shape (job 64 → child 65 from session)\n";
// The actual scenario that surfaced this bug.
$parent_snapshot = array(
	'job'             => array(
		'job_id'      => 64,
		'flow_id'     => 2,
		'pipeline_id' => 2,
		'user_id'     => 0,
		'created_at'  => '2026-04-24 22:21:27',
		'agent_id'    => 2,
	),
	'flow'            => array( 'name' => 'WC backfill — mgs (queueable test)' ),
	'pipeline'        => array( 'name' => 'wiki-generator: WooCommerce releases' ),
	'flow_config'     => array( 'fetch' => 'mcp', 'ai' => 'rubric', 'upsert' => 'wiki_upsert' ),
	'pipeline_config' => array( 'system_prompt' => '...' ),
);

$child = build_child_engine_data( $parent_snapshot, 65, 64, '2', '2' );

// The bug: BEFORE the fix, child['job']['agent_id'] was missing entirely,
// causing CoreMemoryFilesDirective to fall back to the user_id default-agent
// lookup which returns the WRONG agent (the install's primary agent, not
// the wiki-generator the pipeline is bound to).
dm_assert( 2 === $child['job']['agent_id'], 'wiki-generator agent_id (2) carried to child' );
dm_assert( 64 === $child['job']['parent_job_id'], 'parent linkage preserved' );
dm_assert( 'wiki-generator: WooCommerce releases' === $child['pipeline']['name'], 'pipeline metadata preserved on child' );
dm_assert( 'WC backfill — mgs (queueable test)' === $child['flow']['name'], 'flow metadata preserved on child' );

echo "\n=== batch-child-agent-id-smoke: ALL PASS ===\n";
