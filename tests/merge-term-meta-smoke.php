<?php
/**
 * Pure-PHP smoke test for MergeTermMetaAbility (#1164 follow-up).
 *
 * Run with: php tests/merge-term-meta-smoke.php
 *
 * Exercises the per-key decision logic in execute() — given a (data, field_map,
 * strategy, existing_meta) tuple, decide for each data_key:
 *
 *   - skip: missing from data, empty value, or strategy=fill_empty + already populated
 *   - update: write the sanitised value via update_term_meta
 *
 * The production code in inc/Abilities/Taxonomy/MergeTermMetaAbility.php
 * embeds the decision inside execute(); this test reimplements just the
 * decision so we can verify the logic without booting WordPress.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

const STRATEGY_FILL_EMPTY = 'fill_empty';
const STRATEGY_OVERWRITE  = 'overwrite';

/**
 * Inline re-implementation of the per-key decision in
 * MergeTermMetaAbility::execute(). Returns ['updated' => string[],
 * 'skipped' => string[]].
 */
function dm_test_merge_decide( array $data, array $field_map, string $strategy, array $existing_meta ): array {
	$updated = array();
	$skipped = array();

	foreach ( $field_map as $data_key => $meta_key ) {
		$data_key = (string) $data_key;
		$meta_key = (string) $meta_key;

		if ( '' === $data_key || '' === $meta_key ) {
			continue;
		}

		if ( ! array_key_exists( $data_key, $data ) ) {
			$skipped[] = $data_key;
			continue;
		}

		$incoming = $data[ $data_key ];
		if ( null === $incoming || '' === $incoming || ( is_array( $incoming ) && empty( $incoming ) ) ) {
			$skipped[] = $data_key;
			continue;
		}

		if ( STRATEGY_FILL_EMPTY === $strategy ) {
			$existing = $existing_meta[ $meta_key ] ?? '';
			if ( '' !== $existing && null !== $existing && array() !== $existing ) {
				$skipped[] = $data_key;
				continue;
			}
		}

		$updated[] = $data_key;
	}

	return array(
		'updated' => array_values( array_unique( $updated ) ),
		'skipped' => array_values( array_unique( $skipped ) ),
	);
}

$failures = array();

$promoter_field_map = array(
	'url'  => '_promoter_url',
	'type' => '_promoter_type',
);

$venue_field_map = array(
	'address'     => '_venue_address',
	'city'        => '_venue_city',
	'state'       => '_venue_state',
	'coordinates' => '_venue_coordinates',
);

// Case 1: fill_empty, all existing meta empty, all data present → all updated.
$out = dm_test_merge_decide(
	array( 'url' => 'https://example.com', 'type' => 'Organization' ),
	$promoter_field_map,
	STRATEGY_FILL_EMPTY,
	array()
);
if ( $out['updated'] !== array( 'url', 'type' ) ) {
	$failures[] = 'fill_empty + empty meta should update all: ' . var_export( $out, true );
}

// Case 2: fill_empty, url already populated → url skipped, type updated.
$out = dm_test_merge_decide(
	array( 'url' => 'https://NEW.com', 'type' => 'Person' ),
	$promoter_field_map,
	STRATEGY_FILL_EMPTY,
	array( '_promoter_url' => 'https://existing.com' )
);
if ( $out['updated'] !== array( 'type' ) || ! in_array( 'url', $out['skipped'], true ) ) {
	$failures[] = 'fill_empty should skip populated keys: ' . var_export( $out, true );
}

// Case 3: overwrite, url already populated → url still updated.
$out = dm_test_merge_decide(
	array( 'url' => 'https://NEW.com', 'type' => 'Person' ),
	$promoter_field_map,
	STRATEGY_OVERWRITE,
	array( '_promoter_url' => 'https://existing.com' )
);
if ( $out['updated'] !== array( 'url', 'type' ) ) {
	$failures[] = 'overwrite should write populated keys: ' . var_export( $out, true );
}

// Case 4: data has no value for a mapped key → skipped.
$out = dm_test_merge_decide(
	array( 'url' => 'https://example.com' ),
	$promoter_field_map,
	STRATEGY_FILL_EMPTY,
	array()
);
if ( $out['updated'] !== array( 'url' ) || $out['skipped'] !== array( 'type' ) ) {
	$failures[] = 'missing data key should be skipped: ' . var_export( $out, true );
}

// Case 5: empty-string incoming → skipped even with overwrite.
$out = dm_test_merge_decide(
	array( 'url' => '', 'type' => 'Organization' ),
	$promoter_field_map,
	STRATEGY_OVERWRITE,
	array( '_promoter_url' => 'https://existing.com' )
);
if ( in_array( 'url', $out['updated'], true ) ) {
	$failures[] = 'empty-string incoming should never update: ' . var_export( $out, true );
}
if ( ! in_array( 'url', $out['skipped'], true ) ) {
	$failures[] = 'empty-string incoming should be in skipped: ' . var_export( $out, true );
}

// Case 6: keys not in field_map are silently ignored.
$out = dm_test_merge_decide(
	array( 'url' => 'https://example.com', 'evil' => 'pwn' ),
	$promoter_field_map,
	STRATEGY_FILL_EMPTY,
	array()
);
if ( in_array( 'evil', $out['updated'], true ) || in_array( 'evil', $out['skipped'], true ) ) {
	$failures[] = 'unmapped data keys should not appear in result: ' . var_export( $out, true );
}

// Case 7: Venue partial update — only coordinates incoming on a venue with
// every address field already populated. fill_empty leaves address alone,
// writes coordinates.
$out = dm_test_merge_decide(
	array( 'coordinates' => '32.78,-79.93' ),
	$venue_field_map,
	STRATEGY_FILL_EMPTY,
	array(
		'_venue_address' => '123 Main St',
		'_venue_city'    => 'Charleston',
		'_venue_state'   => 'SC',
	)
);
if ( $out['updated'] !== array( 'coordinates' ) ) {
	$failures[] = 'venue partial update should only write coordinates: ' . var_export( $out, true );
}

// Case 8: Venue create-time write (overwrite) populates everything.
$out = dm_test_merge_decide(
	array(
		'address'     => '123 Main St',
		'city'        => 'Charleston',
		'state'       => 'SC',
		'coordinates' => '32.78,-79.93',
	),
	$venue_field_map,
	STRATEGY_OVERWRITE,
	array()
);
if ( count( $out['updated'] ) !== 4 ) {
	$failures[] = 'venue create-time overwrite should write all four: ' . var_export( $out, true );
}

if ( ! empty( $failures ) ) {
	echo "FAIL\n";
	foreach ( $failures as $f ) {
		echo "  - $f\n";
	}
	exit( 1 );
}

echo "PASS merge-term-meta smoke (8/8 cases)\n";
exit( 0 );
