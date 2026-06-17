<?php
/**
 * Smoke tests for FileCleanup retention count primitives.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return array( 'basedir' => $GLOBALS['datamachine_file_cleanup_upload_dir'] );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): bool {
		return is_file( $file ) && unlink( $file );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		unset( $hook, $args );
	}
}

if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
	class WP_Filesystem_Base {
		public function delete( string $file ): bool {
			return is_file( $file ) && unlink( $file );
		}

		public function rmdir( string $directory ): bool {
			return is_dir( $directory ) && rmdir( $directory );
		}
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem(): bool {
		$GLOBALS['wp_filesystem'] = new WP_Filesystem_Base();
		return true;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/FilesRepository/DirectoryManager.php';
require_once dirname( __DIR__ ) . '/inc/Core/FilesRepository/FilesystemHelper.php';
require_once dirname( __DIR__ ) . '/inc/Core/FilesRepository/FileCleanup.php';

use DataMachine\Core\FilesRepository\FileCleanup;

$failures = array();

$assert_same = static function ( int $expected, int $actual, string $label ) use ( &$failures ): void {
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label} expected {$expected}, got {$actual}\n";
	$failures[] = $label;
};

$remove_directory = static function ( string $directory ) use ( &$remove_directory ): void {
	if ( ! is_dir( $directory ) ) {
		return;
	}

	$entries = scandir( $directory );
	foreach ( false === $entries ? array() : $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $directory . DIRECTORY_SEPARATOR . $entry;
		if ( is_dir( $path ) ) {
			$remove_directory( $path );
			continue;
		}

		unlink( $path );
	}

	rmdir( $directory );
};

$upload_dir                                      = sys_get_temp_dir() . '/datamachine-file-cleanup-' . uniqid( '', true );
$GLOBALS['datamachine_file_cleanup_upload_dir'] = $upload_dir;

$files_dir      = $upload_dir . '/datamachine-files/pipeline-1/flow-2/flow-2-files';
$jobs_dir       = $upload_dir . '/datamachine-files/pipeline-1/flow-2/jobs';
$old_file       = $files_dir . '/old.txt';
$recent_file    = $files_dir . '/recent.txt';
$old_job_file   = $jobs_dir . '/job-1/payload.json';
$recent_job_file = $jobs_dir . '/job-2/payload.json';

mkdir( dirname( $old_file ), 0777, true );
mkdir( dirname( $old_job_file ), 0777, true );
mkdir( dirname( $recent_job_file ), 0777, true );
mkdir( $jobs_dir . '/job-3', 0777, true );

file_put_contents( $old_file, 'old' );
file_put_contents( $recent_file, 'recent' );
file_put_contents( $old_job_file, 'old job' );
file_put_contents( $recent_job_file, 'recent job' );

$old_time    = time() - ( 8 * DAY_IN_SECONDS );
$recent_time = time();
touch( $old_file, $old_time );
touch( $old_job_file, $old_time );
touch( $recent_file, $recent_time );
touch( $recent_job_file, $recent_time );

$cleanup = new FileCleanup();

$assert_same( 2, $cleanup->count_old_files( 7 ), 'count_old_files counts old flow files and all-old job directories' );
$assert_same( 1, $cleanup->cleanup_old_files( 7 ), 'cleanup_old_files preserves deleted flow-file return count' );
$assert_same( 0, $cleanup->count_old_files( 7 ), 'count_old_files reflects cleanup results' );

$remove_directory( $upload_dir );

if ( ! empty( $failures ) ) {
	exit( 1 );
}
