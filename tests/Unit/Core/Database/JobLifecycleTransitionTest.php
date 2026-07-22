<?php
/**
 * Tests for job lifecycle status transitions.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Jobs\LegacyAIConcurrencyReconciler;
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

	public function test_cancelled_is_generic_terminal_status(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Cancelled terminal lifecycle' ) );
		$this->assertIsInt( $job_id );

		$this->assertTrue( $this->db_jobs->start_job( $job_id, JobStatus::PROCESSING ) );

		$cancelled = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::CANCELLED, true );
		$restart   = $this->db_jobs->start_job( $job_id, JobStatus::PROCESSING );

		$this->assertTrue( $cancelled['success'] );
		$this->assertTrue( $cancelled['changed'] );
		$this->assertFalse( $restart );
		$this->assertTrue( JobStatus::isStatusFinal( JobStatus::CANCELLED ) );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::CANCELLED, $job['status'] );
	}

	public function test_exact_legacy_ai_contention_failure_can_be_audited_and_reclassified(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Legacy AI contention' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $this->db_jobs->complete_job( $job_id, LegacyAIConcurrencyReconciler::SOURCE_STATUS ) );

		$generic_rewrite = $this->db_jobs->transition_job_status_result( $job_id, LegacyAIConcurrencyReconciler::TARGET_STATUS, true );
		$this->assertFalse( $generic_rewrite['success'] );

		$audits          = array();
		$terminal_hooks  = array();
		$audit_listener  = static function ( int $reconciled_job_id, array $audit ) use ( &$audits ): void {
			$audits[] = array( $reconciled_job_id, $audit );
		};
		$terminal_listener = static function ( int $completed_job_id, string $status ) use ( &$terminal_hooks ): void {
			$terminal_hooks[] = array( $completed_job_id, $status );
		};
		add_action( 'datamachine_job_status_reconciled', $audit_listener, 10, 2 );
		add_action( 'datamachine_job_complete', $terminal_listener, 10, 2 );
		try {
			$reconciled = ( new LegacyAIConcurrencyReconciler() )->reconcile( $job_id );
		} finally {
			remove_action( 'datamachine_job_status_reconciled', $audit_listener, 10 );
			remove_action( 'datamachine_job_complete', $terminal_listener, 10 );
		}
		$this->assertTrue( $reconciled['success'] );
		$this->assertTrue( $reconciled['changed'] );
		$this->assertSame( LegacyAIConcurrencyReconciler::SOURCE_STATUS, $reconciled['current_status'] );
		$this->assertSame( LegacyAIConcurrencyReconciler::TARGET_STATUS, $reconciled['status'] );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( LegacyAIConcurrencyReconciler::TARGET_STATUS, $job['status'] );
		$this->assertSame( 'datamachine.status_reconciliation.v1', $job['engine_data']['status_reconciliation']['schema'] );
		$this->assertSame( LegacyAIConcurrencyReconciler::SOURCE_STATUS, $job['engine_data']['status_reconciliation']['source_status'] );
		$this->assertSame( LegacyAIConcurrencyReconciler::TARGET_STATUS, $job['engine_data']['status_reconciliation']['target_status'] );
		$this->assertSame( 0, $job['engine_data']['run_metrics']['counts']['failed'] );
		$this->assertCount( 1, $audits );
		$this->assertSame( $job_id, $audits[0][0] );
		$this->assertSame( array(), $terminal_hooks );

		$idempotent = ( new LegacyAIConcurrencyReconciler() )->reconcile( $job_id );
		$this->assertTrue( $idempotent['success'] );
		$this->assertFalse( $idempotent['changed'] );
	}

	public function test_legacy_ai_contention_reconciliation_rejects_every_other_terminal_status(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Unrelated terminal status' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::failed( 'handler_failure' )->toString() ) );

		$reconciled = ( new LegacyAIConcurrencyReconciler() )->reconcile( $job_id );
		$this->assertFalse( $reconciled['success'] );
		$this->assertFalse( $reconciled['changed'] );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( 'failed - handler_failure', $job['status'] );
		$this->assertArrayNotHasKey( 'status_reconciliation', $job['engine_data'] );
	}

	public function test_create_or_get_job_returns_existing_job_for_same_idempotency_key(): void {
		$idempotency_key = 'unit-idempotent-job-' . wp_generate_uuid4();

		$first = $this->db_jobs->create_or_get_job(
			array(
				'label'           => 'First idempotent job',
				'idempotency_key' => $idempotency_key,
			)
		);

		$this->assertIsArray( $first );
		$this->assertTrue( $first['created'] );
		$this->assertFalse( $first['already_exists'] );
		$this->assertIsInt( $first['job_id'] );

		$second = $this->db_jobs->create_or_get_job(
			array(
				'label'           => 'Second idempotent job',
				'idempotency_key' => $idempotency_key,
			)
		);

		$this->assertIsArray( $second );
		$this->assertFalse( $second['created'] );
		$this->assertTrue( $second['already_exists'] );
		$this->assertSame( $first['job_id'], $second['job_id'] );
		$this->assertSame( 'First idempotent job', $second['job']['label'] );
		$this->assertSame( $idempotency_key, $second['job']['idempotency_key'] );
	}

	public function test_create_or_get_job_requires_idempotency_key(): void {
		$this->assertFalse( $this->db_jobs->create_or_get_job( array( 'label' => 'Missing idempotency key' ) ) );
	}
}
