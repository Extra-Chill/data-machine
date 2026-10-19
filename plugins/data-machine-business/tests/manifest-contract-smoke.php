<?php
/**
 * Ensure every smoke declares its execution environment in homeboy.json.
 *
 * Run with: php tests/manifest-contract-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

$root     = dirname( __DIR__ );
$config   = json_decode( file_get_contents( $root . '/homeboy.json' ), true );
$settings = $config['extensions']['wordpress']['settings'] ?? null;

if ( ! is_array( $settings ) || ! is_array( $settings['standalone_php_test_paths'] ?? null ) || ! is_array( $settings['wordpress_test_paths'] ?? null ) ) {
	fwrite( STDERR, "homeboy.json must declare standalone_php_test_paths and wordpress_test_paths.\n" );
	exit( 1 );
}

$environments = array_merge(
	array_fill_keys( $settings['standalone_php_test_paths'], 'standalone-php' ),
	array_fill_keys( $settings['wordpress_test_paths'], 'wordpress' )
);

if ( count( $environments ) !== count( $settings['standalone_php_test_paths'] ) + count( $settings['wordpress_test_paths'] ) ) {
	fwrite( STDERR, "A smoke test is declared in both standalone_php_test_paths and wordpress_test_paths.\n" );
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

// A smoke named test-*.php also matches the PHPUnit file convention. The managed
// WP Codebox bootstrap then loads it as a test file, its exit(1) kills the PHP
// process before PHPUnit starts, and the whole gate reports zero executed tests.
foreach ( $expected as $path ) {
	if ( str_starts_with( basename( $path ), 'test-' ) ) {
		fwrite( STDERR, "Smoke test {$path} must not use the PHPUnit test-*.php filename convention.\n" );
		exit( 1 );
	}
}

$declared = array_keys( $environments );
sort( $declared );

if ( $expected !== $declared ) {
	fwrite( STDERR, "homeboy.json does not classify every smoke test exactly.\n" );
	exit( 1 );
}

$wordpress_smokes = array(
	'tests/data-machine-http-client-contract-smoke.php',
	'tests/google-analytics-aggregate-schema-smoke.php',
	'tests/google-analytics-tool-schema-smoke.php',
	'tests/sendy-list-campaigns-schema-smoke.php',
);
foreach ( $environments as $path => $environment ) {
	$expected_environment = in_array( $path, $wordpress_smokes, true ) ? 'wordpress' : 'standalone-php';
	if ( $expected_environment !== $environment ) {
		fwrite( STDERR, "Unexpected environment for {$path}.\n" );
		exit( 1 );
	}
}

echo "homeboy.json test environment contract passed for " . count( $expected ) . " smoke tests.\n";
