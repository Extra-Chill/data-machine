<?php
/**
 * Pure-PHP coverage for atomic composable-file replacement.
 *
 * Run with: php tests/composable-file-atomic-write-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {
		public function ensure_directory_exists( string $directory ): bool {
			return is_dir( $directory );
		}
	}

	class FilesystemHelper {
		public static function make_group_writable( string $filepath ): bool {
			return is_file( $filepath );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'FS_CHMOD_FILE', 0644 );
	require_once dirname( __DIR__ ) . '/inc/Engine/AI/ComposableFileGenerator.php';

	$directory = sys_get_temp_dir() . '/dm-compose-atomic-' . bin2hex( random_bytes( 5 ) );
	$filepath  = $directory . '/AGENTS.md';
	mkdir( $directory, 0700 );
	file_put_contents( $filepath, str_repeat( 'old', 10000 ) );

	$method = new \ReflectionMethod( \DataMachine\Engine\AI\ComposableFileGenerator::class, 'write_file' );
	$result = $method->invoke( null, $filepath, $directory, str_repeat( 'new', 10000 ) );
	$actual = file_get_contents( $filepath );
	$temps  = glob( $directory . '/.AGENTS.md.tmp-*' );

	@unlink( $filepath );
	@rmdir( $directory );

	if ( true !== $result || str_repeat( 'new', 10000 ) . "\n" !== $actual || array() !== $temps ) {
		fwrite( STDERR, "FAIL: composable file was not atomically replaced and cleaned up.\n" );
		exit( 1 );
	}

	echo "OK (atomic same-directory replacement)\n";
}
