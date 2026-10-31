<?php
/**
 * Regression coverage for #3541: `ImageTemplateAbilities` cached-file writes
 * must land at a deterministic, group-writable mode regardless of the
 * writing process's umask, and a denied write must name the destination
 * path and reason — not the (already-deleted) source temp filename.
 *
 * `copyToCache()` and `describeCopyFailure()` are exercised directly via
 * reflection against real OS temp-dir paths (`sys_get_temp_dir()`), not
 * `wp_upload_dir()`. The sandboxed WordPress test runtime backing this
 * suite does not enforce standard POSIX permission bits under
 * `wp-content/uploads` (every write reads back as `0666` regardless of
 * umask or explicit `chmod()`), so permission assertions there are not
 * meaningful. `/tmp` in this same sandbox is a real filesystem — see
 * `FilesystemHelperSharedGroupTest`, which already relies on this for
 * `FilesystemHelper::inherit_shared_group()` coverage.
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\Media\ImageTemplateAbilities;
use ReflectionMethod;
use WP_UnitTestCase;

class ImageTemplateCachePermissionsTest extends WP_UnitTestCase {

	private string $work_dir;

	/** @var string[] */
	private array $cleanup_paths = array();

	public function set_up(): void {
		parent::set_up();
		$this->work_dir = sys_get_temp_dir() . '/dm-3541-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->work_dir, 0775 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test fixture on the local temp dir; $wp_filesystem is unavailable in the headless test runner.
	}

	public function tear_down(): void {
		foreach ( array_reverse( $this->cleanup_paths ) as $path ) {
			if ( is_dir( $path ) ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.
			} elseif ( file_exists( $path ) ) {
				@chmod( $path, 0666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.
			}
		}

		foreach ( (array) glob( $this->work_dir . '/*' ) as $leftover ) {
			@chmod( $leftover, 0666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.
			@unlink( $leftover ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.
		}
		@rmdir( $this->work_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.

		parent::tear_down();
	}

	/**
	 * Invoke the private `copyToCache()` method under test via reflection.
	 * It is an internal implementation detail of `copyFilesToCachedLocation()`
	 * (itself private, used only by `renderTemplate()`), not part of the
	 * ability's public contract.
	 */
	private function copy_to_cache( string $source_path, string $dest_path ): ?string {
		$method = new ReflectionMethod( ImageTemplateAbilities::class, 'copyToCache' );
		$method->setAccessible( true );

		return $method->invoke( null, $source_path, $dest_path );
	}

	private function make_source_file( string $contents = 'fake-rendered-bytes' ): string {
		$path = $this->work_dir . '/source-' . bin2hex( random_bytes( 4 ) ) . '.png';
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture, no $wp_filesystem in the headless test runner.
		$this->cleanup_paths[] = $path;

		return $path;
	}

	public function test_newly_cached_file_is_group_writable_regardless_of_a_restrictive_umask(): void {
		$dest = $this->work_dir . '/umask-restrictive.png';
		$this->cleanup_paths[] = $dest;

		$old_umask = umask( 0077 ); // Would otherwise strip the group-write bit off a freshly created file.
		try {
			$result = $this->copy_to_cache( $this->make_source_file(), $dest );
		} finally {
			umask( $old_umask );
		}

		$this->assertNull( $result, 'Expected the copy to succeed.' );
		$this->assertFileExists( $dest );

		$mode = fileperms( $dest ) & 0777;
		$this->assertSame(
			0060,
			$mode & 0060,
			sprintf( 'Expected the cached file to be group-writable, got mode 0%o — umask must not determine cache-file permissions (#3541).', $mode )
		);
	}

	public function test_overwriting_a_non_group_writable_cached_file_re_normalizes_its_mode(): void {
		// Simulate a card that predates this fix: cached, but not group-writable.
		$dest = $this->work_dir . '/stale-mode.png';
		$this->cleanup_paths[] = $dest;
		file_put_contents( $dest, 'stale-v1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		chmod( $dest, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test fixture: force a non-group-writable starting mode.
		$this->assertSame( 0, fileperms( $dest ) & 0060, 'Test fixture setup: destination must start non-group-writable.' );

		$result = $this->copy_to_cache( $this->make_source_file( 'v2' ), $dest );

		$this->assertNull( $result, 'Expected the overwrite to succeed: ' . $result );
		$this->assertSame( 'v2', file_get_contents( $dest ) );
		$this->assertSame(
			0060,
			fileperms( $dest ) & 0060,
			'Expected the cached file to be re-normalized to group-writable after being overwritten by cache regeneration (#3541).'
		);
	}

	public function test_denied_write_names_destination_path_and_reason_not_source_temp_filename(): void {
		// Force copy() to fail deterministically, independent of which OS
		// user the test runs as: a directory sitting at the destination
		// path can never be copy()'d over by a regular file.
		$dest_path = $this->work_dir . '/blocked.png';
		mkdir( $dest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test fixture.
		$this->cleanup_paths[] = $dest_path;

		$source   = $this->make_source_file();
		$basename = basename( $source );

		$result = $this->copy_to_cache( $source, $dest_path );

		$this->assertNotNull( $result, 'Expected the copy to fail because the destination is a directory.' );
		$this->assertStringContainsString( $dest_path, $result, 'Failure message must name the destination path.' );
		$this->assertStringNotContainsString( $basename, $result, 'Failure message must not name the source temp filename — that sends investigation to the wrong file (#3541).' );
	}

	public function test_missing_source_file_failure_names_the_source(): void {
		// The "source missing" branch (in copyFilesToCachedLocation()'s loop,
		// not copyToCache()) is a genuinely source-side failure — the
		// renderer produced no file — so it is expected, and correct, to
		// still name the source there.
		$bucket = 'dm-3541-missing-source-' . bin2hex( random_bytes( 6 ) );

		$missing_source = $this->work_dir . '/does-not-exist.png';

		$method = new ReflectionMethod( ImageTemplateAbilities::class, 'copyFilesToCachedLocation' );
		$method->setAccessible( true );
		$result = $method->invoke(
			null,
			array( $missing_source ),
			'dm_3541_test_template',
			array(
				'bucket' => $bucket,
				'key'    => 'missing-source',
			)
		);

		$upload_dir            = wp_upload_dir();
		$this->cleanup_paths[] = trailingslashit( $upload_dir['basedir'] ) . $bucket;

		$this->assertEmpty( $result['cached_paths'] );
		$this->assertStringContainsString( basename( $missing_source ), $result['message'] );
		$this->assertStringContainsString( 'source file missing', $result['message'] );
	}
}
