<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration coverage verifies Data Machine-owned jobs and Action Scheduler rows directly.
/**
 * Stale claim reconciler coverage for crashed runs.
 *
 * Covers the detection rule (no live Action Scheduler action + stale
 * activity), resume from the last completed step, the bounded attempt
 * counter, and loud terminalization when resumption is not safe.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\StaleClaimReconciler;
use WP_UnitTestCase;

class StaleClaimReconcilerTest extends WP_UnitTestCase {

	private const HOOK = 'datamachine_execute_step';

	private Jobs $jobs;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		Jobs::create_table();
		$this->jobs = new Jobs();
	}

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_processing_job_with_live_pending_action_is_not_reconciled(): void {
		$job_id = $this->crashedFlowJob();
		as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			self::HOOK,
			array(
				'job_id'       => $job_id,
				'flow_step_id' => 'step-two',
			),
			'data-machine'
		);

		$summary = ( new StaleClaimReconciler() )->reconcile();

		$this->assertSame( 1, $summary['scanned'] );
		$this->assertSame( 0, $summary['requeued'] );
		$this->assertSame( 0, $summary['terminalized'] );
		$this->assertSame( 'skipped', $summary['details'][0]['outcome'] );
		$this->assertSame( 'live_action_exists', $summary['details'][0]['detail'] );
		$this->assertSame( JobStatus::PROCESSING, $this->jobs->get_job( $job_id )['status'] );
	}

	public function test_actionless_stale_job_resumes_from_last_completed_step(): void {
		$job_id = $this->crashedFlowJob();

		$summary = ( new StaleClaimReconciler() )->reconcile();

		$this->assertSame( 1, $summary['requeued'] );
		$this->assertSame( 'requeued', $summary['details'][0]['outcome'] );
		$this->assertSame( 'resumed_from_last_completed_step', $summary['details'][0]['detail'] );
		$this->assertSame( 'step-two', $summary['details'][0]['resume_step_id'] );

		$job = $this->jobs->get_job( $job_id );
		$this->assertSame( JobStatus::PENDING, $job['status'] );

		$engine = datamachine_get_engine_data( $job_id );
		$this->assertSame( 1, (int) ( $engine['retry']['attempts'] ?? 0 ) );
		$this->assertSame( 'resumed', (string) ( $engine['stale_claim_reconciliation']['state'] ?? '' ) );

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => self::HOOK,
				'status' => 'pending',
				'args'   => array(
					'job_id'       => $job_id,
					'flow_step_id' => 'step-two',
				),
			),
			'ids'
		);
		$this->assertNotEmpty( $pending, 'The resume action must be scheduled for the first incomplete step.' );
	}

	public function test_resume_attempt_bound_terminalizes_instead_of_looping(): void {
		$job_id                      = $this->crashedFlowJob();
		$engine                      = datamachine_get_engine_data( $job_id );
		$engine['retry']['attempts'] = StaleClaimReconciler::maxResumeAttempts();
		$this->assertTrue( datamachine_set_engine_data( $job_id, $engine ) );

		$summary = ( new StaleClaimReconciler() )->reconcile();

		$this->assertSame( 1, $summary['terminalized'] );
		$this->assertSame( 'terminalized', $summary['details'][0]['outcome'] );
		$this->assertSame( 'resume_attempt_bound_reached', $summary['details'][0]['detail'] );
		$this->assertSame( 'failed - stale_claim_resume_exhausted', $this->jobs->get_job( $job_id )['status'] );
	}

	public function test_direct_job_with_begun_effects_terminalizes_rather_than_resumes(): void {
		global $wpdb;
		$job_id = $this->jobs->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'source'      => 'system',
				'label'       => 'Crashed direct job',
			)
		);
		$this->assertTrue( $this->jobs->start_job( $job_id ) );
		$this->assertTrue(
			datamachine_merge_engine_data(
				$job_id,
				array(
					'run_metrics' => array(
						'started_at'       => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						'last_activity_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					),
				)
			)
		);
		$wpdb->update(
			$wpdb->prefix . Jobs::TABLE_NAME,
			array(
				'operation_state'            => 'enqueued',
				'operation_action_id'        => 987654321,
				'operation_generation'       => 2,
				'operation_claim_token'      => wp_generate_password( 32, false, false ),
				'operation_step_id'          => 'step-one',
				'operation_effects_begun_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			),
			array( 'job_id' => $job_id )
		);
		$this->backdateCreated( $job_id );

		$summary = ( new StaleClaimReconciler() )->reconcile();

		$this->assertSame( 1, $summary['terminalized'] );
		$this->assertSame( 'terminalized', $summary['details'][0]['outcome'] );
		$this->assertSame( 'failed - scheduler_path_lost_after_effects', $this->jobs->get_job( $job_id )['status'] );
	}

	public function test_mid_step_death_terminalizes_with_worker_died_reason(): void {
		$job_id                             = $this->crashedFlowJob();
		$engine                             = datamachine_get_engine_data( $job_id );
		$engine['step_results']['step-two'] = array(
			'flow_step_id' => 'step-two',
			'step_success' => false,
			'result'       => 'failed',
			'recorded_at'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
		);
		$this->assertTrue( datamachine_set_engine_data( $job_id, $engine ) );

		$summary = ( new StaleClaimReconciler() )->reconcile();

		$this->assertSame( 1, $summary['terminalized'] );
		$this->assertSame( 'worker_died_mid_step', $summary['details'][0]['detail'] );
		$this->assertSame( 'failed - worker_died_mid_step', $this->jobs->get_job( $job_id )['status'] );
	}

	public function test_recent_activity_is_not_reconciled(): void {
		$job_id = $this->crashedFlowJob();
		RunMetrics::start( $job_id );

		$summary = ( new StaleClaimReconciler() )->reconcile();

		$this->assertSame( 1, $summary['scanned'] );
		$this->assertSame( 0, $summary['requeued'] );
		$this->assertSame( 0, $summary['terminalized'] );
		$this->assertSame( 'recent_activity', $summary['details'][0]['detail'] );
		$this->assertSame( JobStatus::PROCESSING, $this->jobs->get_job( $job_id )['status'] );
	}

	/**
	 * Create a processing two-step flow job whose first step completed and
	 * whose created_at is older than the activity threshold — the exact
	 * production crash shape from issue #3478.
	 *
	 * @return int Job ID.
	 */
	private function crashedFlowJob(): int {
		$job_id = $this->jobs->create_job(
			array(
				'pipeline_id' => 1,
				'flow_id'     => 208,
				'source'      => 'flow',
				'label'       => 'Crashed flow job',
			)
		);
		$this->assertIsInt( $job_id );
		$this->assertTrue( $this->jobs->start_job( $job_id ) );

		$this->assertTrue(
			datamachine_merge_engine_data(
				$job_id,
				array(
					'flow_config'  => array(
						'step-one' => array(
							'step_type'       => 'fetch',
							'execution_order' => 1,
							'flow_step_id'    => 'step-one',
						),
						'step-two' => array(
							'step_type'       => 'publish',
							'execution_order' => 2,
							'flow_step_id'    => 'step-two',
						),
					),
					'step_results' => array(
						'step-one' => array(
							'flow_step_id' => 'step-one',
							'step_success' => true,
							'result'       => 'completed',
							'recorded_at'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						),
					),
					'run_metrics'  => array(
						'started_at'       => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						'last_activity_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					),
				)
			)
		);

		$this->backdateCreated( $job_id );
		return $job_id;
	}

	/** Backdate created_at past the reconciliation activity threshold. */
	private function backdateCreated( int $job_id, int $age_seconds = 2 * HOUR_IN_SECONDS ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Jobs::TABLE_NAME,
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $age_seconds ) ),
			array( 'job_id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
