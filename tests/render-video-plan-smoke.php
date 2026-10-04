<?php
/** Pure video render-plan contract smoke. */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/inc/Abilities/Media/VideoRenderPlan.php';

$spec = array(
	'segments' => array(
		array( 'source' => 1, 'kind' => 'video', 'in_ms' => 1000, 'out_ms' => 2000 ),
		array( 'source' => 2, 'kind' => 'image', 'duration_ms' => 1000, 'motion' => 'zoom_in' ),
		array( 'source' => 3, 'kind' => 'video', 'in_ms' => 500, 'out_ms' => 1500, 'motion' => 'zoom_out' ),
	),
	'captions' => array( array( 'text' => 'Hello', 'start_ms' => 500, 'end_ms' => 1500, 'position' => 'top' ) ),
	'audio' => 4,
);
$plan = \DataMachine\Abilities\Media\VideoRenderPlan::build( $spec, array( '/tmp/one.mp4', '/tmp/two.jpg', '/tmp/three.mp4', 'audio' => '/tmp/audio.wav' ) );
$args = implode( ' ', $plan['arguments'] );
$checks = array(
	'segment input order' => strpos( $args, '/tmp/one.mp4' ) < strpos( $args, '/tmp/two.jpg' ) && strpos( $args, '/tmp/two.jpg' ) < strpos( $args, '/tmp/three.mp4' ),
	'video trim offsets' => strpos( $args, '-ss 1.000 -t 1.000' ) !== false && strpos( $args, '-ss 0.500 -t 1.000' ) !== false,
	'image motion and duration' => strpos( $args, 'zoom+0.0015' ) !== false && strpos( $args, 'zoom-0.0015' ) !== false && strpos( $args, '-loop 1 -t 1.000' ) !== false && strpos( $args, "':d=1:" ) !== false,
	'cover crop to default portrait frame' => strpos( $args, 'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920' ) !== false,
	'audio trim and fade' => strpos( $args, 'atrim=duration=3.000,afade=' ) !== false,
	'ASS caption timing and position' => strpos( $plan['captions_ass'], '0:00:00.50,0:00:01.50' ) !== false && strpos( $plan['captions_ass'], '{\\an8}Hello' ) !== false,
	'planned bounded duration' => 3000 === $plan['duration_ms'],
);
foreach ( $checks as $name => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $name . "\n";
	if ( ! $passed ) {
		exit( 1 );
	}
}
