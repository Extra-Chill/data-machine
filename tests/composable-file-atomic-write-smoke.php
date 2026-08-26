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
		public static array $group_writable_paths = array();

		public static function make_group_writable( string $filepath ): bool {
			self::$group_writable_paths[] = $filepath;
			return is_file( $filepath );
		}
	}
}

namespace {
	$mode = $argv[1] ?? '';
	if ( ! in_array( $mode, array( 'defined', 'undefined' ), true ) ) {
		$run = static function ( string $mode ): array {
			$process = proc_open( array( PHP_BINARY, __FILE__, $mode ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) {
				return array( 1, '', 'Failed to start child process.' );
			}

			$output = stream_get_contents( $pipes[1] );
			$error  = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );

			return array( proc_close( $process ), $output, $error );
		};

		foreach ( array( 'defined', 'undefined' ) as $child_mode ) {
			list( $status, $output, $error ) = $run( $child_mode );
			if ( 0 !== $status ) {
				fwrite( STDERR, "FAIL: {$child_mode} FS_CHMOD_FILE child failed.\n{$output}{$error}" );
				exit( 1 );
			}
		}

		echo "OK (atomic replacement and FS_CHMOD_FILE fallback)\n";
		exit( 0 );
	}

	define( 'ABSPATH', __DIR__ );
	if ( 'defined' === $mode ) {
		define( 'FS_CHMOD_FILE', 0640 );
	} elseif ( defined( 'FS_CHMOD_FILE' ) ) {
		fwrite( STDERR, "FAIL: FS_CHMOD_FILE must be undefined for this child.\n" );
		exit( 1 );
	}
	require_once dirname( __DIR__ ) . '/inc/Engine/AI/ComposableFileGenerator.php';

	$directory = sys_get_temp_dir() . '/dm-compose-atomic-' . bin2hex( random_bytes( 5 ) );
	$filepath  = $directory . '/AGENTS.md';
	$expected_mode = 'defined' === $mode ? 0640 : 0644;
	mkdir( $directory, 0700 );
	file_put_contents( $filepath, str_repeat( 'old', 10000 ) );

	$method = new \ReflectionMethod( \DataMachine\Engine\AI\ComposableFileGenerator::class, 'write_file' );
	$result = $method->invoke( null, $filepath, $directory, str_repeat( 'new', 10000 ) );
	$actual = file_get_contents( $filepath );
	clearstatcache( true, $filepath );
	$permissions = fileperms( $filepath ) & 0777;
	$temps       = glob( $directory . '/.AGENTS.md.tmp-*' );

	@unlink( $filepath );
	@rmdir( $directory );

	if ( true !== $result || str_repeat( 'new', 10000 ) . "\n" !== $actual || $expected_mode !== $permissions || array() !== $temps || array( $filepath ) !== \DataMachine\Core\FilesRepository\FilesystemHelper::$group_writable_paths ) {
		fwrite( STDERR, "FAIL: composable file write did not preserve atomic replacement, cleanup, mode, and group-writable normalization.\n" );
		exit( 1 );
	}

	echo "OK ({$mode} FS_CHMOD_FILE mode)\n";
}
