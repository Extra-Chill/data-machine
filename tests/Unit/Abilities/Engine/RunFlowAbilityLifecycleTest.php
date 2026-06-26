<?php
/**
 * Tests for run-flow lifecycle transitions on invalid plans.
 *
 * @package DataMachine\Tests\Unit\Abilities\Engine
 */

namespace DataMachine\Tests\Unit\Abilities\Engine;

use DataMachine\Abilities\Engine\RunFlowAbility;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\JobStatus;
use WP_UnitTestCase;

class RunFlowAbilityLifecycleTest extends WP_UnitTestCase {

	private $schedule_capture;
	private array $scheduled_steps = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( function_exists( 'datamachine_activate_for_site' ) ) {
			datamachine_activate_for_site();
		}
	}

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->scheduled_steps  = array();
		$this->schedule_capture = function ( $job_id, $flow_step_id, $data_packets = array() ): void {
			$this->scheduled_steps[] = compact( 'job_id', 'flow_step_id', 'data_packets' );
		};
		add_action( 'datamachine_schedule_next_step', $this->schedule_capture, 1, 3 );
	}

	public function tear_down(): void {
		remove_action( 'datamachine_schedule_next_step', $this->schedule_capture, 1 );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_no_first_step_marks_created_job_failed(): void {
		$pipeline_id = ( new Pipelines() )->create_pipeline(
			array(
				'pipeline_name'   => 'No First Step Pipeline',
				'pipeline_config' => array(),
				'user_id'         => get_current_user_id(),
			)
		);
		$this->assertIsInt( $pipeline_id );

		$flow_id = ( new Flows() )->create_flow(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => 'No First Step Flow',
				'flow_config'       => array(),
				'scheduling_config' => array( 'enabled' => true ),
				'user_id'           => get_current_user_id(),
			)
		);
		$this->assertIsInt( $flow_id );

		$result = ( new RunFlowAbility() )->execute( array( 'flow_id' => $flow_id ) );

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'no_first_step', $result['reason'] ?? '' );
		$this->assertIsInt( $result['job_id'] ?? null );
		$this->assertSame( array(), $this->scheduled_steps );

		$job = ( new Jobs() )->get_job( (int) $result['job_id'] );
		$this->assertNotEmpty( $job );
		$this->assertSame( JobStatus::failed( 'no_first_step' )->toString(), $job['status'] ?? '' );
	}

	public function test_scheduler_run_is_deferred_when_active_jobs_exceed_ceiling(): void {
		$jobs = new Jobs();

		// Saturate the queue with in-flight (pending) jobs.
		$jobs->create_job( array( 'source' => 'pipeline', 'label' => 'inflight 1' ) );
		$jobs->create_job( array( 'source' => 'pipeline', 'label' => 'inflight 2' ) );

		$pipeline_id = ( new Pipelines() )->create_pipeline(
			array(
				'pipeline_name'   => 'Backpressure Pipeline',
				'pipeline_config' => array(),
				'user_id'         => get_current_user_id(),
			)
		);
		$flow_id = ( new Flows() )->create_flow(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => 'Backpressure Flow',
				'flow_config'       => array(),
				'scheduling_config' => array( 'enabled' => true ),
				'user_id'           => get_current_user_id(),
			)
		);
		$this->assertIsInt( $flow_id );

		$jobs_before = $jobs->count_active_jobs();

		// Force the ceiling below the current in-flight count so the next
		// scheduler-triggered run must defer.
		$cap = static fn() => 1;
		add_filter( 'datamachine_max_active_jobs', $cap );

		$result = ( new RunFlowAbility() )->execute(
			array(
				'flow_id'        => $flow_id,
				'respect_paused' => true,
			)
		);

		remove_filter( 'datamachine_max_active_jobs', $cap );

		$this->assertTrue( $result['success'] ?? false );
		$this->assertTrue( $result['skipped'] ?? false );
		$this->assertSame( 'queue_backpressure', $result['reason'] ?? '' );
		// On a backpressure defer the result carries an explicit null job_id
		// (no job admitted). Assert the key exists and is null directly — the
		// `?? 'unset'` idiom would coerce a legitimate null into the default
		// and make assertNull() impossible to satisfy.
		$this->assertArrayHasKey( 'job_id', $result );
		$this->assertNull( $result['job_id'] );

		// No new job was admitted.
		$this->assertSame( $jobs_before, $jobs->count_active_jobs() );
		$this->assertSame( array(), $this->scheduled_steps );
	}

	public function test_throttling_disabled_when_ceiling_is_zero(): void {
		$jobs = new Jobs();
		$jobs->create_job( array( 'source' => 'pipeline', 'label' => 'inflight a' ) );
		$jobs->create_job( array( 'source' => 'pipeline', 'label' => 'inflight b' ) );

		$pipeline_id = ( new Pipelines() )->create_pipeline(
			array(
				'pipeline_name'   => 'No Throttle Pipeline',
				'pipeline_config' => array(),
				'user_id'         => get_current_user_id(),
			)
		);
		$flow_id = ( new Flows() )->create_flow(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => 'No Throttle Flow',
				'flow_config'       => array(),
				'scheduling_config' => array( 'enabled' => true ),
				'user_id'           => get_current_user_id(),
			)
		);

		// 0 disables admission throttling — the run proceeds and admits a job
		// (it will fail later on no_first_step, but a job_id is created, proving
		// admission was not deferred).
		$cap = static fn() => 0;
		add_filter( 'datamachine_max_active_jobs', $cap );

		$result = ( new RunFlowAbility() )->execute(
			array(
				'flow_id'        => $flow_id,
				'respect_paused' => true,
			)
		);

		remove_filter( 'datamachine_max_active_jobs', $cap );

		$this->assertNotSame( 'queue_backpressure', $result['reason'] ?? '' );
		$this->assertIsInt( $result['job_id'] ?? null );
	}

	public function test_completed_parent_run_result_includes_available_child_envelopes(): void {
		$jobs = new Jobs();

		$parent_job_id = $jobs->create_job(
			array(
				'source' => 'pipeline',
				'label'  => 'Parent run result envelope test',
			)
		);
		$this->assertIsInt( $parent_job_id );

		$child_job_id = $jobs->create_job(
			array(
				'source'        => 'pipeline',
				'label'         => 'Child run result envelope test',
				'parent_job_id' => $parent_job_id,
			)
		);
		$this->assertIsInt( $child_job_id );

		datamachine_set_engine_data(
			$child_job_id,
			array(
				'job'        => array( 'job_id' => $child_job_id ),
				'run_result' => array(
					'schema_version' => 'datamachine.run_result.v1',
					'job'            => array( 'job_id' => $child_job_id ),
					'status'         => JobStatus::COMPLETED,
				),
			)
		);

		datamachine_set_engine_data(
			$parent_job_id,
			array(
				'job'           => array( 'job_id' => $parent_job_id ),
				'batch_results' => array(
					'completed' => 1,
					'failed'    => 0,
					'skipped'   => 0,
					'total'     => 1,
				),
			)
		);

		$this->assertTrue( $jobs->complete_job( $parent_job_id, JobStatus::COMPLETED ) );

		$parent_engine = datamachine_get_engine_data( $parent_job_id );
		$this->assertSame( 'datamachine.run_result.v1', $parent_engine['run_result']['schema_version'] ?? '' );
		$this->assertSame( $child_job_id, $parent_engine['run_result']['child_job_envelopes'][0]['job']['job_id'] ?? 0 );
	}
}
