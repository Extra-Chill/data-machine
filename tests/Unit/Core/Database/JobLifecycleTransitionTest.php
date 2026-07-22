<?php
/**
 * Tests for job lifecycle status transitions.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\Jobs\Jobs;
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

	/**
	 * @dataProvider terminal_accounting_interruption_boundaries
	 */
	public function test_terminal_accounting_replays_from_every_post_commit_boundary( string $boundary ): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Terminal accounting ' . $boundary ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $this->db_jobs->start_job( $job_id ) );

		$committed_hooks = 0;
		$complete_hooks  = 0;
		$committed       = static function ( int $hook_job_id ) use ( $job_id, &$committed_hooks ): void {
			if ( $job_id === $hook_job_id ) {
				++$committed_hooks;
			}
		};
		$complete        = static function ( int $hook_job_id ) use ( $job_id, &$complete_hooks ): void {
			if ( $job_id === $hook_job_id ) {
				++$complete_hooks;
			}
		};
		$interrupted     = false;
		$interrupt       = static function ( bool $should_interrupt, string $current_boundary, int $filtered_job_id ) use ( $boundary, $job_id, &$interrupted ): bool {
			if ( ! $interrupted && $job_id === $filtered_job_id && $boundary === $current_boundary ) {
				$interrupted = true;
				return true;
			}
			return $should_interrupt;
		};

		add_action( 'datamachine_job_terminal_committed', $committed, 10, 1 );
		add_action( 'datamachine_job_complete', $complete, 10, 1 );
		add_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10, 3 );
		try {
			try {
				$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
				$this->fail( 'Expected terminal accounting interruption.' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'Terminal accounting interrupted at ' . $boundary, $exception->getMessage() );
			}
		} finally {
			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10 );
			remove_action( 'datamachine_job_terminal_committed', $committed, 10 );
			remove_action( 'datamachine_job_complete', $complete, 10 );
		}

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::COMPLETED, $job['status'] );
		$this->assertLessThan( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $job['terminal_accounting_state'] );
		$this->assertSame( 1, $this->db_jobs->count_incomplete_terminal_accounting() );
		$metrics_completed_at_before   = $job['engine_data']['run_metrics']['completed_at'] ?? null;
		$lifecycle_completed_at_before = $job['engine_data']['run_lifecycle']['completed_at'] ?? null;

		add_action( 'datamachine_job_terminal_committed', $committed, 10, 1 );
		add_action( 'datamachine_job_complete', $complete, 10, 1 );
		try {
			$replayed = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
			$repeated = $this->db_jobs->reconcile_terminal_accounting( $job_id );
		} finally {
			remove_action( 'datamachine_job_terminal_committed', $committed, 10 );
			remove_action( 'datamachine_job_complete', $complete, 10 );
		}

		$this->assertTrue( $replayed['success'] );
		$this->assertFalse( $replayed['changed'] );
		$this->assertTrue( $repeated['complete'] );
		$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $this->db_jobs->get_job( $job_id )['terminal_accounting_state'] );
		$this->assertSame( 0, $this->db_jobs->count_incomplete_terminal_accounting() );
		$this->assertSame( 1, $committed_hooks );
		$this->assertSame( 1, $complete_hooks );
		$completed_job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::COMPLETED, ( new \DataMachine\Core\RunLifecycleStore( $this->db_jobs ) )->get_run( $job_id )['status'] );
		$this->assertSame( JobStatus::COMPLETED, \DataMachine\Core\RunMetrics::fromJob( $completed_job )['status'] );
		if ( null !== $metrics_completed_at_before ) {
			$this->assertSame( $metrics_completed_at_before, $completed_job['engine_data']['run_metrics']['completed_at'] );
		}
		if ( null !== $lifecycle_completed_at_before ) {
			$this->assertSame( $lifecycle_completed_at_before, $completed_job['engine_data']['run_lifecycle']['completed_at'] );
		}
	}

	/** @return array<string,array{string}> */
	public static function terminal_accounting_interruption_boundaries(): array {
		return array(
			'before committed hook' => array( 'before:terminal_committed_hook' ),
			'after committed hook'  => array( 'after:terminal_committed_hook' ),
			'before metrics'        => array( 'before:run_metrics' ),
			'after metrics'         => array( 'after:run_metrics' ),
			'before complete hook'  => array( 'before:job_complete_hook' ),
			'after complete hook'   => array( 'after:job_complete_hook' ),
			'before lifecycle'      => array( 'before:run_lifecycle' ),
			'after lifecycle'       => array( 'after:run_lifecycle' ),
		);
	}

	public function test_throwing_external_hook_is_claimed_at_most_once(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'At-most-once external hook' ) );
		$this->assertIsInt( $job_id );

		$calls    = 0;
		$listener = static function ( int $hook_job_id ) use ( $job_id, &$calls ): void {
			if ( $job_id === $hook_job_id ) {
				++$calls;
				throw new \RuntimeException( 'external hook failed after receipt claim' );
			}
		};
		add_action( 'datamachine_job_complete', $listener, 10, 1 );
		try {
			$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
			$this->fail( 'Expected external hook failure.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'external hook failed after receipt claim', $exception->getMessage() );
		} finally {
			remove_action( 'datamachine_job_complete', $listener, 10 );
		}

		$this->assertTrue( $this->db_jobs->reconcile_terminal_accounting( $job_id )['complete'] );
		$this->assertTrue( $this->db_jobs->reconcile_terminal_accounting( $job_id )['complete'] );
		$this->assertSame( 1, $calls );
	}

	public function test_reopening_failed_job_starts_a_fresh_accounting_receipt(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Fresh retry accounting' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::FAILED ) );
		$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $this->db_jobs->get_job( $job_id )['terminal_accounting_state'] );

		$this->assertTrue( $this->db_jobs->reopen_failed_job( $job_id ) );
		$reopened = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::PENDING, $reopened['status'] );
		$this->assertNull( $reopened['terminal_accounting_state'] );

		$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
		$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $this->db_jobs->get_job( $job_id )['terminal_accounting_state'] );
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
