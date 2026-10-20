<?php
/**
 * Fails when two tracked paths differ only by letter case.
 *
 * Case-insensitive filesystems (macOS) collapse such paths into one directory
 * while Linux keeps them distinct, so checkouts and copied snapshots disagree.
 *
 * @package DataMachine\Tests
 */

$audit_root     = dirname( __DIR__ );
$tracked_files  = array();
$ls_files_exit  = 0;
$path_spellings = array();

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- CLI-only repository audit reads the Git index.
exec( 'git -C ' . escapeshellarg( $audit_root ) . ' ls-files', $tracked_files, $ls_files_exit );
if ( 0 !== $ls_files_exit ) {
	echo "git ls-files failed.\n";
	exit( 1 );
}

foreach ( $tracked_files as $tracked_file ) {
	$prefix = '';
	foreach ( explode( '/', $tracked_file ) as $segment ) {
		$prefix = '' === $prefix ? $segment : $prefix . '/' . $segment;
		$path_spellings[ strtolower( $prefix ) ][ $prefix ] = true;
	}
}

$collisions = array_filter( $path_spellings, static fn( array $variants ): bool => count( $variants ) > 1 );
if ( array() !== $collisions ) {
	echo "Tracked paths differ only by case:\n";
	foreach ( $collisions as $variants ) {
		echo '- ' . implode( ' vs ', array_keys( $variants ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output of repository paths.
	}
	exit( 1 );
}

echo 'Path case audit passed (' . count( $tracked_files ) . " tracked files).\n";
