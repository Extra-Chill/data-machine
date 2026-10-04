<?php
/** Optional real ffmpeg/ffprobe render smoke. */
declare( strict_types = 1 );

foreach ( array( 'ffmpeg', 'ffprobe' ) as $binary ) {
	$found = array();
	exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null', $found, $status );
	if ( 0 !== $status || empty( $found ) ) {
		echo "SKIP: ffmpeg and ffprobe are required for render integration.\n";
		exit( 0 );
	}
}
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/inc/Abilities/Media/VideoRenderPlan.php';
$dir = sys_get_temp_dir() . '/datamachine-render-' . bin2hex( random_bytes( 5 ) );
if ( ! mkdir( $dir, 0700 ) ) {
	fwrite( STDERR, "Unable to create render smoke directory.\n" );
	exit( 1 );
}
$paths = array( $dir . '/one.mp4', $dir . '/still.jpg', $dir . '/three.mp4', $dir . '/rendered.mp4' );
$commands = array(
	'ffmpeg -v error -f lavfi -i color=c=red:s=320x240:d=1 -an -c:v libx264 -pix_fmt yuv420p -y ' . escapeshellarg( $paths[0] ),
	'ffmpeg -v error -f lavfi -i color=c=green:s=320x240 -frames:v 1 -y ' . escapeshellarg( $paths[1] ),
	'ffmpeg -v error -f lavfi -i color=c=blue:s=320x240:d=1 -an -c:v libx264 -pix_fmt yuv420p -y ' . escapeshellarg( $paths[2] ),
);
foreach ( $commands as $command ) {
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		fwrite( STDERR, "Failed to generate render smoke input.\n" );
	exit( 1 );
	}
}
$spec = array( 'segments' => array(
	array( 'kind' => 'video', 'in_ms' => 0, 'out_ms' => 1000 ),
	array( 'kind' => 'image', 'duration_ms' => 1000 ),
	array( 'kind' => 'video', 'in_ms' => 0, 'out_ms' => 1000 ),
), 'output' => array( 'width' => 180, 'height' => 320, 'fps' => 10 ) );
$plan = \DataMachine\Abilities\Media\VideoRenderPlan::build( $spec, array( $paths[0], $paths[1], $paths[2] ) );
$arguments = array_map( 'escapeshellarg', $plan['arguments'] );
$command = 'ffmpeg ' . implode( ' ', $arguments ) . ' ' . escapeshellarg( $paths[3] ) . ' 2>&1';
exec( $command, $output, $status );
if ( 0 !== $status ) {
	fwrite( STDERR, "ffmpeg render failed: " . implode( "\n", $output ) . "\n" );
	exit( 1 );
}
$probe = array();
exec( 'ffprobe -v error -show_entries format=duration:stream=width,height -of json ' . escapeshellarg( $paths[3] ), $probe, $status );
$metadata = json_decode( implode( "\n", $probe ), true );
foreach ( $paths as $path ) {
	@unlink( $path );
}
@rmdir( $dir );
$stream = $metadata['streams'][0] ?? array();
$duration = (float) ( $metadata['format']['duration'] ?? 0 );
if ( 0 !== $status || 180 !== (int) ( $stream['width'] ?? 0 ) || 320 !== (int) ( $stream['height'] ?? 0 ) || $duration < 2.7 || $duration > 3.4 ) {
	fwrite( STDERR, "Rendered output did not match expected dimensions and duration: " . json_encode( array( 'status' => $status, 'metadata' => $metadata ) ) . "\n" );
	exit( 1 );
}
echo "PASS: rendered three segments at 180x320 for approximately 3 seconds.\n";
