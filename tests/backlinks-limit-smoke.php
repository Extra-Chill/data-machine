<?php
/**
 * Smoke tests for bounded backlink ability responses.
 *
 * Runs via:
 *
 *   php tests/backlinks-limit-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['datamachine_permalink_calls'] = array();
$GLOBALS['datamachine_link_graph']      = array(
	'post_type'    => 'wiki',
	'_id_to_title' => array(
		11 => 'Most linked',
		22 => 'Least linked',
		33 => 'Second linked',
	),
	'_all_links'   => array(
		array( 'source_id' => 11, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 11, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 11, 'target_id' => 100, 'edge_type' => 'html_anchor' ),
		array( 'source_id' => 22, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 33, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 33, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 44, 'target_id' => 999, 'edge_type' => 'wikilink' ),
	),
);

function absint( $value ): int {
	return max( 0, (int) $value );
}

function sanitize_text_field( $value ): string {
	return trim( (string) $value );
}

function sanitize_key( $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}

function get_transient( string $_key ) {
	return $GLOBALS['datamachine_link_graph'];
}

function get_permalink( $post_id ) {
	$GLOBALS['datamachine_permalink_calls'][] = (int) $post_id;
	return 'https://example.test/wiki/' . (int) $post_id . '/';
}

function datamachine_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/inc/Abilities/InternalLinkingAbilities.php';

echo "Backlinks limit smoke\n";

$result = DataMachine\Abilities\InternalLinkingAbilities::getBacklinks(
	array(
		'post_id'   => 100,
		'post_type' => 'wiki',
		'limit'     => 2,
	)
);

datamachine_assert( true === $result['success'], 'limited backlink lookup succeeds' );
datamachine_assert( 3 === $result['backlink_count'], 'total backlink source count is preserved' );
datamachine_assert( 2 === count( $result['backlinks'] ), 'returned backlinks are limited' );
datamachine_assert( 11 === $result['backlinks'][0]['source_id'], 'highest link-count source is first' );
datamachine_assert( 33 === $result['backlinks'][1]['source_id'], 'second highest source is second' );
datamachine_assert( array( 11, 33 ) === $GLOBALS['datamachine_permalink_calls'], 'only returned sources are permalink-hydrated' );

$GLOBALS['datamachine_permalink_calls'] = array();
$filtered                             = DataMachine\Abilities\InternalLinkingAbilities::getBacklinks(
	array(
		'post_id'   => 100,
		'post_type' => 'wiki',
		'types'     => array( 'html_anchor' ),
		'limit'     => 2,
	)
);

datamachine_assert( 1 === $filtered['backlink_count'], 'type filtering still affects the total count' );
datamachine_assert( 1 === count( $filtered['backlinks'] ), 'type filtering still limits returned rows' );
datamachine_assert( 11 === $filtered['backlinks'][0]['source_id'], 'type-filtered source is returned' );
datamachine_assert( array( 11 ) === $GLOBALS['datamachine_permalink_calls'], 'type-filtered lookup hydrates only returned rows' );

echo "Backlinks limit smoke passed.\n";
