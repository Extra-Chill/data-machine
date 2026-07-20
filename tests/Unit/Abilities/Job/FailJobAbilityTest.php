<?php
/**
 * Fail-job ability coverage.
 *
 * @package DataMachine\Tests\Unit\Abilities\Job
 */

namespace DataMachine\Tests\Unit\Abilities\Job;

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Abilities\Job\FailJobAbility;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use WP_UnitTestCase;

class FailJobAbilityTest extends WP_UnitTestCase {
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_fail_job_allows_pending_jobs(): void {
		$jobs_db = new Jobs();
		$job_id  = $this->createDirectJob( $jobs_db );

		$result = ( new FailJobAbility() )->execute(
			array(
				'job_id' => $job_id,
				'reason' => 'manual cancellation',
			)
		);

		$this->assertTrue( $result['success'] ?? false );
		$this->assertSame( JobStatus::PENDING, $result['previous_status'] ?? '' );
		$this->assertSame( 'failed - manual cancellation', $result['new_status'] ?? '' );
		$this->assertSame( 'failed - manual cancellation', $jobs_db->get_job( $job_id )['status'] ?? '' );
	}

	public function test_execute_step_does_not_restart_terminal_jobs(): void {
		$jobs_db = new Jobs();
		$job_id  = $this->createDirectJob( $jobs_db );

		$this->assertTrue( $jobs_db->complete_job( $job_id, JobStatus::failed( 'manual cancellation' )->toString() ) );

		$result = ( new ExecuteStepAbility() )->execute(
			array(
				'job_id'       => $job_id,
				'flow_step_id' => 'queued_step',
			)
		);

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'failed - manual cancellation', $result['terminal_state'] ?? '' );
		$this->assertStringContainsString( 'terminal status', $result['error'] ?? '' );
		$this->assertSame( 'failed - manual cancellation', $jobs_db->get_job( $job_id )['status'] ?? '' );
	}

	private function createDirectJob( Jobs $jobs_db ): int {
		$job_id = $jobs_db->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'source'      => 'system',
				'label'       => 'Pending cancellation test',
				'user_id'     => $this->admin_id,
			)
		);

		$this->assertIsInt( $job_id );
		$this->assertSame( JobStatus::PENDING, $jobs_db->get_job( $job_id )['status'] ?? '' );

		return $job_id;
	}
}
