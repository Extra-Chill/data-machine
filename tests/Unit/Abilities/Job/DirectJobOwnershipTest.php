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
use DataMachine\Abilities\Job\HydrateJobArtifactAbility;
use DataMachine\Abilities\Job\JobsSummaryAbility;
use DataMachine\Abilities\Job\RetryJobAbility;
use DataMachine\Abilities\Job\RunMetricsAbility;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DirectJobEnqueuer;
use DataMachine\Core\JobRetryPolicy;
use WP_UnitTestCase;

class DirectJobOwnershipTest extends WP_UnitTestCase {

	private int $owner_id;
	private int $other_user_id;
	private int $admin_id;
	private int $agent_id;

	public function set_up(): void {
		parent::set_up();

		datamachine_register_capabilities();
		$this->owner_id      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->owner_id )->add_cap( 'datamachine_manage_flows' );
		get_user_by( 'id', $this->other_user_id )->add_cap( 'datamachine_manage_flows' );
		$this->agent_id = ( new Agents() )->create_if_missing( 'direct-job-owner', 'Direct Job Owner', $this->owner_id );

	}

	public function tear_down(): void {
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );
		parent::tear_down();
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

	public function test_forged_system_context_input_does_not_grant_internal_authority(): void {
		wp_set_current_user( 0 );

		$result = ( new ExecuteWorkflowAbility() )->execute(
			array(
				'workflow'      => $this->workflow(),
				'operation_key' => 'forged-system-context',
				'system_context' => true,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'authenticated acting caller', $result['error'] );

		$internal = ( new ExecuteWorkflowAbility( false ) )->executeInternal(
			array(
				'workflow' => $this->workflow(),
			)
		);
		$this->assertTrue( $internal['success'] );

		wp_set_current_user( $this->other_user_id );
		$this->assertSame( 'job_access_denied', ( new GetJobsAbility() )->execute( array( 'job_id' => $internal['job_id'] ) )['error_code'] );
		wp_set_current_user( $this->admin_id );
		$this->assertTrue( ( new GetJobsAbility() )->execute( array( 'job_id' => $internal['job_id'] ) )['success'] );
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

	public function test_collection_filters_cannot_escape_owner_scope(): void {
		wp_set_current_user( $this->owner_id );
		$owned = $this->execute( 'owned-collection' );
		wp_set_current_user( $this->other_user_id );
		$other = $this->execute( 'other-collection' );

		wp_set_current_user( $this->owner_id );
		$list = ( new GetJobsAbility() )->execute( array() );
		$this->assertContains( $owned['job_id'], array_map( 'intval', array_column( $list['jobs'], 'job_id' ) ) );
		$this->assertNotContains( $other['job_id'], array_map( 'intval', array_column( $list['jobs'], 'job_id' ) ) );

		$forged = ( new GetJobsAbility() )->execute( array( 'user_id' => $this->other_user_id ) );
		$this->assertFalse( $forged['success'] );
		$this->assertSame( 'job_access_denied', $forged['error_code'] );
		$forged_summary = ( new JobsSummaryAbility() )->execute( array( 'user_id' => $this->other_user_id ) );
		$this->assertSame( 'job_access_denied', $forged_summary['error_code'] );

		wp_set_current_user( $this->admin_id );
		$operator_list = ( new GetJobsAbility() )->execute( array() );
		$operator_ids  = array_map( 'intval', array_column( $operator_list['jobs'], 'job_id' ) );
		$this->assertContains( $owned['job_id'], $operator_ids );
		$this->assertContains( $other['job_id'], $operator_ids );
	}

	public function test_artifact_authorization_runs_before_hydration(): void {
		wp_set_current_user( $this->owner_id );
		$created      = $this->execute( 'artifact-access' );
		$artifact_ref = 'datamachine://jobs/' . $created['job_id'] . '/artifacts/tool-trace';
		$content      = 'owner-only artifact';
		$jobs         = new Jobs();
		$engine_data  = $jobs->retrieve_engine_data( (int) $created['job_id'] );
		$engine_data['artifact_files'] = array(
			'tool_trace' => array(
				'artifact_ref' => $artifact_ref,
				'type'         => 'tool_trace',
				'bytes'        => strlen( $content ),
				'sha256'       => hash( 'sha256', $content ),
			),
		);
		$this->assertTrue( $jobs->store_engine_data( (int) $created['job_id'], $engine_data ) );

		$hydrate_count = 0;
		$resolver      = static function () use ( &$hydrate_count, $content ) {
			++$hydrate_count;
			return $content;
		};
		add_filter( 'datamachine_job_artifact_ref_content', $resolver );

		wp_set_current_user( $this->other_user_id );
		$denied = ( new HydrateJobArtifactAbility() )->execute( array( 'artifact_ref' => $artifact_ref ) );
		$this->assertSame( 'job_access_denied', $denied['error_code'] );
		$this->assertSame( 0, $hydrate_count );

		wp_set_current_user( $this->owner_id );
		$owner_result = ( new HydrateJobArtifactAbility() )->execute( array( 'artifact_ref' => $artifact_ref ) );
		$this->assertTrue( $owner_result['success'] );
		$this->assertSame( 1, $hydrate_count );

		wp_set_current_user( $this->admin_id );
		$operator_result = ( new HydrateJobArtifactAbility() )->execute( array( 'artifact_ref' => $artifact_ref ) );
		$this->assertTrue( $operator_result['success'] );
		$this->assertSame( 2, $hydrate_count );
		remove_filter( 'datamachine_job_artifact_ref_content', $resolver );
	}

	public function test_matching_replay_is_idempotent_after_failure_and_conflicts_are_rejected(): void {
		wp_set_current_user( $this->owner_id );
		$first = $this->execute( 'stable-operation' );
		$this->assertTrue( ( new Jobs() )->complete_job( (int) $first['job_id'], 'failed - test' ) );

		$replay = $this->execute( 'stable-operation' );
		$this->assertTrue( $replay['success'] );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $first['job_id'], $replay['job_id'] );
		$this->assertSame( (int) ( new Jobs() )->get_job( (int) $first['job_id'] )['operation_action_id'], (int) ( new Jobs() )->get_job( (int) $replay['job_id'] )['operation_action_id'] );

		$conflict = $this->execute( 'stable-operation', 'different input' );
		$this->assertFalse( $conflict['success'] );
		$this->assertStringContainsString( 'different workflow input', $conflict['error'] );

		$distinct = $this->execute( 'distinct-operation' );
		$this->assertTrue( $distinct['success'] );
		$this->assertNotSame( $first['job_id'], $distinct['job_id'] );
	}

	public function test_invalid_workflow_does_not_claim_operation_key(): void {
		wp_set_current_user( $this->owner_id );
		$invalid = ( new ExecuteWorkflowAbility() )->execute(
			array(
				'workflow'      => array( 'steps' => array() ),
				'operation_key' => 'validation-before-claim',
			)
		);

		$this->assertFalse( $invalid['success'] );
		$this->assertTrue( $this->execute( 'validation-before-claim' )['success'] );
	}

	public function test_terminal_replay_survives_engine_data_retention(): void {
		global $wpdb;

		wp_set_current_user( $this->owner_id );
		$first = $this->execute( 'retained-operation' );
		$jobs  = new Jobs();
		$this->assertTrue( $jobs->complete_job( (int) $first['job_id'], 'failed - retained' ) );
		$wpdb->update( $wpdb->prefix . 'datamachine_jobs', array( 'engine_data' => null ), array( 'job_id' => $first['job_id'] ) );

		$replay = $this->execute( 'retained-operation' );
		$retained_job = $jobs->get_job( (int) $first['job_id'] );
		$this->assertTrue( $replay['success'] );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $first['job_id'], $replay['job_id'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $retained_job['request_fingerprint'] );
		$this->assertSame( 'enqueued', $retained_job['operation_state'] );
	}

	public function test_enqueue_failure_is_reclaimable_and_concurrent_calls_schedule_once(): void {
		global $wpdb;

		$jobs   = new Jobs();
		$job_id = $jobs->create_job(
			array(
				'pipeline_id'      => 'direct',
				'flow_id'          => 'direct',
				'operation_state'  => 'preparing',
				'operation_step_id' => 'ephemeral_step_0',
			)
		);

		$failing = new DirectJobEnqueuer( $jobs, static fn() => false, static fn() => 0 );
		$this->assertFalse( $failing->enqueue( $job_id, 'ephemeral_step_0' )['success'] );
		$this->assertSame( 'enqueue_failed', $jobs->get_job( $job_id )['operation_state'] );

		$schedule_count = 0;
		$scheduled_id   = 0;
		$enqueuer       = new DirectJobEnqueuer(
			$jobs,
			static function () use ( &$schedule_count, &$scheduled_id ) {
				++$schedule_count;
				$scheduled_id = 91;
				return $scheduled_id;
			},
			static function () use ( &$scheduled_id ) {
				return $scheduled_id;
			}
		);
		$first  = $enqueuer->enqueue( $job_id, 'ephemeral_step_0' );
		$second = $enqueuer->enqueue( $job_id, 'ephemeral_step_0' );

		$this->assertTrue( $first['success'] );
		$this->assertTrue( $second['success'] );
		$this->assertSame( 1, $schedule_count );

		$crashed_job_id = $jobs->create_job(
			array(
				'pipeline_id'      => 'direct',
				'flow_id'          => 'direct',
				'operation_state'  => 'preparing',
				'operation_step_id' => 'ephemeral_step_0',
			)
		);
		$this->assertIsArray( $jobs->claim_operation_enqueue( $crashed_job_id ) );
		$wpdb->update(
			$wpdb->prefix . 'datamachine_jobs',
			array( 'operation_claimed_at' => '2000-01-01 00:00:00' ),
			array( 'job_id' => $crashed_job_id )
		);
		$recovered = ( new DirectJobEnqueuer( $jobs, static fn() => 92, static fn() => 0 ) )->enqueue( $crashed_job_id, 'ephemeral_step_0' );
		$this->assertTrue( $recovered['success'] );
		$this->assertSame( 'enqueued', $jobs->get_job( $crashed_job_id )['operation_state'] );
	}

	public function test_non_owner_gets_retryable_in_progress_until_action_is_durable(): void {
		$jobs   = new Jobs();
		$job_id = $jobs->create_job(
			array(
				'pipeline_id'      => 'direct',
				'flow_id'          => 'direct',
				'operation_state'  => 'preparing',
				'operation_step_id' => 'ephemeral_step_0',
			)
		);
		$this->assertIsArray( $jobs->claim_operation_enqueue( $job_id ) );

		$result = ( new DirectJobEnqueuer( $jobs, static fn() => 99, static fn() => 0 ) )->enqueue( $job_id, 'ephemeral_step_0' );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertSame( 'enqueue_in_progress', $result['error'] );
		$this->assertSame( 0, $result['action_id'] );
	}

	public function test_replay_commits_scheduled_action_after_submitter_crash(): void {
		$jobs   = new Jobs();
		$job_id = $jobs->create_job(
			array(
				'pipeline_id'       => 'direct',
				'flow_id'           => 'direct',
				'operation_state'   => 'preparing',
				'operation_step_id' => 'ephemeral_step_0',
			)
		);
		$claim = $jobs->claim_operation_enqueue( $job_id );
		$this->assertIsArray( $claim );

		$result = ( new DirectJobEnqueuer( $jobs, static fn() => 999, static fn() => 404 ) )->enqueue( $job_id, 'ephemeral_step_0' );
		$job    = $jobs->get_job( $job_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 404, $result['action_id'] );
		$this->assertSame( 'enqueued', $job['operation_state'] );
		$this->assertSame( 404, (int) $job['operation_action_id'] );
		$this->assertSame( $claim['generation'], (int) $job['operation_generation'] );
		$this->assertSame( $claim['token'], $job['operation_claim_token'] );
	}

	public function test_expired_lease_takeover_fences_slow_generation(): void {
		global $wpdb;

		$jobs   = new Jobs();
		$job_id = $jobs->create_job(
			array(
				'pipeline_id'      => 'direct',
				'flow_id'          => 'direct',
				'operation_state'  => 'preparing',
				'operation_step_id' => 'ephemeral_step_0',
			)
		);
		$takeover_claim = null;
		$slow = new DirectJobEnqueuer(
			$jobs,
			static function () use ( &$takeover_claim, $jobs, $wpdb, $job_id ) {
				$wpdb->update(
					$wpdb->prefix . 'datamachine_jobs',
					array( 'operation_claimed_at' => '2000-01-01 00:00:00' ),
					array( 'job_id' => $job_id )
				);
				$takeover_claim = $jobs->claim_operation_enqueue( $job_id );
				return 101;
			},
			static fn() => 0
		);

		$slow_result = $slow->enqueue( $job_id, 'ephemeral_step_0' );
		$this->assertFalse( $slow_result['success'] );
		$this->assertSame( 'enqueue_claim_fenced', $slow_result['error'] );
		$this->assertIsArray( $takeover_claim );
		$this->assertSame( 2, $takeover_claim['generation'] );
		$this->assertTrue( $jobs->finish_operation_enqueue( $job_id, 'enqueued', 202, $takeover_claim['token'], $takeover_claim['generation'] ) );

		$job = $jobs->get_job( $job_id );
		$this->assertSame( 2, (int) $job['operation_generation'] );
		$this->assertSame( 202, (int) $job['operation_action_id'] );
		$stale = ( new ExecuteStepAbility() )->execute(
			array(
				'job_id'               => $job_id,
				'flow_step_id'          => 'ephemeral_step_0',
				'operation_generation' => 1,
				'operation_claim_token' => 'superseded-token',
			)
		);
		$this->assertTrue( $stale['stale_generation'] );
	}

	public function test_worker_defers_until_generation_commit_without_starting_job(): void {
		$jobs   = new Jobs();
		$job_id = $jobs->create_job(
			array(
				'pipeline_id'       => 'direct',
				'flow_id'           => 'direct',
				'operation_state'   => 'preparing',
				'operation_step_id' => 'ephemeral_step_0',
			)
		);
		$worker_result = null;
		$enqueued      = ( new DirectJobEnqueuer(
			$jobs,
			static function ( int $run_at, string $hook, array $args ) use ( &$worker_result ) {
				$run_at;
				$hook;
				$worker_result = ( new ExecuteStepAbility() )->execute( $args );
				return 303;
			},
			static fn() => 0
		) )->enqueue( $job_id, 'ephemeral_step_0' );

		$this->assertTrue( $worker_result['deferred'] );
		$this->assertTrue( $worker_result['retryable'] );
		$this->assertSame( 'pending', $jobs->get_job( $job_id )['status'] );
		$this->assertTrue( $enqueued['success'] );
		$this->assertSame( 'enqueued', $jobs->get_job( $job_id )['operation_state'] );
	}

	public function test_deferred_worker_becomes_stale_after_crashed_claim_takeover(): void {
		global $wpdb;

		$jobs   = new Jobs();
		$job_id = $jobs->create_job(
			array(
				'pipeline_id'       => 'direct',
				'flow_id'           => 'direct',
				'operation_state'   => 'preparing',
				'operation_step_id' => 'ephemeral_step_0',
			)
		);
		$first_claim = $jobs->claim_operation_enqueue( $job_id );
		$this->assertIsArray( $first_claim );
		$action_args = array(
			'job_id'               => $job_id,
			'flow_step_id'         => 'ephemeral_step_0',
			'operation_generation' => $first_claim['generation'],
			'operation_claim_token' => $first_claim['token'],
		);

		$before_takeover = ( new ExecuteStepAbility() )->execute( $action_args );
		$this->assertTrue( $before_takeover['deferred'] );
		$this->assertSame( 'pending', $jobs->get_job( $job_id )['status'] );

		$wpdb->update(
			$wpdb->prefix . 'datamachine_jobs',
			array( 'operation_claimed_at' => '2000-01-01 00:00:00' ),
			array( 'job_id' => $job_id )
		);
		$takeover = $jobs->claim_operation_enqueue( $job_id );
		$this->assertIsArray( $takeover );
		$this->assertGreaterThan( $first_claim['generation'], $takeover['generation'] );

		$after_takeover = ( new ExecuteStepAbility() )->execute( $action_args );
		$this->assertTrue( $after_takeover['stale_generation'] );
		$this->assertArrayNotHasKey( 'deferred', $after_takeover );
		$this->assertSame( 'pending', $jobs->get_job( $job_id )['status'] );
	}

	public function test_processing_direct_workflow_retry_waits_for_live_generation(): void {
		wp_set_current_user( $this->owner_id );
		$created = $this->execute( 'processing-direct-retry' );
		$jobs    = new Jobs();
		$original_job        = $jobs->get_job( (int) $created['job_id'] );
		$original_action_id  = (int) $original_job['operation_action_id'];
		$original_generation = (int) $original_job['operation_generation'];
		$this->assertTrue( $jobs->start_job( (int) $created['job_id'] ) );

		$retry = ( new RetryJobAbility() )->execute( array( 'job_id' => $created['job_id'] ) );
		$job   = $jobs->get_job( (int) $created['job_id'] );

		$this->assertFalse( $retry['success'] );
		$this->assertTrue( $retry['retryable'] );
		$this->assertSame( 'job_execution_in_progress', $retry['error_code'] );
		$this->assertSame( 'processing', $job['status'] );
		$this->assertSame( 'enqueued', $job['operation_state'] );
		$this->assertSame( $original_action_id, (int) $job['operation_action_id'] );
		$this->assertSame( $original_generation, (int) $job['operation_generation'] );
	}

	public function test_processing_multistep_retry_detects_live_action_for_different_step(): void {
		wp_set_current_user( $this->owner_id );
		$created = $this->execute( 'processing-multistep-retry' );
		$jobs    = new Jobs();
		$job_id  = (int) $created['job_id'];
		$engine_data = $jobs->retrieve_engine_data( $job_id );
		$first_step  = $engine_data['flow_config']['ephemeral_step_0'];
		$second_step = $first_step;
		$second_step['flow_step_id']   = 'ephemeral_step_1';
		$second_step['execution_order'] = 1;
		$engine_data['flow_config']['ephemeral_step_1'] = $second_step;
		$engine_data['resumable'] = true;
		$engine_data['step_results']['ephemeral_step_0'] = array( 'step_success' => true );
		$this->assertTrue( $jobs->store_engine_data( $job_id, $engine_data ) );
		$this->assertSame( 'ephemeral_step_1', JobRetryPolicy::resolveDirectResumeStepId( $engine_data ) );
		$this->assertTrue( $jobs->start_job( $job_id ) );

		$retry = ( new RetryJobAbility() )->execute( array( 'job_id' => $job_id ) );

		$this->assertFalse( $retry['success'] );
		$this->assertSame( 'job_execution_in_progress', $retry['error_code'] );
		$this->assertSame( 'processing', $jobs->get_job( $job_id )['status'] );
	}

	public function test_failed_direct_retry_uses_next_generation_while_old_action_remains(): void {
		wp_set_current_user( $this->owner_id );
		$created = $this->execute( 'failed-direct-retry-generation' );
		$jobs    = new Jobs();
		$original_job        = $jobs->get_job( (int) $created['job_id'] );
		$original_action_id  = (int) $original_job['operation_action_id'];
		$original_generation = (int) $original_job['operation_generation'];
		$this->assertTrue( $jobs->complete_job( (int) $created['job_id'], 'failed - test' ) );

		$retry = ( new RetryJobAbility() )->execute( array( 'job_id' => $created['job_id'] ) );
		$job   = $jobs->get_job( (int) $created['job_id'] );

		$this->assertTrue( $retry['success'] );
		$this->assertTrue( $retry['direct_requeued'] );
		$this->assertSame( 'pending', $job['status'] );
		$this->assertNotSame( $original_action_id, (int) $job['operation_action_id'] );
		$this->assertGreaterThan( $original_generation, (int) $job['operation_generation'] );

		$stale = ( new ExecuteStepAbility() )->execute(
			array(
				'job_id'               => (int) $created['job_id'],
				'flow_step_id'          => 'ephemeral_step_0',
				'operation_generation' => $original_generation,
				'operation_claim_token' => (string) $original_job['operation_claim_token'],
			)
		);
		$this->assertTrue( $stale['stale_generation'] );
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
				'workflow'      => $this->workflow( $prompt ),
				'operation_key' => $operation_key,
			)
		);
	}

	private function workflow( string $prompt = 'input' ): array {
		return array(
			'steps' => array(
				array(
					'step_type'     => 'ai',
					'system_prompt' => $prompt,
				),
			),
		);
	}
}
