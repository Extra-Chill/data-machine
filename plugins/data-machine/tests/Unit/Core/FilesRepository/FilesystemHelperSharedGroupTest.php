<?php
/**
 * Regression coverage for shared-group inheritance on composed files (#3512).
 *
 * 0664 only means "writable by both runtime users" when both are in the file's
 * group. A file created by a process whose primary group is not the shared one
 * — root, typically — is group-writable by a group the service user is not in,
 * which is the same as not being writable at all.
 *
 * Changing a file's group requires ownership plus membership of the target
 * group, so the cases that need a real reassignment run only where the suite
 * can actually perform one.
 *
 * @package DataMachine\Tests\Unit\Core\FilesRepository
 */

namespace DataMachine\Tests\Unit\Core\FilesRepository;

use DataMachine\Core\FilesRepository\FilesystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DataMachine\Core\FilesRepository\FilesystemHelper::inherit_shared_group
 */
class FilesystemHelperSharedGroupTest extends TestCase {

	private string $directory = '';

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/dm-shared-group-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->directory, 0775 );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->directory . '/*' ) as $entry ) {
			@unlink( $entry );
		}
		@rmdir( $this->directory );
		parent::tearDown();
	}

	public function test_file_already_in_the_directory_group_is_left_alone(): void {
		$file = $this->directory . '/SITE.md';
		file_put_contents( $file, "composed\n" );

		$this->assertTrue( FilesystemHelper::inherit_shared_group( $file, $this->directory ) );
		$this->assertSame( filegroup( $this->directory ), filegroup( $file ) );
	}

	public function test_file_is_moved_into_the_group_that_shares_its_directory(): void {
		$group = $this->second_group_available_to_this_user();
		if ( null === $group ) {
			$this->markTestSkipped( 'Needs a second group this user may assign to reassign anything.' );
		}

		chgrp( $this->directory, $group );
		$file = $this->directory . '/SITE.md';
		file_put_contents( $file, "composed\n" );
		clearstatcache( true, $file );

		if ( filegroup( $file ) === $group ) {
			$this->markTestSkipped( 'Directory is setgid; there is no mismatch to correct here.' );
		}

		$this->assertTrue( FilesystemHelper::inherit_shared_group( $file, $this->directory ) );
		clearstatcache( true, $file );
		$this->assertSame(
			$group,
			filegroup( $file ),
			'A composed file left in the writer primary group is unwritable by the other runtime user.'
		);
	}

	public function test_missing_file_or_directory_is_reported_rather_than_assumed(): void {
		$this->assertFalse(
			FilesystemHelper::inherit_shared_group( $this->directory . '/absent.md', $this->directory )
		);

		$file = $this->directory . '/SITE.md';
		file_put_contents( $file, "composed\n" );
		$this->assertFalse(
			FilesystemHelper::inherit_shared_group( $file, $this->directory . '/absent-dir' )
		);
	}

	/**
	 * Find a group other than the file's current one that this user may assign.
	 */
	private function second_group_available_to_this_user(): ?int {
		if ( ! function_exists( 'posix_getgroups' ) || ! function_exists( 'posix_getegid' ) ) {
			return null;
		}

		$current = posix_getegid();
		foreach ( posix_getgroups() as $candidate ) {
			if ( $candidate !== $current ) {
				return $candidate;
			}
		}

		// Root may assign any group, so any real one other than the current.
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			foreach ( array( 'www-data', 'daemon', 'bin', 'sys' ) as $name ) {
				$group = posix_getgrnam( $name );
				if ( is_array( $group ) && $group['gid'] !== $current ) {
					return (int) $group['gid'];
				}
			}
		}

		return null;
	}
}
