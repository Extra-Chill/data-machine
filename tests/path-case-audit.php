<?php
/**
 * Fails when two tracked paths differ only by letter case.
 *
 * Case-insensitive filesystems (macOS) collapse such paths into one directory
 * while Linux keeps them distinct, so checkouts and copied snapshots disagree.
 *
 * @package DataMachine\Tests
 */

$root   = dirname( __DIR__ );
$output = array();
$status = 0;
exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files', $output, $status );
if ( 0 !== $status ) {
	fwrite( STDERR, "git ls-files failed.\n" );
	exit( 1 );
}

$spellings = array();
foreach ( $output as $tracked ) {
	$path = '';
	foreach ( explode( '/', $tracked ) as $segment ) {
		$path = '' === $path ? $segment : $path . '/' . $segment;
		$spellings[ strtolower( $path ) ][ $path ] = true;
	}
}

$collisions = array_filter( $spellings, static fn( array $variants ): bool => count( $variants ) > 1 );
if ( array() !== $collisions ) {
	fwrite( STDERR, "Tracked paths differ only by case:\n" );
	foreach ( $collisions as $variants ) {
		fwrite( STDERR, '- ' . implode( ' vs ', array_keys( $variants ) ) . "\n" );
	}
	exit( 1 );
}

echo 'Path case audit passed (' . count( $output ) . " tracked files).\n";
