<?php
/**
 * Ensure every standalone or WordPress smoke declares its execution environment.
 *
 * Run with: php tests/test-manifest-contract-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

$root     = dirname( __DIR__ );
$manifest = json_decode( file_get_contents( $root . '/homeboy-test-manifest.json' ), true );

if ( ! is_array( $manifest ) || 'homeboy/test-manifest/v1' !== ( $manifest['schema'] ?? '' ) || ! isset( $manifest['tests'] ) || ! is_array( $manifest['tests'] ) ) {
	fwrite( STDERR, "Invalid Homeboy test manifest.\n" );
	exit( 1 );
}

$expected = array();
$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( $file->isFile() && str_ends_with( $file->getFilename(), '-smoke.php' ) ) {
		$expected[] = 'tests/' . str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( __DIR__ ) + 1 ) );
	}
}
sort( $expected );
$declared = array_keys( $manifest['tests'] );
sort( $declared );

if ( $expected !== $declared ) {
	fwrite( STDERR, "Homeboy test manifest does not classify every smoke test exactly.\n" );
	exit( 1 );
}

$wordpress_smokes = array(
	'tests/data-machine-http-client-contract-smoke.php',
	'tests/google-analytics-aggregate-schema-smoke.php',
);
foreach ( $manifest['tests'] as $path => $test ) {
	$expected_environment = in_array( $path, $wordpress_smokes, true ) ? 'wordpress' : 'standalone-php';
	if ( $expected_environment !== ( $test['environment'] ?? null ) ) {
		fwrite( STDERR, "Unexpected environment for {$path}.\n" );
		exit( 1 );
	}
}

echo "Homeboy test manifest contract passed for " . count( $expected ) . " smoke tests.\n";
