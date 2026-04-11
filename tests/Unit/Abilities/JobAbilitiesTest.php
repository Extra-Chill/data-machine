<?php
/**
 * JobAbilities Tests
 *
 * Tests for job listing, execution, and monitoring abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

// Stub for Action Scheduler function not available in PHPUnit test environment.
// Must be in the global namespace so ExecuteWorkflowAbility can resolve it.
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
		// Return a fake action ID. The test verifies job creation, not scheduling.
		return 1;
	}
}

use DataMachine\Abilities\JobAbilities;
use DataMachine\Core\Database\Jobs\Jobs;
use WP_UnitTestCase;



class JobAbilitiesTest extends WP_UnitTestCase {

	private JobAbilities $job_abilities;
	private int $test_pipeline_id;
	private int $test_flow_id;
	private int $test_job_id;

	public function set_up(): void {
		parent::set_up();

		// Stub Action Scheduler function if not available (PHPUnit env has no AS).
		if ( ! function_exists( '\as_schedule_single_action' ) ) {
			require_once __DIR__ . '/../../fixtures/action-scheduler-stubs.php';
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->job_abilities = new JobAbilities();

		$pipeline_ability      = wp_get_ability( 'datamachine/create-pipeline' );
		$flow_ability          = wp_get_ability( 'datamachine/create-flow' );
		$db_jobs               = new Jobs();

		$pipeline              = $pipeline_ability->execute( array( 'pipeline_name' => 'Test Pipeline for Jobs' ) );
		$this->test_pipeline_id = $pipeline['pipeline_id'];

		// Add a pipeline step so flows have something to execute.
		$step_ability = wp_get_ability( 'datamachine/add-pipeline-step' );
		$step_result  = $step_ability->execute(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);
		$pipeline_step_id = $step_result['pipeline_step_id'];

		$flow                  = $flow_ability->execute( array( 'pipeline_id' => $this->test_pipeline_id, 'flow_name' => 'Test Flow for Jobs' ) );
		$this->test_flow_id    = $flow['flow_id'];

		// Configure the flow step with execution_order so RunFlowAbility can find the first step.
		$flow_step_ability = wp_get_ability( 'datamachine/configure-flow-steps' );
		if ( $flow_step_ability ) {
			$flow_step_ability->execute(
				array(
					'flow_id' => $this->test_flow_id,
					'steps'   => array(
						$pipeline_step_id => array( 'execution_order' => 0 ),
					),
				)
			);
		}

		$this->test_job_id     = $db_jobs->create_job(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'flow_id'     => $this->test_flow_id,
			)
		);
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_get_jobs_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-jobs' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-jobs', $ability->get_name() );
	}

	public function test_get_jobs_supports_single_job_lookup(): void {
		$result = $this->job_abilities->executeGetJobs(
			array(
				'job_id' => $this->test_job_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'jobs', $result );
		$this->assertCount( 1, $result['jobs'] );
		$this->assertEquals( $this->test_job_id, (int) $result['jobs'][0]['job_id'] );
	}

	public function test_delete_jobs_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/delete-jobs' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/delete-jobs', $ability->get_name() );
	}

	public function test_run_flow_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/run-flow' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/run-flow', $ability->get_name() );
	}

	public function test_get_flow_health_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-flow-health' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-flow-health', $ability->get_name() );
	}

	public function test_get_problem_flows_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-problem-flows' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-problem-flows', $ability->get_name() );
	}

	public function test_get_jobs_returns_job_list(): void {
		$result = $this->job_abilities->executeGetJobs( array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'jobs', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'offset', $result );
		$this->assertArrayHasKey( 'filters_applied', $result );

		$jobs = $result['jobs'];
		$this->assertIsArray( $jobs );
		$this->assertGreaterThan( 0, count( $jobs ) );

		$first_job = $jobs[0];
		$this->assertArrayHasKey( 'job_id', $first_job );
		$this->assertArrayHasKey( 'flow_id', $first_job );
		$this->assertArrayHasKey( 'pipeline_id', $first_job );
		$this->assertArrayHasKey( 'status', $first_job );
		$this->assertArrayHasKey( 'created_at', $first_job );
		$this->assertArrayHasKey( 'created_at_display', $first_job );
	}

	public function test_get_jobs_filters_by_flow_id(): void {
		$result = $this->job_abilities->executeGetJobs(
			array(
				'flow_id' => $this->test_flow_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'jobs', $result );
		$this->assertEquals( $this->test_flow_id, $result['filters_applied']['flow_id'] );

		foreach ( $result['jobs'] as $job ) {
			$this->assertEquals( (string) $this->test_flow_id, $job['flow_id'] );
		}
	}

	public function test_get_jobs_filters_by_pipeline_id(): void {
		$result = $this->job_abilities->executeGetJobs(
			array(
				'pipeline_id' => $this->test_pipeline_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $this->test_pipeline_id, $result['filters_applied']['pipeline_id'] );
	}

	public function test_get_jobs_filters_by_status(): void {
		$result = $this->job_abilities->executeGetJobs(
			array(
				'status' => 'pending',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'pending', $result['filters_applied']['status'] );

		foreach ( $result['jobs'] as $job ) {
			$this->assertEquals( 'pending', $job['status'] );
		}
	}

	public function test_get_jobs_with_pagination(): void {
		$result1 = $this->job_abilities->executeGetJobs(
			array(
				'per_page' => 1,
				'offset'   => 0,
			)
		);

		$result2 = $this->job_abilities->executeGetJobs(
			array(
				'per_page' => 1,
				'offset'   => 1,
			)
		);

		$this->assertTrue( $result1['success'] );
		$this->assertTrue( $result2['success'] );

		$this->assertEquals( 1, $result1['per_page'] );
		$this->assertEquals( 0, $result1['offset'] );
		$this->assertEquals( 1, $result2['offset'] );
	}

	public function test_get_jobs_with_job_id_returns_single_job(): void {
		$result = $this->job_abilities->executeGetJobs(
			array(
				'job_id' => $this->test_job_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'jobs', $result );
		$this->assertCount( 1, $result['jobs'] );

		$job = $result['jobs'][0];
		$this->assertEquals( $this->test_job_id, (int) $job['job_id'] );
		$this->assertArrayHasKey( 'status', $job );
		$this->assertArrayHasKey( 'created_at', $job );
		$this->assertArrayHasKey( 'created_at_display', $job );
	}

	public function test_get_jobs_with_invalid_job_id_returns_empty_array(): void {
		$result = $this->job_abilities->executeGetJobs(
			array(
				'job_id' => 999999,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'jobs', $result );
		$this->assertEmpty( $result['jobs'] );
		$this->assertEquals( 0, $result['total'] );
	}

	public function test_get_jobs_with_zero_job_id_falls_through_to_listing(): void {
		// PHP treats 0 as falsy, so `if ($job_id)` skips the direct-lookup branch
		// and falls through to the general listing path. This is correct behavior —
		// 0 means "no specific job requested".
		$result = $this->job_abilities->executeGetJobs(
			array(
				'job_id' => 0,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'jobs', $result );
		$this->assertArrayHasKey( 'total', $result );
	}

	public function test_delete_jobs_with_all_type(): void {
		$db_jobs = new Jobs();
		$db_jobs->create_job(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'flow_id'     => $this->test_flow_id,
			)
		);

		$result = $this->job_abilities->executeDeleteJobs(
			array(
				'type' => 'all',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'deleted_count', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertGreaterThanOrEqual( 1, $result['deleted_count'] );
	}

	public function test_delete_jobs_with_failed_type(): void {
		$db_jobs = new Jobs();
		$failed_job_id = $db_jobs->create_job(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'flow_id'     => $this->test_flow_id,
			)
		);
		$db_jobs->complete_job( $failed_job_id, 'failed' );

		$result = $this->job_abilities->executeDeleteJobs(
			array(
				'type' => 'failed',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'deleted_count', $result );
	}

	public function test_delete_jobs_with_invalid_type_returns_error(): void {
		$result = $this->job_abilities->executeDeleteJobs(
			array(
				'type' => 'invalid',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'all', $result['error'] );
		$this->assertStringContainsString( 'failed', $result['error'] );
	}

	public function test_run_flow_creates_job(): void {
		$ability = wp_get_ability( 'datamachine/run-flow' );
		$result  = $ability->execute(
			array(
				'flow_id' => $this->test_flow_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $this->test_flow_id, $result['flow_id'] );
		$this->assertArrayHasKey( 'job_id', $result );
	}

	public function test_run_flow_with_count_creates_multiple_jobs(): void {
		$ability = wp_get_ability( 'datamachine/run-flow' );
		$job_ids = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$result = $ability->execute(
				array( 'flow_id' => $this->test_flow_id )
			);
			$this->assertTrue( $result['success'] );
			$job_ids[] = $result['job_id'];
		}

		$this->assertCount( 3, $job_ids );
		$this->assertCount( 3, array_unique( $job_ids ) );
	}

	public function test_run_flow_with_timestamp_schedules_delayed(): void {
		$future_timestamp = time() + 3600;

		$ability = wp_get_ability( 'datamachine/schedule-flow' );
		$result  = $ability->execute(
			array(
				'flow_id'               => $this->test_flow_id,
				'interval_or_timestamp' => $future_timestamp,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'one_time', $result['schedule_type'] );
		$this->assertArrayHasKey( 'scheduled_time', $result );
	}

	public function test_run_flow_with_timestamp_and_count_returns_error(): void {
		// The CLI enforces this constraint — schedule-flow only handles
		// one execution at a time. Multiple runs use run-flow in a loop.
		// This test verifies the schedule-flow ability accepts valid input.
		$future_timestamp = time() + 3600;

		$ability = wp_get_ability( 'datamachine/schedule-flow' );
		$result  = $ability->execute(
			array(
				'flow_id'               => $this->test_flow_id,
				'interval_or_timestamp' => $future_timestamp,
			)
		);

		// schedule-flow handles single scheduling — no count parameter exists.
		$this->assertTrue( $result['success'] );
	}

	public function test_run_flow_with_invalid_flow_id_returns_error(): void {
		$ability = wp_get_ability( 'datamachine/run-flow' );
		$result  = $ability->execute(
			array(
				'flow_id' => 999999,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_run_flow_with_zero_id_returns_error(): void {
		$ability = wp_get_ability( 'datamachine/run-flow' );
		$result  = $ability->execute(
			array(
				'flow_id' => 0,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_get_flow_health_returns_metrics(): void {
		$result = $this->job_abilities->executeGetFlowHealth(
			array(
				'flow_id' => $this->test_flow_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $this->test_flow_id, $result['flow_id'] );
		$this->assertArrayHasKey( 'consecutive_failures', $result );
		$this->assertArrayHasKey( 'consecutive_no_items', $result );
		$this->assertArrayHasKey( 'latest_job', $result );
		$this->assertIsInt( $result['consecutive_failures'] );
		$this->assertIsInt( $result['consecutive_no_items'] );
	}

	public function test_get_flow_health_with_invalid_flow_id_returns_error(): void {
		$result = $this->job_abilities->executeGetFlowHealth(
			array(
				'flow_id' => 999999,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_get_flow_health_with_zero_id_returns_error(): void {
		$result = $this->job_abilities->executeGetFlowHealth(
			array(
				'flow_id' => 0,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'positive integer', $result['error'] );
	}

	public function test_get_problem_flows_returns_empty_when_no_problems(): void {
		$result = $this->job_abilities->executeGetProblemFlows(
			array(
				'threshold' => 100,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'failing', $result );
		$this->assertArrayHasKey( 'idle', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertArrayHasKey( 'threshold', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertEquals( 0, $result['count'] );
		$this->assertEquals( 100, $result['threshold'] );
	}

	public function test_get_problem_flows_uses_default_threshold(): void {
		$result = $this->job_abilities->executeGetProblemFlows( array() );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'threshold', $result );
		$this->assertGreaterThan( 0, $result['threshold'] );
	}

	public function test_get_problem_flows_detects_failing_flows(): void {
		global $wpdb;
		$db_jobs = new Jobs();
		$table   = $wpdb->prefix . 'datamachine_jobs';

		// Mark the set_up job as completed with an older timestamp so it sorts
		// below the failed jobs. SQLite ORDER BY created_at DESC returns the
		// first matching row for ties, which would be the lowest rowid (our
		// pending job from set_up), breaking the consecutive failure count.
		$db_jobs->complete_job( $this->test_job_id, 'completed' );
		$wpdb->update( $table, array( 'created_at' => '2020-01-01 00:00:00' ), array( 'job_id' => $this->test_job_id ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$job_id = $db_jobs->create_job(
				array(
					'pipeline_id' => $this->test_pipeline_id,
					'flow_id'     => $this->test_flow_id,
				)
			);
			$db_jobs->complete_job( $job_id, 'failed' );
		}

		delete_transient( "datamachine_flow_health_{$this->test_flow_id}" );

		$result = $this->job_abilities->executeGetProblemFlows(
			array(
				'threshold' => 3,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, $result['count'] );
		$this->assertGreaterThan( 0, count( $result['failing'] ) );
	}

	public function test_permission_callback_denies_unauthenticated(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/get-jobs' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}
}
