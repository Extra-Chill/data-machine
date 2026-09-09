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

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();

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
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'datamachine_run_flow_now' );
		}
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

		$this->assertWPError( $result );
		$this->assertSame( 'no_first_step', $result->get_error_code() );
		$this->assertSame( 'Flow execution failed - no first step found.', $result->get_error_message() );
		$error_data = $result->get_error_data();
		$this->assertSame( 400, $error_data['status'] );
		$this->assertFalse( $error_data['retryable'] );
		$this->assertSame( $flow_id, $error_data['flow_id'] );
		$this->assertIsInt( $error_data['job_id'] );
		$this->assertSame( array(), $this->scheduled_steps );

		$job = ( new Jobs() )->get_job( $error_data['job_id'] );
		$this->assertNotEmpty( $job );
		$this->assertSame( JobStatus::FAILED, $job['status'] ?? '' );
		$this->assertSame( 'no_first_step', $job['engine_data']['job_status_reason'] ?? '' );
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
		$flow_id     = ( new Flows() )->create_flow(
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

	public function test_deferral_tick_is_rescheduled_when_queue_is_still_saturated(): void {
		$jobs = new Jobs();
		$jobs->create_job( array( 'source' => 'pipeline', 'label' => 'inflight tick 1' ) );

		$flow_id = $this->create_backpressure_flow( 'Backpressure Tick Flow' );

		$cap              = static fn() => 1;
		$run_scheduler_fn = static fn () => ( new RunFlowAbility() )->execute(
			array(
				'flow_id'        => $flow_id,
				'respect_paused' => true,
			)
		);
		add_filter( 'datamachine_max_active_jobs', $cap );

		// First saturated run defers and schedules a wake-up tick.
		$first = $run_scheduler_fn();
		$this->assertTrue( $first['skipped'] ?? false );

		// Simulate that tick firing: Action Scheduler flips the action from
		// pending to running when the runner claims it. A guard based on
		// as_next_scheduled_action() counts this running action as "already
		// pending" and drops the reschedule.
		$tick_action_id = $this->first_pending_tick_action_id( $flow_id );
		$this->assertGreaterThan( 0, $tick_action_id, 'The first deferral must schedule a wake-up tick.' );
		\ActionScheduler_Store::instance()->log_execution( $tick_action_id );

		// The tick executes while the queue is still saturated: a fresh
		// pending tick must exist afterwards — the run must not be dropped.
		$second = $run_scheduler_fn();

		remove_filter( 'datamachine_max_active_jobs', $cap );

		$this->assertTrue( $second['skipped'] ?? false );

		$pending_ids = \ActionScheduler_Store::instance()->query_actions(
			array(
				'hook'     => 'datamachine_run_flow_now',
				'args'     => array( $flow_id ),
				'group'    => \DataMachine\Core\ActionScheduler\GroupRegistrar::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 10,
			)
		);
		$this->assertNotEmpty( $pending_ids, 'A pending deferral tick must exist after a saturated deferral tick fires.' );

		$action = \ActionScheduler_Store::instance()->fetch_action( (int) reset( $pending_ids ) );
		$date   = $action->get_schedule()->get_date();
		$this->assertNotNull( $date );
		$this->assertGreaterThan( time() - 5, $date->getTimestamp(), 'The replacement tick must be scheduled in the future.' );
	}

	public function test_repeated_deferrals_escalate_to_warning_and_reset_on_admission(): void {
		$jobs = new Jobs();
		$jobs->create_job( array( 'source' => 'pipeline', 'label' => 'inflight warn 1' ) );
		$jobs->create_job( array( 'source' => 'pipeline', 'label' => 'inflight warn 2' ) );

		$flow_id = $this->create_backpressure_flow( 'Backpressure Warning Flow' );

		$deferral_logs = array();
		$logger        = static function ( $level, $message, $context ) use ( &$deferral_logs ): void {
			if ( 'Flow execution deferred - queue backpressure' === $message ) {
				$deferral_logs[] = array( $level, $context );
			}
		};
		add_action( 'datamachine_log', $logger, 10, 3 );

		$cap      = static fn() => 1;
		$run_flow = static fn () => ( new RunFlowAbility() )->execute(
			array(
				'flow_id'        => $flow_id,
				'respect_paused' => true,
			)
		);

		add_filter( 'datamachine_max_active_jobs', $cap );
		for ( $i = 1; $i <= 5; ++$i ) {
			$result = $run_flow();
			$this->assertTrue( $result['skipped'] ?? false );
		}
		remove_filter( 'datamachine_max_active_jobs', $cap );
		remove_action( 'datamachine_log', $logger, 10 );

		$this->assertCount( 5, $deferral_logs );
		$this->assertSame( 'info', $deferral_logs[3][0], 'Deferrals below the threshold must log at info level.' );
		$this->assertSame( 4, $deferral_logs[3][1]['deferral_count'] );
		$this->assertSame( 'warning', $deferral_logs[4][0], 'The 5th consecutive deferral must log at warning level.' );
		$this->assertSame( 5, $deferral_logs[4][1]['deferral_count'] );

		// Once the run is actually admitted, the counter resets.
		$result = ( new RunFlowAbility() )->execute( array( 'flow_id' => $flow_id ) );
		$this->assertWPError( $result );
		$this->assertSame( 'no_first_step', $result->get_error_code() );
		$this->assertIsInt( $result->get_error_data()['job_id'] ?? null );

		$scheduling_config = ( new Flows() )->get_flow( $flow_id )['scheduling_config'] ?? array();
		$this->assertArrayNotHasKey( RunFlowAbility::BACKPRESSURE_DEFERRAL_COUNT_KEY, $scheduling_config );
	}

	/**
	 * Create a pipeline + enabled flow pair for backpressure tests.
	 *
	 * @param string $name Flow name.
	 * @return int Flow ID.
	 */
	private function create_backpressure_flow( string $name ): int {
		$pipeline_id = ( new Pipelines() )->create_pipeline(
			array(
				'pipeline_name'   => $name . ' Pipeline',
				'pipeline_config' => array(),
				'user_id'         => get_current_user_id(),
			)
		);
		$flow_id     = ( new Flows() )->create_flow(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => $name,
				'flow_config'       => array(),
				'scheduling_config' => array( 'enabled' => true ),
				'user_id'           => get_current_user_id(),
			)
		);
		$this->assertIsInt( $flow_id );

		return (int) $flow_id;
	}

	/**
	 * Find the action ID of a pending deferral tick for a flow.
	 *
	 * @param int $flow_id Flow ID.
	 * @return int Action ID, or 0 when none is pending.
	 */
	private function first_pending_tick_action_id( int $flow_id ): int {
		$ids = \ActionScheduler_Store::instance()->query_actions(
			array(
				'hook'     => 'datamachine_run_flow_now',
				'args'     => array( $flow_id ),
				'group'    => \DataMachine\Core\ActionScheduler\GroupRegistrar::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			)
		);

		return is_array( $ids ) && ! empty( $ids ) ? (int) reset( $ids ) : 0;
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
		$flow_id     = ( new Flows() )->create_flow(
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

		$this->assertWPError( $result );
		$this->assertSame( 'no_first_step', $result->get_error_code() );
		$error_data = $result->get_error_data();
		$this->assertSame( 400, $error_data['status'] );
		$this->assertFalse( $error_data['retryable'] );
		$this->assertIsInt( $error_data['job_id'] );
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
