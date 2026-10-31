<?php
/**
 * Tests for JobStatus WAITING state.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use DataMachine\Core\JobStatus;

class JobStatusWaitingTest extends TestCase {

	/**
	 * Test WAITING constant exists.
	 */
	public function test_waiting_constant(): void {
		$this->assertSame( 'waiting', JobStatus::WAITING );
	}

	/**
	 * Test waiting is NOT a final status.
	 */
	public function test_waiting_is_not_final(): void {
		$this->assertFalse( JobStatus::isStatusFinal( 'waiting' ) );
		$this->assertNotContains( JobStatus::WAITING, JobStatus::FINAL_STATUSES );
	}

	/**
	 * Test waiting is NOT a success status.
	 */
	public function test_waiting_is_not_success(): void {
		$this->assertFalse( JobStatus::isStatusSuccess( 'waiting' ) );
	}

	/**
	 * Test waiting is NOT a failure status.
	 */
	public function test_waiting_is_not_failure(): void {
		$this->assertFalse( JobStatus::isStatusFailure( 'waiting' ) );
	}

	/**
	 * Test isStatusWaiting static helper.
	 */
	public function test_is_status_waiting(): void {
		$this->assertTrue( JobStatus::isStatusWaiting( 'waiting' ) );
		$this->assertFalse( JobStatus::isStatusWaiting( 'pending' ) );
		$this->assertFalse( JobStatus::isStatusWaiting( 'completed' ) );
	}

	/**
	 * Test waiting factory method.
	 */
	public function test_waiting_factory(): void {
		$status = JobStatus::waiting();
		$this->assertTrue( $status->isWaiting() );
		$this->assertFalse( $status->isFinal() );
		$this->assertFalse( $status->isSuccess() );
		$this->assertFalse( $status->isFailure() );
		$this->assertSame( 'waiting', $status->toString() );
	}

	/**
	 * Test parseBaseStatus handles waiting.
	 */
	public function test_parse_base_status_waiting(): void {
		$status = JobStatus::fromString( 'waiting' );
		$this->assertSame( 'waiting', $status->getBaseStatus() );
		$this->assertNull( $status->getReason() );
	}

	/**
	 * Test parseBaseStatus handles waiting with reason.
	 */
	public function test_parse_waiting_with_reason(): void {
		$status = JobStatus::fromString( 'waiting - webhook gate' );
		$this->assertSame( 'waiting', $status->getBaseStatus() );
		$this->assertSame( 'webhook gate', $status->getReason() );
		$this->assertTrue( $status->isWaiting() );
	}
}
