<?php
/**
 * Bounded JobStatus storage contract.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\JobStatus;
use PHPUnit\Framework\TestCase;

class JobStatusBoundedStorageTest extends TestCase {

	public function test_long_error_message_is_not_part_of_stored_status(): void {
		$detail = 'wp-ai-client request failed: ' . str_repeat( 'cURL error 28: Operation timed out after 513455 milliseconds. ', 8 );
		$stored = JobStatus::failed( $detail )->toStorage();

		$this->assertSame( JobStatus::FAILED, $stored['status'] );
		$this->assertSame( $detail, $stored['status_reason'] );
		$this->assertStringNotContainsString( 'cURL', $stored['status'] );
	}

	public function test_compound_separator_inside_detail_is_preserved(): void {
		$parsed = JobStatus::fromString( 'failed - provider timeout - retry exhausted' );
		$stored = $parsed->toStorage();

		$this->assertSame( JobStatus::FAILED, $stored['status'] );
		$this->assertSame( 'provider timeout - retry exhausted', $stored['status_reason'] );
	}

	public function test_typeerror_exception_stays_out_of_status(): void {
		$exception = 'DataMachineCode\\Workspace\\WorktreeContextInjector::has_owner_terminal_disposable_cleanup_signal(): Argument #1 ($metadata) must be of type array, null given, called in /Users/example/wp-content/plugins/data-machine-code/inc/Workspace/WorkspaceWorktreeCleanupEngine.php:376';
		$stored    = JobStatus::failed( 'Exception: ' . $exception )->toStorage();

		$this->assertSame( JobStatus::FAILED, $stored['status'] );
		$this->assertSame( 'Exception: ' . $exception, $stored['status_reason'] );
		$this->assertStringNotContainsString( 'WorktreeContextInjector', $stored['status'] );
		$this->assertStringNotContainsString( '/Users/', $stored['status'] );
	}

	public function test_reasonless_status_stores_null_detail(): void {
		$stored = JobStatus::completed()->toStorage();

		$this->assertSame( JobStatus::COMPLETED, $stored['status'] );
		$this->assertNull( $stored['status_reason'] );
	}
}
