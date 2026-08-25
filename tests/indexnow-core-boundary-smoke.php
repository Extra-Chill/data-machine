<?php
/**
 * Regression smoke: provider-specific indexing ownership stays outside core.
 *
 * Run with: php tests/indexnow-core-boundary-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	final class WP_CLI {
		/** @var array<string, class-string> */
		public static array $commands = array();

		public static function add_command( string $command, string $class ): void {
			self::$commands[ $command ] = $class;
		}
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/CliServiceProvider.php';

$root     = dirname( __DIR__ );
$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

\DataMachine\Core\Bootstrap\CliServiceProvider::register();

$assert( ! isset( WP_CLI::$commands['datamachine indexnow'] ), 'Core CLI registration excludes IndexNow' );
$assert( isset( WP_CLI::$commands['datamachine meta-description'] ), 'Core CLI provider registers generic SEO commands' );

$removed_files = array(
	$root . '/inc/Abilities/SEO/IndexNowAbilities.php',
	$root . '/inc/Cli/Commands/IndexNowCommand.php',
);

foreach ( $removed_files as $removed_file ) {
	$assert( ! is_file( $removed_file ), basename( $removed_file ) . ' is not owned by core' );
}

$provider_source = (string) file_get_contents( $root . '/inc/Core/Bootstrap/AbilityServiceProvider.php' );
$assert( ! str_contains( strtolower( $provider_source ), 'indexnow' ), 'Core ability provider has no IndexNow bootstrap registration' );
$assert( str_contains( $provider_source, 'MetaDescriptionAbilities' ), 'Core ability provider retains meta-description registration' );

$forbidden = array(
	'https://api.indexnow.org/indexnow',
	'datamachine/indexnow-submit',
	'datamachine/indexnow-status',
	'datamachine/indexnow-generate-key',
	'datamachine/indexnow-verify-key',
);
$iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc' ) );

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$source = (string) file_get_contents( $file->getPathname() );
	foreach ( $forbidden as $provider_surface ) {
		$assert( ! str_contains( $source, $provider_surface ), "Core does not own {$provider_surface}" );
	}
}

if ( $failures ) {
	foreach ( array_unique( $failures ) as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "PASS: core registers without provider-specific IndexNow ownership\n" );
