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
