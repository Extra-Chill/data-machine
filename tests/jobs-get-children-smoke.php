<?php
/**
 * Pure-PHP smoke test for Jobs::get_children.
 *
 * Run with: php tests/jobs-get-children-smoke.php
 *
 * Verifies the row-shaping logic that JobsOperations::get_children
 * applies to wpdb->get_results output:
 *   - Empty result set → empty array.
 *   - Each row's engine_data is JSON-decoded into an array.
 *   - Rows with malformed engine_data degrade to an empty array
 *     (so callers don't have to defend against json_decode == null).
 *   - Rows with no engine_data column degrade to an empty array.
 *   - Order returned by wpdb is preserved (we sort ASC by job_id in SQL).
 *
 * The full live path requires a real wpdb + jobs table, so this smoke
 * isolates the post-query row-mutation block in a harness that mirrors
 * the production code's foreach byte-for-byte.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Mirror of the row-shaping foreach inside JobsOperations::get_children.
 * Kept literally byte-equivalent so any drift surfaces as a diff between
 * this harness and the source.
 */
function shape_children_rows( array $rows ): array {
	if ( empty( $rows ) ) {
		return array();
	}

	foreach ( $rows as &$row ) {
		if ( isset( $row['engine_data'] ) && is_string( $row['engine_data'] ) && '' !== $row['engine_data'] ) {
			$decoded = json_decode( $row['engine_data'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$row['engine_data'] = $decoded;
			} else {
				$row['engine_data'] = array();
			}
		} else {
			$row['engine_data'] = array();
		}
	}
	unset( $row );

	return $rows;
}

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "=== jobs-get-children-smoke ===\n";

// -----------------------------------------------------------------
echo "\n[1] empty result set → empty array\n";
$out = shape_children_rows( array() );
dm_assert( array() === $out, 'empty rows return empty array' );

// -----------------------------------------------------------------
echo "\n[2] two children, engine_data decoded, order preserved\n";
$rows = array(
	array(
		'job_id'        => 65,
		'parent_job_id' => 64,
		'status'        => 'completed',
		'engine_data'   => '{"task_type":"alt_text","effects":[{"type":"post_meta_set","target":{"post_id":1,"meta_key":"_alt_text"}}]}',
	),
	array(
		'job_id'        => 66,
		'parent_job_id' => 64,
		'status'        => 'completed',
		'engine_data'   => '{"task_type":"alt_text","effects":[{"type":"post_meta_set","target":{"post_id":2,"meta_key":"_alt_text"}}]}',
	),
);

$out = shape_children_rows( $rows );

dm_assert( 2 === count( $out ), 'two rows returned' );
dm_assert( 65 === $out[0]['job_id'], 'first row is job_id=65 (ASC order preserved)' );
dm_assert( 66 === $out[1]['job_id'], 'second row is job_id=66 (ASC order preserved)' );
dm_assert( is_array( $out[0]['engine_data'] ), 'first row engine_data decoded to array' );
dm_assert( is_array( $out[1]['engine_data'] ), 'second row engine_data decoded to array' );
dm_assert( 'alt_text' === $out[0]['engine_data']['task_type'], 'task_type accessible after decode' );
dm_assert( 1 === $out[0]['engine_data']['effects'][0]['target']['post_id'], 'nested effect target accessible' );
dm_assert( 2 === $out[1]['engine_data']['effects'][0]['target']['post_id'], 'second child distinct from first' );

// -----------------------------------------------------------------
echo "\n[3] malformed JSON → engine_data degrades to empty array\n";
$rows = array(
	array(
		'job_id'        => 70,
		'parent_job_id' => 64,
		'engine_data'   => '{not valid json',
	),
);

$out = shape_children_rows( $rows );

dm_assert( 1 === count( $out ), 'one row returned' );
dm_assert( array() === $out[0]['engine_data'], 'malformed JSON degrades to empty array (not null, not the raw string)' );

// -----------------------------------------------------------------
echo "\n[4] empty string engine_data → empty array\n";
$rows = array(
	array(
		'job_id'        => 71,
		'parent_job_id' => 64,
		'engine_data'   => '',
	),
);

$out = shape_children_rows( $rows );
dm_assert( array() === $out[0]['engine_data'], 'empty string engine_data → empty array' );

// -----------------------------------------------------------------
echo "\n[5] missing engine_data key → empty array (defensive)\n";
$rows = array(
	array(
		'job_id'        => 72,
		'parent_job_id' => 64,
	),
);

$out = shape_children_rows( $rows );
dm_assert( array() === $out[0]['engine_data'], 'missing engine_data key → empty array' );

// -----------------------------------------------------------------
echo "\n[6] mixed children — some with effects, some without (fan-out reality)\n";
// Real-world fan-out shape: SystemTask::undo() walks children, merges
// effects[] across them. A child that completed without producing
// effects (e.g. skipped because the post had no images) must not break
// the consumer's effects-merge loop.
$rows = array(
	array(
		'job_id'        => 80,
		'parent_job_id' => 79,
		'engine_data'   => '{"effects":[{"type":"post_meta_set"}]}',
	),
	array(
		'job_id'        => 81,
		'parent_job_id' => 79,
		'engine_data'   => '{"task_type":"alt_text"}',
	),
	array(
		'job_id'        => 82,
		'parent_job_id' => 79,
		'engine_data'   => '{"effects":[{"type":"post_field_set"},{"type":"attachment_created"}]}',
	),
);

$out      = shape_children_rows( $rows );
$effects  = array();
foreach ( $out as $child ) {
	$child_effects = $child['engine_data']['effects'] ?? array();
	$effects       = array_merge( $effects, $child_effects );
}

dm_assert( 3 === count( $out ), 'three children shaped' );
dm_assert( 3 === count( $effects ), 'merged effects from children = 3 (1 from #80, 0 from #81, 2 from #82)' );
dm_assert( 'post_meta_set' === $effects[0]['type'], 'first effect from job #80' );
dm_assert( 'post_field_set' === $effects[1]['type'], 'second effect from job #82 (in order)' );
dm_assert( 'attachment_created' === $effects[2]['type'], 'third effect from job #82 (in order)' );

echo "\n=== jobs-get-children-smoke: ALL PASS ===\n";
