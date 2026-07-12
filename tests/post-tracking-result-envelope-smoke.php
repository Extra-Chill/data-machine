<?php
/**
 * Smoke test for post ID extraction from tool result envelopes.
 *
 * @package DataMachine
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/inc/Core/WordPress/PostTracking.php';

use DataMachine\Core\WordPress\PostTracking;

$cases = array(
	'data.post_id'        => array( array( 'data' => array( 'post_id' => 42 ) ), 42 ),
	'post_id'             => array( array( 'post_id' => 99 ), 99 ),
	'result.post_id'      => array( array( 'result' => array( 'post_id' => 123 ) ), 123 ),
	'result.data.post_id' => array( array( 'result' => array( 'data' => array( 'post_id' => 456 ) ) ), 456 ),
	'empty result'        => array( array(), 0 ),
	'zero nested ID'      => array( array( 'result' => array( 'data' => array( 'post_id' => 0 ) ) ), 0 ),
);

foreach ( $cases as $label => [ $result, $expected ] ) {
	$actual = PostTracking::extractPostId( $result );
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "%s: expected %d, got %d\n", $label, $expected, $actual ) );
		exit( 1 );
	}
}

echo "Post tracking result envelope smoke test passed.\n";
