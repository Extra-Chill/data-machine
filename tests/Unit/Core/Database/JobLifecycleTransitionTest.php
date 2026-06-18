<?php
/**
 * Tests for job lifecycle status transitions.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use WP_UnitTestCase;

class JobLifecycleTransitionTest extends WP_UnitTestCase {

	private Jobs $db_jobs;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( function_exists( 'datamachine_activate_for_site' ) ) {
			datamachine_activate_for_site();
		}
	}

	public function set_up(): void {
		parent::set_up();
		$this->db_jobs = new Jobs();
	}

	public function test_terminal_status_cannot_be_overwritten_by_start_job(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Terminal immutability' ) );
		$this->assertIsInt( $job_id );

		$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::FAILED ) );
		$this->assertFalse( $this->db_jobs->start_job( $job_id, JobStatus::PROCESSING ) );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::FAILED, $job['status'] );
	}

	public function test_terminal_transition_hook_only_fires_when_status_changes(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Terminal hook idempotency' ) );
		$this->assertIsInt( $job_id );

		$completed = array();
		$listener  = static function ( int $completed_job_id, string $status ) use ( &$completed ): void {
			$completed[] = array( $completed_job_id, $status );
		};

		add_action( 'datamachine_job_complete', $listener, 10, 2 );
		try {
			$first = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
			$again = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );

			$this->assertTrue( $first['success'] );
			$this->assertTrue( $first['changed'] );
			$this->assertTrue( $again['success'] );
			$this->assertFalse( $again['changed'] );
			$this->assertSame( array( array( $job_id, JobStatus::COMPLETED ) ), $completed );
		} finally {
			remove_action( 'datamachine_job_complete', $listener, 10 );
		}
	}

	public function test_terminal_status_can_move_from_active_once(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Active to terminal' ) );
		$this->assertIsInt( $job_id );

		$this->assertTrue( $this->db_jobs->start_job( $job_id, JobStatus::PROCESSING ) );

		$completed = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED_NO_ITEMS, true );
		$failed    = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::FAILED, true );

		$this->assertTrue( $completed['success'] );
		$this->assertTrue( $completed['changed'] );
		$this->assertFalse( $failed['success'] );
		$this->assertFalse( $failed['changed'] );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $job['status'] );
	}
}
