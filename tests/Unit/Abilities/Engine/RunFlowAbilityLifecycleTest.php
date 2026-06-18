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
}
