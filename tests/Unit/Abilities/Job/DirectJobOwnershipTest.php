<?php
/**
 * Direct asynchronous job ownership and idempotency coverage.
 *
 * @package DataMachine\Tests\Unit\Abilities\Job
 */

namespace DataMachine\Tests\Unit\Abilities\Job;

use AgentsAPI\AI\WP_Agent_Execution_Principal;
use DataMachine\Abilities\Job\ExecuteWorkflowAbility;
use DataMachine\Abilities\Job\FailJobAbility;
use DataMachine\Abilities\Job\GetJobsAbility;
use DataMachine\Abilities\Job\RetryJobAbility;
use DataMachine\Abilities\Job\RunMetricsAbility;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobRetryPolicy;
use WP_UnitTestCase;

class DirectJobOwnershipTest extends WP_UnitTestCase {

	private int $owner_id;
	private int $other_user_id;
	private int $admin_id;
	private int $agent_id;
	private int $scheduled_count = 0;

	public function set_up(): void {
		parent::set_up();

		datamachine_register_capabilities();
		$this->owner_id      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->owner_id )->add_cap( 'datamachine_manage_flows' );
		get_user_by( 'id', $this->other_user_id )->add_cap( 'datamachine_manage_flows' );
		$this->agent_id = ( new Agents() )->create_if_missing( 'direct-job-owner', 'Direct Job Owner', $this->owner_id );

		add_action( 'datamachine_schedule_next_step', array( $this, 'captureSchedule' ), 1 );
	}

	public function tear_down(): void {
		remove_action( 'datamachine_schedule_next_step', array( $this, 'captureSchedule' ), 1 );
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function captureSchedule(): void {
		++$this->scheduled_count;
	}

	public function test_authenticated_caller_owns_direct_job_and_identity_survives_engine_snapshot(): void {
		wp_set_current_user( $this->owner_id );

		$result = $this->execute( 'authenticated-caller' );
		$job    = ( new Jobs() )->get_job( (int) $result['job_id'] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $this->owner_id, (int) $job['user_id'] );
		$this->assertNull( $job['agent_id'] );
		$this->assertSame( $this->owner_id, (int) $job['engine_data']['job']['user_id'] );
		$this->assertSame( (int) $job['job_id'], (int) $job['engine_data']['job']['job_id'] );
	}

	public function test_delegated_principal_and_effective_agent_are_persisted(): void {
		wp_set_current_user( $this->owner_id );
		PermissionHelper::set_agent_context( $this->agent_id, $this->owner_id );
		PermissionHelper::set_execution_principal(
			WP_Agent_Execution_Principal::user_session(
				$this->other_user_id,
				(string) $this->agent_id,
				WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST
			)
		);

		$result = $this->execute( 'delegated-caller' );
		$job    = ( new Jobs() )->get_job( (int) $result['job_id'] );

		$this->assertSame( $this->other_user_id, (int) $job['user_id'] );
		$this->assertSame( $this->agent_id, (int) $job['agent_id'] );
		$this->assertSame( $this->other_user_id, (int) $job['engine_data']['job']['user_id'] );
		$this->assertSame( $this->agent_id, (int) $job['engine_data']['job']['agent_id'] );
		$this->assertSame( $this->other_user_id, (int) $job['engine_data']['calling_user_id'] );
	}

	public function test_absent_caller_fails_closed_for_user_scoped_execution(): void {
		wp_set_current_user( 0 );

		$result = $this->execute( 'missing-caller' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'authenticated acting caller', $result['error'] );
	}

	public function test_ownership_survives_deferred_worker_resume(): void {
		wp_set_current_user( $this->owner_id );
		$created = $this->execute( 'deferred-resume' );
		$jobs    = new Jobs();

		$retry = JobRetryPolicy::maybeRetry(
			(int) $created['job_id'],
			'ai_step_failed',
			array(
				'retryable'   => true,
				'flow_step_id' => 'ephemeral_step_0',
			),
			$jobs
		);
		$job = $jobs->get_job( (int) $created['job_id'] );

		$this->assertTrue( $retry['retried'] );
		$this->assertSame( $this->owner_id, (int) $job['user_id'] );
		$this->assertSame( $this->owner_id, (int) $job['engine_data']['job']['user_id'] );
		$this->assertSame( $this->owner_id, (int) $job['engine_data']['calling_user_id'] );
	}

	public function test_owner_access_unrelated_denial_and_privileged_access(): void {
		wp_set_current_user( $this->owner_id );
		$created = $this->execute( 'access-matrix' );
		$job_id  = (int) $created['job_id'];

		$this->assertTrue( ( new GetJobsAbility() )->execute( array( 'job_id' => $job_id ) )['success'] );

		wp_set_current_user( $this->other_user_id );
		$denied = ( new GetJobsAbility() )->execute( array( 'job_id' => $job_id ) );
		$this->assertFalse( $denied['success'] );
		$this->assertSame( 'job_access_denied', $denied['error_code'] );
		$this->assertSame( 'job_access_denied', ( new RunMetricsAbility() )->execute( array( 'job_id' => $job_id ) )['error_code'] );
		$this->assertFalse( ( new FailJobAbility() )->execute( array( 'job_id' => $job_id ) )['success'] );
		$this->assertSame( 'job_access_denied', ( new RetryJobAbility() )->execute( array( 'job_id' => $job_id ) )['error_code'] );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( ( new GetJobsAbility() )->execute( array( 'job_id' => $job_id ) )['success'] );
	}

	public function test_matching_replay_is_idempotent_after_failure_and_conflicts_are_rejected(): void {
		wp_set_current_user( $this->owner_id );
		$first = $this->execute( 'stable-operation' );
		$this->assertTrue( ( new Jobs() )->complete_job( (int) $first['job_id'], 'failed - test' ) );

		$replay = $this->execute( 'stable-operation' );
		$this->assertTrue( $replay['success'] );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $first['job_id'], $replay['job_id'] );
		$this->assertSame( 1, $this->scheduled_count, 'Replay must not enqueue duplicate work.' );

		$conflict = $this->execute( 'stable-operation', 'different input' );
		$this->assertFalse( $conflict['success'] );
		$this->assertStringContainsString( 'different workflow input', $conflict['error'] );

		$distinct = $this->execute( 'distinct-operation' );
		$this->assertTrue( $distinct['success'] );
		$this->assertNotSame( $first['job_id'], $distinct['job_id'] );
		$this->assertSame( 2, $this->scheduled_count );
	}

	public function test_legacy_unowned_job_retains_capability_gated_access(): void {
		$job_id = ( new Jobs() )->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'source'      => 'direct',
			)
		);

		wp_set_current_user( $this->other_user_id );
		$this->assertTrue( ( new GetJobsAbility() )->execute( array( 'job_id' => $job_id ) )['success'] );
	}

	private function execute( string $operation_key, string $prompt = 'input' ): array {
		return ( new ExecuteWorkflowAbility() )->execute(
			array(
				'workflow' => array(
					'steps' => array(
						array(
							'step_type'      => 'ai',
							'system_prompt'  => $prompt,
						),
					),
				),
				'operation_key' => $operation_key,
			)
		);
	}
}
