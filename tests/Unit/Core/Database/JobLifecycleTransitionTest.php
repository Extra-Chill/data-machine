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
use DataMachine\Engine\AI\AIConcurrencyBackpressure;
use DataMachine\Engine\Actions\Handlers\StepLifecycleHandler;
use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
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

	public function test_concurrent_pathless_child_recovery_has_one_owner(): void {
		$parent_id = $this->db_jobs->create_job( array( 'label' => 'Recovery parent' ) );
		$child_id  = $this->db_jobs->create_job( array( 'label' => 'Recovery child', 'parent_job_id' => $parent_id ) );
		$this->assertIsInt( $parent_id );
		$this->assertIsInt( $child_id );
		$this->assertTrue( $this->db_jobs->start_job( $child_id ) );

		$ability = new RecoverStuckJobsAbility();
		$method  = new \ReflectionMethod( $ability, 'claimPathlessChildRecovery' );
		$first   = $method->invoke( $ability, $child_id, 'test' );
		$second  = $method->invoke( $ability, $child_id, 'test' );

		$this->assertTrue( $first['owned'] );
		$this->assertFalse( $second['owned'] );
		$this->assertNotSame( $first['token'], $second['token'] );
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
		}

		try {
			$job = $this->db_jobs->get_job( $job_id );
			$this->assertSame( JobStatus::COMPLETED, $job['status'] );
			if ( Jobs::TERMINAL_ACCOUNTING_COMPLETE !== (int) $job['terminal_accounting_state'] ) {
				$this->expireTerminalAccountingLease( $job_id );
			}
			StepLifecycleHandler::handleTerminalRollback( $job_id );

			$replayed = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$repeated = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$this->assertTrue( $replayed['complete'] );
			$this->assertTrue( $repeated['complete'] );
			$this->assertSame( 1, $committed_hooks );
			$this->assertSame( 1, $complete_hooks );

			$completed_job = $this->db_jobs->get_job( $job_id );
			$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $completed_job['terminal_accounting_state'] );
			$this->assertSame( $completed_job['completed_at'], $completed_job['engine_data']['run_metrics']['completed_at'] );
			$this->assertSame( $completed_job['completed_at'], $completed_job['engine_data']['run_lifecycle']['completed_at'] );
		} finally {
			remove_action( 'datamachine_job_terminal_committed', $committed, 10 );
			remove_action( 'datamachine_job_complete', $complete, 10 );
		}
	}

	/** @return array<string,array{string}> */
	public static function terminal_accounting_interruption_boundaries(): array {
		return array(
			'before metrics'             => array( 'before:run_metrics' ),
			'after metrics operation'    => array( 'after_operation:run_metrics' ),
			'after metrics commit'       => array( 'after_commit:run_metrics' ),
			'before core callbacks'      => array( 'before:core_callbacks' ),
			'after core callbacks'       => array( 'after_operation:core_callbacks' ),
			'after core commit'          => array( 'after_commit:core_callbacks' ),
			'before lifecycle'           => array( 'before:run_lifecycle' ),
			'after lifecycle operation'  => array( 'after_operation:run_lifecycle' ),
			'after lifecycle commit'     => array( 'after_commit:run_lifecycle' ),
			'before notifications'       => array( 'before:extension_notifications' ),
			'after notifications'        => array( 'after_notification:extension_notifications' ),
		);
	}

	public function test_throwing_core_callback_does_not_skip_later_callback_and_remains_replayable(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Replayable core callbacks' ) );
		$this->assertIsInt( $job_id );

		$throw_calls = 0;
		$later_calls = 0;
		$registry    = static function ( array $callbacks ) use ( &$throw_calls, &$later_calls ): array {
			$callbacks['throwing_test'] = static function () use ( &$throw_calls ): void {
				++$throw_calls;
				if ( $throw_calls <= 2 ) {
					throw new \RuntimeException( 'core callback interrupted' );
				}
			};
			$callbacks['later_test'] = static function () use ( &$later_calls ): void {
				++$later_calls;
			};
			return $callbacks;
		};
		add_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		try {
			$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
			$incomplete = $this->db_jobs->get_job( $job_id );
			$this->assertSame( 1, (int) $incomplete['terminal_accounting_state'] );
			$this->assertSame( 1, $later_calls, 'later core callback runs despite an earlier exception' );

			$failed = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$this->assertFalse( $failed['success'] );
			$this->assertSame( 'core_callbacks', $failed['stage'] );
			$this->assertSame( 'core_callback_exception', $failed['errors'][0]['code'] );
			$this->assertSame( 'throwing_test', $failed['errors'][0]['callback'] );
			$this->assertSame( 2, $later_calls );

			$replayed = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$this->assertTrue( $replayed['complete'] );
			$this->assertSame( 3, $throw_calls );
			$this->assertSame( 3, $later_calls );
		} finally {
			remove_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		}
	}

	public function test_concurrent_reconciler_cannot_overtake_owned_stage(): void {
		$job_id          = $this->db_jobs->create_job( array( 'label' => 'Ordered concurrent accounting' ) );
		$contender       = null;
		$core_calls      = 0;
		$core_at_contend = null;
		$registry        = static function ( array $callbacks ) use ( &$core_calls ): array {
			$callbacks['ordering_test'] = static function () use ( &$core_calls ): void {
				++$core_calls;
			};
			return $callbacks;
		};
		$interleave      = static function ( bool $interrupt, string $boundary, int $filtered_job_id ) use ( $job_id, &$contender, &$core_at_contend, &$core_calls ): bool {
			if ( $job_id === $filtered_job_id && 'before:run_metrics' === $boundary && null === $contender ) {
				$contender       = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
				$core_at_contend = $core_calls;
			}
			return $interrupt;
		};

		add_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		add_filter( 'datamachine_job_terminal_accounting_interrupt', $interleave, 10, 3 );
		try {
			$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
		} finally {
			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interleave, 10 );
			remove_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		}

		$this->assertIsArray( $contender );
		$this->assertTrue( $contender['in_progress'] );
		$this->assertSame( 'run_metrics', $contender['stage'] );
		$this->assertSame( 0, $core_at_contend );
		$this->assertSame( 1, $core_calls );
		$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $this->db_jobs->get_job( $job_id )['terminal_accounting_state'] );
	}

	public function test_extension_listener_exception_is_best_effort_after_core_completion(): void {
		$job_id      = $this->db_jobs->create_job( array( 'label' => 'Best effort extension notification' ) );
		$core_calls  = 0;
		$hook_calls  = 0;
		$registry    = static function ( array $callbacks ) use ( &$core_calls ): array {
			$callbacks['notification_order_test'] = static function () use ( &$core_calls ): void {
				++$core_calls;
			};
			return $callbacks;
		};
		$listener    = static function () use ( &$hook_calls ): void {
			++$hook_calls;
			throw new \RuntimeException( 'extension notification failed' );
		};
		$interrupted = false;
		$interrupt   = static function ( bool $should_interrupt, string $boundary, int $filtered_job_id ) use ( $job_id, &$interrupted ): bool {
			if ( ! $interrupted && $job_id === $filtered_job_id && 'before:extension_notifications' === $boundary ) {
				$interrupted = true;
				return true;
			}
			return $should_interrupt;
		};

		add_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		add_action( 'datamachine_job_complete', $listener, 1 );
		add_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10, 3 );
		try {
			try {
				$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
				$this->fail( 'Expected interruption before extension notification.' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'Terminal accounting interrupted at before:extension_notifications', $exception->getMessage() );
			}
			$this->assertSame( 1, $core_calls );
			$this->assertSame( 0, $hook_calls );

			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10 );
			$this->expireTerminalAccountingLease( $job_id );
			$result = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$this->assertTrue( $result['complete'] );
			$this->assertSame( 'extension_notification_failed', $result['errors'][0]['code'] );
			$this->assertSame( 1, $hook_calls );
			$this->assertTrue( ( new Jobs() )->reconcile_terminal_accounting( $job_id )['complete'] );
			$this->assertSame( 1, $hook_calls );
		} finally {
			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10 );
			remove_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
			remove_action( 'datamachine_job_complete', $listener, 1 );
		}
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

	public function test_ai_resume_generation_ownership_advances_with_cas(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'AI resume generation ownership' ) );
		$this->assertIsInt( $job_id );

		$generation_one = AIConcurrencyBackpressure::claimNextGeneration( $job_id, 'ai-1', 0, time() );
		$duplicate_one  = AIConcurrencyBackpressure::claimNextGeneration( $job_id, 'ai-1', 0, time() );
		$this->assertTrue( $generation_one['success'] );
		$this->assertTrue( $generation_one['owned'] );
		$this->assertSame( 1, $generation_one['generation'] );
		$this->assertTrue( $duplicate_one['success'] );
		$this->assertFalse( $duplicate_one['owned'] );
		$this->assertSame( 1, $duplicate_one['generation'] );

		$this->assertTrue( AIConcurrencyBackpressure::recordScheduledAction( $job_id, 'ai-1', 1, $generation_one['token'], 101 ) );
		$this->assertTrue( AIConcurrencyBackpressure::beginGeneration( $job_id, 'ai-1', 1, time() ) );
		$this->assertFalse( AIConcurrencyBackpressure::beginGeneration( $job_id, 'ai-1', 1, time() ) );

		$generation_two = AIConcurrencyBackpressure::claimNextGeneration( $job_id, 'ai-1', 1, time() );
		$duplicate_two  = AIConcurrencyBackpressure::claimNextGeneration( $job_id, 'ai-1', 1, time() );
		$this->assertTrue( $generation_two['success'] );
		$this->assertTrue( $generation_two['owned'] );
		$this->assertSame( 2, $generation_two['generation'] );
		$this->assertTrue( $duplicate_two['success'] );
		$this->assertFalse( $duplicate_two['owned'] );
		$this->assertFalse( AIConcurrencyBackpressure::beginGeneration( $job_id, 'ai-1', 1, time() ) );
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

	private function expireTerminalAccountingLease( int $job_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->db_jobs->get_table_name(),
			array( 'terminal_accounting_claimed_at' => '2000-01-01 00:00:00' ),
			array( 'job_id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
