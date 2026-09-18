<?php
/**
 * Regression coverage for composition-lock reach and fault reporting (#3512).
 *
 * The lock is only useful if every identity allowed to write the composed file
 * can also open the lock guarding it. A lock left at the creating process's
 * umask made the first privileged composer permanently lock out the service
 * user, because acquisition needs write access. On a live install that wedged
 * AGENTS.md and SITE.md composition until the file was deleted by hand.
 *
 * The true EACCES recovery cannot be exercised as root, which bypasses
 * permission checks, so those cases skip there. The inheritance cases that
 * prevent the wedge in the first place run for every uid.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\ComposableFileLock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DataMachine\Engine\AI\ComposableFileLock
 */
class ComposableFileLockPermissionsTest extends TestCase {

	private string $directory = '';

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/dm-lock-perms-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->directory, 0775 );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->directory . '/{,.}*', GLOB_BRACE ) as $entry ) {
			if ( is_file( $entry ) ) {
				@unlink( $entry );
			} elseif ( is_dir( $entry ) && ! in_array( basename( $entry ), array( '.', '..' ), true ) ) {
				@rmdir( $entry );
			}
		}
		@rmdir( $this->directory );
		parent::tearDown();
	}

	private function target(): string {
		return $this->directory . '/AGENTS.md';
	}

	private function lock_path(): string {
		return $this->directory . '/.AGENTS.md.compose.lock';
	}

	private function mode_of( string $path ): int {
		clearstatcache( true, $path );
		return (int) ( fileperms( $path ) & 0777 );
	}

	public function test_lock_inherits_the_group_writable_mode_of_the_file_it_guards(): void {
		file_put_contents( $this->target(), "composed\n" );
		chmod( $this->target(), 0664 );

		$acquisition = ComposableFileLock::acquire( 'AGENTS.md', $this->target(), 100 );
		$this->assertTrue( $acquisition['acquired'] );
		$acquisition['lock']->release();

		$this->assertSame(
			0664,
			$this->mode_of( $this->lock_path() ),
			'A lock more restrictive than its target locks out identities that may write the target.'
		);
		$this->assertSame(
			filegroup( $this->target() ),
			filegroup( $this->lock_path() ),
			'Lock and target must share a group or group-write on the target buys nothing.'
		);
	}

	public function test_lock_does_not_widen_past_a_private_target(): void {
		file_put_contents( $this->target(), "composed\n" );
		chmod( $this->target(), 0600 );

		$acquisition = ComposableFileLock::acquire( 'AGENTS.md', $this->target(), 100 );
		$this->assertTrue( $acquisition['acquired'] );
		$acquisition['lock']->release();

		$this->assertSame(
			0600,
			$this->mode_of( $this->lock_path() ),
			'Inheritance must track the target both ways, not grant blanket group access.'
		);
	}

	public function test_reacquisition_heals_a_lock_whose_mode_has_drifted(): void {
		file_put_contents( $this->target(), "composed\n" );
		chmod( $this->target(), 0664 );

		$first = ComposableFileLock::acquire( 'AGENTS.md', $this->target(), 100 );
		$this->assertTrue( $first['acquired'] );
		$first['lock']->release();

		// Stand in for a lock created before this fix, or by a composer running
		// under a stricter umask.
		chmod( $this->lock_path(), 0600 );

		$second = ComposableFileLock::acquire( 'AGENTS.md', $this->target(), 100 );
		$this->assertTrue( $second['acquired'] );
		$second['lock']->release();

		$this->assertSame(
			0664,
			$this->mode_of( $this->lock_path() ),
			'An install wedged by an old lock must heal the next time a permitted composer runs.'
		);
	}

	public function test_unopenable_lock_is_reported_as_a_permission_fault_not_contention(): void {
		// A directory at the lock path is unopenable for every uid, including
		// root, so this reproduces the misreport without depending on who runs
		// the suite.
		mkdir( $this->lock_path(), 0755 );

		$acquisition = ComposableFileLock::acquire( 'AGENTS.md', $this->target(), 100 );

		$this->assertFalse( $acquisition['acquired'] );
		$this->assertSame( 'unusable', $acquisition['diagnostic']['lock_status'] );
		$this->assertSame(
			'rm -f ' . escapeshellarg( $this->lock_path() ),
			$acquisition['diagnostic']['recovery_command'],
			'Re-running the composition that just failed on this lock is not a recovery.'
		);
	}

	public function test_snapshot_reports_a_lock_it_cannot_open_for_writing(): void {
		mkdir( $this->lock_path(), 0755 );

		$snapshot = ComposableFileLock::snapshot( 'AGENTS.md', $this->target() );

		$this->assertSame(
			'unusable',
			$snapshot['lock_status'],
			'Diagnostics must not require write access to the thing being diagnosed.'
		);
	}

	public function test_unwritable_lock_file_is_replaced_so_composition_recovers(): void {
		$this->skip_when_root();

		file_put_contents( $this->target(), "composed\n" );
		chmod( $this->target(), 0664 );
		touch( $this->lock_path() );
		chmod( $this->lock_path(), 0444 );

		$acquisition = ComposableFileLock::acquire( 'AGENTS.md', $this->target(), 100 );

		$this->assertTrue(
			$acquisition['acquired'],
			'An unheld lock nobody can write is recoverable; failing forever is not acceptable.'
		);
		$acquisition['lock']->release();
		$this->assertSame( 0664, $this->mode_of( $this->lock_path() ) );
	}

	public function test_unwritable_lock_is_left_alone_when_the_directory_is_not_writable(): void {
		$this->skip_when_root();

		touch( $this->lock_path() );
		chmod( $this->lock_path(), 0444 );
		chmod( $this->directory, 0555 );

		$acquisition = ComposableFileLock::acquire( 'AGENTS.md', $this->target(), 100 );

		chmod( $this->directory, 0775 );

		$this->assertFalse( $acquisition['acquired'] );
		$this->assertSame( 'unusable', $acquisition['diagnostic']['lock_status'] );
	}

	private function skip_when_root(): void {
		if ( ! function_exists( 'posix_geteuid' ) ) {
			$this->markTestSkipped( 'POSIX extension required to reason about the effective user.' );
		}
		if ( 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses the permission check this case depends on.' );
		}
	}
}
