<?php
/**
 * Regression coverage for #3545: `ComposableFileGenerator::write_file()`
 * silently lost its atomic-rename guarantee when `tempnam()` fell back to
 * `sys_get_temp_dir()` because the target directory was not writable.
 *
 * `tempnam()` does not fail in that case — it emits an `E_NOTICE` and
 * returns a path on a different filesystem, degrading the following
 * `rename()` to a non-atomic copy+unlink while the caller reports success.
 * The fix checks `is_writable()` up front (so `tempnam()` is never even
 * called against an unwritable directory) and fails explicitly rather than
 * completing that fallback silently.
 *
 * `write_file()` is a private implementation detail of `regenerate()`, not
 * part of the class's public contract, and is exercised directly via
 * reflection against a real temp-dir filesystem so the writable/unwritable
 * behavior under test reflects real POSIX permission semantics rather than
 * a mocked filesystem.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\ComposableFileGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \DataMachine\Engine\AI\ComposableFileGenerator
 */
class ComposableFileGeneratorAtomicWriteTest extends TestCase {

	private string $directory = '';

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/dm-3545-atomic-write-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->directory, 0775 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test fixture on the real OS temp dir.
	}

	protected function tearDown(): void {
		// Restore permissions before cleanup so a read-only-directory test
		// case doesn't leave anything behind that a later run can't remove.
		@chmod( $this->directory, 0775 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.

		foreach ( (array) glob( $this->directory . '/*' ) as $entry ) {
			@unlink( $entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.
		}
		foreach ( (array) glob( $this->directory . '/.*' ) as $entry ) {
			if ( is_file( $entry ) ) {
				@unlink( $entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup, catches any leaked .tmp- temp files.
			}
		}
		@rmdir( $this->directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup.

		parent::tearDown();
	}

	private function composed_file_path(): string {
		return $this->directory . '/AGENTS.md';
	}

	/**
	 * Invoke the private `write_file()` method under test via reflection.
	 * It is an internal implementation detail of `regenerate()`, not part
	 * of the class's public contract.
	 *
	 * @return array{success:bool,message?:string,error_code?:string}
	 */
	private function write_file( string $filepath, string $directory, string $content ): array {
		$method = new ReflectionMethod( ComposableFileGenerator::class, 'write_file' );
		$method->setAccessible( true );

		return $method->invoke( null, $filepath, $directory, $content );
	}

	public function test_writable_directory_write_succeeds_and_leaves_no_stray_temp_file(): void {
		$result = $this->write_file( $this->composed_file_path(), $this->directory, 'composed content' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( "composed content\n", file_get_contents( $this->composed_file_path() ) );

		$leftovers = array_filter(
			(array) glob( $this->directory . '/.*' ),
			static fn( string $path ): bool => is_file( $path )
		);
		$this->assertSame(
			array(),
			array_values( $leftovers ),
			'A successful write must not leave its temp file behind (#3545 atomic path).'
		);
	}

	public function test_writable_directory_write_is_group_writable(): void {
		$old_umask = umask( 0077 ); // Would otherwise strip the group-write bit off a freshly created file.
		try {
			$result = $this->write_file( $this->composed_file_path(), $this->directory, 'v1' );
		} finally {
			umask( $old_umask );
		}

		$this->assertTrue( $result['success'] );

		$mode = fileperms( $this->composed_file_path() ) & 0777;
		$this->assertSame(
			0060,
			$mode & 0060,
			sprintf( 'Expected the composed file to be group-writable, got mode 0%o.', $mode )
		);
	}

	public function test_writable_directory_second_write_replaces_content_and_leaves_no_stray_temp_file(): void {
		$first = $this->write_file( $this->composed_file_path(), $this->directory, 'v1' );
		$this->assertTrue( $first['success'] );

		// Longer than 'v1', so an in-place truncate+write bug (instead of the
		// intended write-temp-then-rename) would still be caught by content
		// correctness even where inode reuse makes an inode-identity check
		// unreliable (tmpfs-backed sandboxes recycle freed inodes readily).
		$second = $this->write_file( $this->composed_file_path(), $this->directory, 'v2-longer-payload' );
		$this->assertTrue( $second['success'] );

		$this->assertSame( "v2-longer-payload\n", file_get_contents( $this->composed_file_path() ) );

		$leftovers = array_filter(
			(array) glob( $this->directory . '/.*' ),
			static fn( string $path ): bool => is_file( $path )
		);
		$this->assertSame(
			array(),
			array_values( $leftovers ),
			'A second composition must not leave its temp file behind after the atomic rename (#3545).'
		);
	}

	public function test_unwritable_directory_fails_explicitly_without_a_tempnam_notice(): void {
		$this->skip_when_root();

		chmod( $this->directory, 0500 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test fixture: directory readable/listable but not writable.

		$notices = array();
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$notices ): bool {
				$notices[] = $errstr;
				return true; // Swallow so PHPUnit's own handler doesn't turn it into a failure with a different message.
			}
		);
		try {
			$result = $this->write_file( $this->composed_file_path(), $this->directory, 'composed content' );
		} finally {
			restore_error_handler();
		}

		$this->assertSame(
			array(),
			$notices,
			'write_file() must never let tempnam() emit its "file created in the system temporary directory" notice (#3545).'
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'directory_not_writable', $result['error_code'] ?? null );
		$this->assertStringContainsString(
			$this->directory,
			$result['message'],
			'Failure must name the unwritable directory so the operator knows what to fix.'
		);

		$this->assertFileDoesNotExist(
			$this->composed_file_path(),
			'A refused write must not produce a non-atomic in-place copy of the target (#3545).'
		);
	}

	private function skip_when_root(): void {
		if ( ! function_exists( 'posix_geteuid' ) ) {
			$this->markTestSkipped( 'POSIX extension required to reason about the effective user.' );
		}
		if ( 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses the directory-mode permission check this case depends on.' );
		}
	}
}
