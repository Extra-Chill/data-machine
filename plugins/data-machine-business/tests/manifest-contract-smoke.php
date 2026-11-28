<?php
/**
 * Guard the smoke-test conventions homeboy.json relies on.
 *
 * Standalone smokes are discovered by the WordPress extension's convention
 * (every tests/ file ending in -smoke.php); homeboy.json lists only `wordpress_test_paths`,
 * the smokes that need a booted WordPress.
 *
 * Run with: php tests/manifest-contract-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

$root     = dirname( __DIR__ );
$config   = json_decode( file_get_contents( $root . '/homeboy.json' ), true );
$settings = $config['extensions']['wordpress']['settings'] ?? array();
$failures = array();

if ( array_key_exists( 'standalone_php_test_paths', $settings ) ) {
	$failures[] = 'homeboy.json must not list standalone_php_test_paths; *-smoke.php files are discovered by convention.';
}

foreach ( (array) ( $settings['wordpress_test_paths'] ?? array() ) as $path ) {
	if ( ! is_file( $root . '/' . $path ) || ! str_ends_with( $path, '-smoke.php' ) ) {
		$failures[] = "wordpress_test_paths entry {$path} must name an existing *-smoke.php file.";
	}
}

// A smoke named test-*.php also matches the PHPUnit file convention. The managed
// WP Codebox bootstrap then loads it as a test file, its exit(1) kills the PHP
// process before PHPUnit starts, and the whole gate reports zero executed tests.
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( $file->isFile() && str_ends_with( $file->getFilename(), '.php' ) && str_starts_with( $file->getFilename(), 'test-' ) && str_contains( $file->getFilename(), 'smoke' ) ) {
		$failures[] = "Smoke test {$file->getFilename()} must not use the PHPUnit test-*.php filename convention.";
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Smoke-test conventions hold.\n";
