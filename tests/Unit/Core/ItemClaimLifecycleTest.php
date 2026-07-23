<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Integration coverage verifies Data Machine-owned claim and tracking tables.
/**
 * Owner-safe source identity claim lifecycle coverage.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Abilities\Job\FailJobAbility;
use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\ActionScheduler\GroupRegistrar;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\Database\TrackedItems\TrackedItems;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\Steps\Fetch\Tools\FetchItemDispositionTool;
use DataMachine\Engine\Actions\Handlers\FailJobHandler;
use DataMachine\Engine\Actions\Handlers\StepLifecycleHandler;
use Closure;
use WP_UnitTestCase;

class ItemClaimLifecycleTest extends WP_UnitTestCase {

	private const SCOPE     = 'shared:test-source';
	private const SOURCE    = 'test_source';
	private const NAMESPACE = 'claim-lifecycle-test';

	private ProcessedItems $processed;
	private TrackedItems $tracked;
	private Jobs $jobs;
	private int $admin_id;
	private ?Closure $schedule_failure_filter   = null;
	private ?Closure $chunk_size_filter         = null;
	private ?Closure $tracked_handler_filter    = null;
	private ?Closure $completion_handler_filter = null;

	public function set_up(): void {
		parent::set_up();
		$this->processed = new ProcessedItems();
		$this->tracked   = new TrackedItems();
		$this->jobs      = new Jobs();
		$this->processed->create_table();
		$this->tracked->create_table();
		$this->tracked_handler_filter = static fn( array $handlers ): array => TrackedItems::registerClaimCompletionHandler( $handlers );
		add_filter( 'datamachine_item_claim_completion_handlers', $this->tracked_handler_filter );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		$this->deleteTestRows();
	}

	public function tear_down(): void {
		if ( null !== $this->schedule_failure_filter ) {
			remove_filter( 'pre_as_schedule_single_action', $this->schedule_failure_filter );
		}
		if ( null !== $this->chunk_size_filter ) {
			remove_filter( 'datamachine_batch_chunk_size', $this->chunk_size_filter );
		}
		if ( null !== $this->completion_handler_filter ) {
			remove_filter( 'datamachine_item_claim_completion_handlers', $this->completion_handler_filter );
		}
		if ( null !== $this->tracked_handler_filter ) {
			remove_filter( 'datamachine_item_claim_completion_handlers', $this->tracked_handler_filter );
		}
		$this->deleteTestRows();
		parent::tear_down();
	}

	public function test_two_flow_steps_race_one_shared_identity(): void {
		global $wpdb;
		$first  = $this->context( 'flow-step-a', 101 )->claimItemOwnership( self::SCOPE, 'shared-id' );
		$second = $this->context( 'flow-step-b', 102 )->claimItemOwnership( self::SCOPE, 'shared-id' );

		$this->assertIsArray( $first );
		$this->assertFalse( $second );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_expired_owner_cannot_complete_or_release_replacement(): void {
		$old = $this->claim( 'expiry-id', 201, 'old-revision' );
		$this->expireClaim( 'expiry-id' );
		$new = $this->claim( 'expiry-id', 202, 'new-revision' );

		// The old worker resumes only after expiry and replacement ownership.
		StepLifecycleHandler::handleCompleted( 201, array( ProcessedItems::CLAIM_METADATA_KEY => $old ) );
		StepLifecycleHandler::handleFailed( 201, array( ProcessedItems::CLAIM_METADATA_KEY => $old ) );

		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'expiry-id' ) );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'expiry-id' ) );

		StepLifecycleHandler::handleCompleted( 202, array( ProcessedItems::CLAIM_METADATA_KEY => $new ) );
		$this->assertSame( 'new-revision', $this->tracked->get( self::NAMESPACE, 'expiry-id' )['source_revision'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'expiry-id' ) );
	}

	public function test_expired_current_owner_can_complete_without_replacement(): void {
		$claim = $this->claim( 'long-running-id', 2021, 'long-running-revision' );
		$this->expireClaim( 'long-running-id' );

		StepLifecycleHandler::handleCompleted( 2021, array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );

		$this->assertSame( 'long-running-revision', $this->tracked->get( self::NAMESPACE, 'long-running-id' )['source_revision'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'long-running-id' ) );
	}

	public function test_completion_callback_failure_rolls_back_side_effect(): void {
		$claim  = $this->claim( 'rollback-id', 203, 'rolled-back-revision' );
		$result = $this->processed->complete_owned_claim(
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['ownership_token'],
			203,
			function (): bool {
				$this->tracked->upsert(
					array(
						'namespace'       => self::NAMESPACE,
						'item_id'         => 'rollback-id',
						'source_revision' => 'rolled-back-revision',
					)
				);
				return false;
			}
		);

		$this->assertFalse( $result );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'rollback-id' ) );
		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'rollback-id' ) );
	}

	public function test_terminal_completion_callback_failure_fails_job_and_releases_for_retry(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claimWithCompletion(
			'terminal-rollback-id',
			$job_id,
			array(
				'handler'          => 'failing_test',
				'retain_processed' => false,
			)
		);
		datamachine_set_engine_data( $job_id, array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );
		$this->completion_handler_filter = function ( array $handlers ): array {
			$handlers['failing_test'] = function ( array $payload, int $job_id, array $claim ): bool {
				unset( $payload, $job_id, $claim );
				$this->tracked->upsert(
					array(
						'namespace'       => self::NAMESPACE,
						'item_id'         => 'terminal-rollback-id',
						'source_revision' => 'must-rollback',
					)
				);
				return false;
			};
			return $handlers;
		};
		add_filter( 'datamachine_item_claim_completion_handlers', $this->completion_handler_filter );

		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::COMPLETED ) );

		$job = $this->jobs->get_job( $job_id );
		$this->assertIsArray( $job );
		$this->assertFalse( JobStatus::isStatusSuccess( (string) $job['status'] ) );
		$this->assertStringContainsString( 'item_claim_completion_failed', (string) $job['status'] );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'terminal-rollback-id' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'terminal-rollback-id' ) );
		$this->assertIsArray( $this->context( 'retry-flow-step', $job_id + 1000 )->claimItemOwnership( self::SCOPE, 'terminal-rollback-id' ) );
	}

	public function test_later_claim_failure_rolls_back_every_claim_and_callback(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$first  = $this->claim( 'multi-rollback-first', $job_id, 'must-rollback' );
		$second = $this->claimWithCompletion(
			'multi-rollback-second',
			$job_id,
			array(
				'handler'          => 'failing_test',
				'retain_processed' => false,
			)
		);
		datamachine_set_engine_data( $job_id, array( ProcessedItems::CLAIMS_METADATA_KEY => array( $first, $second ) ) );
		$this->completion_handler_filter = static function ( array $handlers ): array {
			$handlers['failing_test'] = static fn(): bool => false;
			return $handlers;
		};
		add_filter( 'datamachine_item_claim_completion_handlers', $this->completion_handler_filter );

		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::COMPLETED ) );

		$job = $this->jobs->get_job( $job_id );
		$this->assertIsArray( $job );
		$this->assertStringContainsString( 'item_claim_completion_failed', (string) $job['status'] );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'multi-rollback-first' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'multi-rollback-first' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'multi-rollback-second' ) );
	}

	public function test_terminal_exception_rolls_back_callbacks_before_failure_recovery(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claim( 'crash-boundary-id', $job_id, 'must-rollback' );
		datamachine_set_engine_data( $job_id, array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );
		$crash = static function (): never {
			throw new \RuntimeException( 'simulated crash before terminal CAS' );
		};
		add_filter( 'datamachine_job_terminal_status', $crash, 20 );

		try {
			$this->assertFalse( $this->jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
		} finally {
			remove_filter( 'datamachine_job_terminal_status', $crash, 20 );
		}

		$this->assertSame( 'processing', $this->jobs->get_job( $job_id )['status'] );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'crash-boundary-id' ) );
		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'crash-boundary-id' ) );
		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::failed( 'terminal_preparation_exception' )->toString() ) );

		$job = $this->jobs->get_job( $job_id );
		$this->assertIsArray( $job );
		$this->assertStringContainsString( 'terminal_preparation_exception', (string) $job['status'] );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'crash-boundary-id' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'crash-boundary-id' ) );
		$this->assertIsArray( $this->context( 'crash-retry-step', $job_id + 2000 )->claimItemOwnership( self::SCOPE, 'crash-boundary-id' ) );
	}

	public function test_forced_terminal_cas_failure_rolls_back_claim_side_effects(): void {
		global $wpdb;
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claim( 'cas-loser-id', $job_id, 'must-rollback' );
		datamachine_set_engine_data( $job_id, array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );
		$force_cas_loss = static function ( string $status, int $filtered_job_id ) use ( $wpdb ): string {
			$wpdb->update(
				( new Jobs() )->get_table_name(),
				array( 'status' => 'processing-race-winner' ),
				array( 'job_id' => $filtered_job_id ),
				array( '%s' ),
				array( '%d' )
			);
			return $status;
		};
		add_filter( 'datamachine_job_terminal_status', $force_cas_loss, 20, 2 );

		try {
			$result = $this->jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
		} finally {
			remove_filter( 'datamachine_job_terminal_status', $force_cas_loss, 20 );
		}

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'processing', $result['status'] );
		$this->assertSame( 'processing', $this->jobs->get_job( $job_id )['status'] );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'cas-loser-id' ) );
		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cas-loser-id' ) );
		StepLifecycleHandler::handleFailed( $job_id );
	}

	public function test_terminal_cas_loser_reports_persisted_winner(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::CANCELLED ) );

		$result = $this->jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( JobStatus::CANCELLED, $result['status'] );
		$this->assertSame( JobStatus::CANCELLED, $result['current_status'] );
	}

	public function test_concurrent_terminal_callers_share_one_persisted_success(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claimWithCompletion(
			'concurrent-terminal-id',
			$job_id,
			array(
				'handler'          => 'counting_test',
				'retain_processed' => false,
			)
		);
		datamachine_set_engine_data( $job_id, array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );
		$callback_count                  = 0;
		$this->completion_handler_filter = static function ( array $handlers ) use ( &$callback_count ): array {
			$handlers['counting_test'] = static function () use ( &$callback_count ): bool {
				++$callback_count;
				return true;
			};
			return $handlers;
		};
		add_filter( 'datamachine_item_claim_completion_handlers', $this->completion_handler_filter );
		$contender_result = null;
		$interleave       = function ( string $status ) use ( $job_id, &$contender_result ): string {
			$contender_result = ( new Jobs() )->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
			return $status;
		};
		add_filter( 'datamachine_job_terminal_status', $interleave, 20 );
		try {
			$owner_result = $this->jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
		} finally {
			remove_filter( 'datamachine_job_terminal_status', $interleave, 20 );
		}

		$this->assertTrue( $owner_result['success'] );
		$this->assertIsArray( $contender_result );
		$this->assertFalse( $contender_result['success'] );
		$this->assertSame( JobStatus::COMPLETED, $owner_result['status'] );
		$this->assertSame( 1, $callback_count );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'concurrent-terminal-id' ) );
	}

	public function test_reject_source_defers_owned_claim_completion_to_terminal_lifecycle(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claim( 'rejected-by-agent', $job_id, 'rejected-revision' );
		datamachine_set_engine_data(
			$job_id,
			array_merge(
				$this->legacyEngineData( 'rejected-by-agent' ),
				array( ProcessedItems::CLAIM_METADATA_KEY => $claim )
			)
		);

		$result = ( new FetchItemDispositionTool() )->handle_tool_call(
			array(
				'job_id' => $job_id,
				'reason' => 'not an event',
			),
			array( 'disposition' => 'reject_source' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'rejected-by-agent' ) );
		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::agentSkipped( 'source-rejected' )->toString() ) );
		$this->assertSame( 'rejected-revision', $this->tracked->get( self::NAMESPACE, 'rejected-by-agent' )['source_revision'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'rejected-by-agent' ) );
		$this->assertStringNotContainsString( 'item_claim_completion_failed', $this->jobs->get_job( $job_id )['status'] );
	}

	public function test_defer_item_defers_owned_claim_release_to_terminal_lifecycle(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claim( 'deferred-by-agent', $job_id, 'deferred-revision' );
		datamachine_set_engine_data(
			$job_id,
			array_merge(
				$this->legacyEngineData( 'deferred-by-agent' ),
				array( ProcessedItems::CLAIM_METADATA_KEY => $claim )
			)
		);

		$result = ( new FetchItemDispositionTool() )->handle_tool_call(
			array(
				'job_id' => $job_id,
				'reason' => 'temporary tool failure',
			),
			array( 'disposition' => 'defer_item' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'deferred-by-agent' ) );
		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::failed( 'item-deferred' )->toString() ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'deferred-by-agent' ) );
		$this->assertIsArray( $this->context( 'deferred-retry-step', $job_id + 3000 )->claimItemOwnership( self::SCOPE, 'deferred-by-agent' ) );
	}

	public function test_interleaved_status_write_before_terminal_cas_rolls_back_and_recovers(): void {
		global $wpdb;
		$job_id     = $this->createProcessingJobWithTrackedClaim( 'process-death-id', 'recovered-revision' );
		$interleave = static function ( string $status, int $filtered_job_id ) use ( $wpdb ): string {
			$wpdb->update(
				( new Jobs() )->get_table_name(),
				array( 'status' => 'processing-interleaver' ),
				array( 'job_id' => $filtered_job_id ),
				array( '%s' ),
				array( '%d' )
			);
			return $status;
		};
		add_filter( 'datamachine_job_terminal_status', $interleave, 20, 2 );
		try {
			$result = $this->jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
		} finally {
			remove_filter( 'datamachine_job_terminal_status', $interleave, 20 );
		}

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'processing', $this->jobs->get_job( $job_id )['status'] );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'process-death-id' ) );
		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'process-death-id' ) );
		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
		$this->assertSame( 'recovered-revision', $this->tracked->get( self::NAMESPACE, 'process-death-id' )['source_revision'] );
	}

	public function test_process_death_after_commit_replays_persisted_processed_claim_count(): void {
		global $wpdb;
		$job_id    = $this->createProcessingJobWithTrackedClaim( 'commit-crash-id', 'committed-revision' );
		$interrupt = static function ( bool $should_interrupt, string $boundary, int $filtered_job_id ) use ( $job_id ): bool {
			return $should_interrupt || ( $job_id === $filtered_job_id && 'before:run_metrics' === $boundary );
		};
		add_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 20, 3 );
		try {
			$this->jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
			$this->fail( 'Expected the post-commit interruption.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Terminal accounting interrupted at before:run_metrics', $exception->getMessage() );
		} finally {
			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 20 );
		}

		$committed = $this->jobs->get_job( $job_id );
		$this->assertSame( JobStatus::COMPLETED, $committed['status'] );
		$this->assertSame( 1, (int) $committed['terminal_accounting_processed_count'] );
		$this->assertSame( 'committed-revision', $this->tracked->get( self::NAMESPACE, 'commit-crash-id' )['source_revision'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'commit-crash-id' ) );

		StepLifecycleHandler::handleTerminalRollback( $job_id );
		$wpdb->update(
			$this->jobs->get_table_name(),
			array( 'terminal_accounting_claimed_at' => '2000-01-01 00:00:00' ),
			array( 'job_id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertTrue( ( new Jobs() )->reconcile_terminal_accounting( $job_id )['complete'] );
		$this->assertTrue( ( new Jobs() )->reconcile_terminal_accounting( $job_id )['complete'] );
		$this->assertSame( 1, RunMetrics::fromJob( $this->jobs->get_job( $job_id ) )['counts']['processed'] );
	}

	public function test_cancellation_atomically_releases_mixed_descriptor_and_legacy_claims(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claim( 'cancel-mixed-owned', $job_id, 'must-not-persist' );
		$this->assertTrue( $this->processed->claim_item( self::SCOPE, self::SOURCE, 'cancel-mixed-legacy', $job_id ) );
		datamachine_set_engine_data(
			$job_id,
			array_merge(
				$this->legacyEngineData( 'cancel-mixed-legacy' ),
				array( ProcessedItems::CLAIM_METADATA_KEY => $claim )
			)
		);

		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::CANCELLED ) );

		$this->assertSame( JobStatus::CANCELLED, $this->jobs->get_job( $job_id )['status'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cancel-mixed-owned' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cancel-mixed-legacy' ) );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'cancel-mixed-owned' ) );
	}

	public function test_gated_inline_continuation_completes_every_claim(): void {
		$first  = $this->claim( 'inline-success-a', 211, 'revision-a' );
		$second = $this->claim( 'inline-success-b', 211, 'revision-b' );
		$job_id = $this->createJobWithClaim( array(), true );

		StepLifecycleHandler::handleInlineContinuation(
			$job_id,
			array( 'step_type' => 'fetch' ),
			array( $this->packet( $first ), $this->packet( $second ) )
		);
		$engine = datamachine_get_engine_data( $job_id );
		$this->assertCount( 2, $engine[ ProcessedItems::CLAIMS_METADATA_KEY ] );

		StepLifecycleHandler::handleCompleted( $job_id, $engine );
		$this->assertSame( 'revision-a', $this->tracked->get( self::NAMESPACE, 'inline-success-a' )['source_revision'] );
		$this->assertSame( 'revision-b', $this->tracked->get( self::NAMESPACE, 'inline-success-b' )['source_revision'] );
	}

	public function test_gated_inline_continuation_failure_releases_every_claim(): void {
		$first  = $this->claim( 'inline-failure-a', 212, 'revision-a' );
		$second = $this->claim( 'inline-failure-b', 212, 'revision-b' );
		$job_id = $this->createJobWithClaim( array(), true );

		StepLifecycleHandler::handleInlineContinuation(
			$job_id,
			array( 'step_type' => 'fetch' ),
			array( $this->packet( $first ), $this->packet( $second ) )
		);
		StepLifecycleHandler::handleFailed( $job_id );

		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'inline-failure-a' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'inline-failure-b' ) );
	}

	public function test_successful_terminal_completion_persists_tracked_revision(): void {
		$claim  = $this->claim( 'success-id', 301, 'success-revision' );
		$job_id = $this->createJobWithClaim( $claim, true );

		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
		$this->assertSame( 'success-revision', $this->tracked->get( self::NAMESPACE, 'success-id' )['source_revision'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'success-id' ) );
		$this->assertSame( 1, RunMetrics::fromJob( $this->jobs->get_job( $job_id ) )['counts']['processed'] );
	}

	public function test_ordinary_terminal_failure_releases_claim(): void {
		$claim  = $this->claim( 'retry-id', 401, 'retry-revision' );
		$job_id = $this->createJobWithClaim( $claim, true );

		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::failed( 'retry-exhausted' )->toString() ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'retry-id' ) );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'retry-id' ) );
	}

	public function test_retry_exhaustion_releases_claim(): void {
		$claim  = $this->claim( 'exhausted-id', 402, 'exhausted-revision' );
		$job_id = $this->createJobWithClaim( $claim, true );
		$retryable = static fn() => true;
		$policy    = static function ( array $resolved ): array {
			$resolved['max_attempts'] = 1;
			return $resolved;
		};
		add_filter( 'datamachine_job_error_retryable', $retryable );
		add_filter( 'datamachine_job_retry_policy', $policy );

		try {
			$this->assertTrue( FailJobHandler::handle( $job_id, 'retryable-test', array( 'flow_step_id' => 'step-1' ) ) );
		} finally {
			remove_filter( 'datamachine_job_error_retryable', $retryable );
			remove_filter( 'datamachine_job_retry_policy', $policy );
		}

		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'exhausted-id' ) );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'exhausted-id' ) );
	}

	public function test_manual_fail_releases_claim(): void {
		$claim  = $this->claim( 'manual-id', 501, 'manual-revision' );
		$job_id = $this->createJobWithClaim( $claim );

		$result = ( new FailJobAbility() )->execute( array( 'job_id' => $job_id, 'reason' => 'manual test' ) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'manual-id' ) );
	}

	public function test_stale_recovery_releases_claim(): void {
		$claim  = $this->claim( 'stale-id', 601, 'stale-revision' );
		$job_id = $this->createJobWithClaim( $claim, true );
		datamachine_merge_engine_data( $job_id, array( 'job_status' => JobStatus::failed( 'stale' )->toString() ) );

		$result = ( new RecoverStuckJobsAbility() )->execute(
			array(
				'dry_run' => false,
				'job_id'  => $job_id,
			)
		);

		$this->assertGreaterThanOrEqual( 1, $result['recovered'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'stale-id' ) );
	}

	public function test_initial_batch_scheduling_failure_releases_all_claims(): void {
		$claim                         = $this->claim( 'schedule-id', 701, 'schedule-revision' );
		$parent_id                     = $this->createJobWithClaim( array(), true );
		$this->schedule_failure_filter = static fn() => 0;
		add_filter( 'pre_as_schedule_single_action', $this->schedule_failure_filter );

		$result = BatchScheduler::start( $parent_id, 'test_batch_hook', array( $this->packet( $claim ) ), array(), 'pipeline' );

		$this->assertFalse( $result['scheduled'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'schedule-id' ) );
	}

	public function test_child_creation_failure_releases_item_claim(): void {
		$claim     = $this->claim( 'child-id', 801, 'child-revision' );
		$parent_id = $this->createJobWithClaim( array(), true );
		BatchScheduler::start( $parent_id, 'test_batch_hook', array( $this->packet( $claim ) ), array(), 'pipeline' );
		$this->unscheduleTestBatch( $parent_id );

		BatchScheduler::processChunk( $parent_id, static fn() => false );

		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'child-id' ) );
	}

	public function test_batch_cancellation_releases_unscheduled_claims(): void {
		$claim     = $this->claim( 'cancel-id', 901, 'cancel-revision' );
		$parent_id = $this->createJobWithClaim( array(), true );
		BatchScheduler::start( $parent_id, 'test_batch_hook', array( $this->packet( $claim ) ), array(), 'pipeline' );
		$this->unscheduleTestBatch( $parent_id );

		$this->assertTrue( BatchScheduler::cancel( $parent_id ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cancel-id' ) );
	}

	public function test_batch_cancellation_during_active_chunk_preserves_created_child_claim(): void {
		$first     = $this->claim( 'cancel-race-a', 9011, 'revision-a' );
		$second    = $this->claim( 'cancel-race-b', 9011, 'revision-b' );
		$third     = $this->claim( 'cancel-race-c', 9011, 'revision-c' );
		$fourth    = $this->claim( 'cancel-race-d', 9011, 'revision-d' );
		$parent_id = $this->createJobWithClaim( array(), true );
		$this->chunk_size_filter = static fn() => 2;
		add_filter( 'datamachine_batch_chunk_size', $this->chunk_size_filter );
		BatchScheduler::start(
			$parent_id,
			'test_batch_hook',
			array( $this->packet( $first ), $this->packet( $second ), $this->packet( $third ), $this->packet( $fourth ) ),
			array(),
			'pipeline'
		);
		$this->unscheduleTestBatch( $parent_id );
		$created = 0;

		$result = BatchScheduler::processChunk(
			$parent_id,
			static function () use ( $parent_id, &$created ): bool {
				++$created;
				BatchScheduler::cancel( $parent_id );
				return true;
			},
			0
		);

		$this->assertTrue( $result['cancelled'] );
		$this->assertSame( 1, $created );
		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cancel-race-a' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cancel-race-b' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cancel-race-c' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cancel-race-d' ) );
		StepLifecycleHandler::handleFailed( 9011, array( ProcessedItems::CLAIM_METADATA_KEY => $first ) );
	}

	public function test_later_chunk_scheduling_failure_releases_remaining_claims(): void {
		$first     = $this->claim( 'later-a', 902, 'revision-a' );
		$second    = $this->claim( 'later-b', 902, 'revision-b' );
		$parent_id = $this->createJobWithClaim( array(), true );
		$calls     = 0;
		$this->chunk_size_filter       = static fn() => 1;
		$this->schedule_failure_filter = static function ( $pre ) use ( &$calls ) {
			++$calls;
			return $calls > 1 ? 0 : $pre;
		};
		add_filter( 'datamachine_batch_chunk_size', $this->chunk_size_filter );
		add_filter( 'pre_as_schedule_single_action', $this->schedule_failure_filter );

		BatchScheduler::start( $parent_id, 'test_batch_hook', array( $this->packet( $first ), $this->packet( $second ) ), array(), 'pipeline' );
		$this->unscheduleTestBatch( $parent_id );
		$result = BatchScheduler::processChunk( $parent_id, static fn() => true );

		$this->assertTrue( $result['schedule_failed'] );
		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'later-a' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'later-b' ) );
		StepLifecycleHandler::handleFailed( 902, array( ProcessedItems::CLAIM_METADATA_KEY => $first ) );
	}

	public function test_content_addressed_hydration_failure_releases_sidecar_claim(): void {
		$claim     = $this->claim( 'hydrate-id', 903, 'hydrate-revision' );
		$parent_id = $this->createJobWithClaim( array(), true );
		$item      = array( 'data_packets' => array( $this->packet( $claim ) ) );
		BatchScheduler::start( $parent_id, 'test_batch_hook', array( $item ), array(), 'task' );
		$this->unscheduleTestBatch( $parent_id );

		$engine = datamachine_get_engine_data( $parent_id );
		$engine['batch_state']['items'][0]['data_packets'][0]['file_path'] = '/missing/data-packet.json';
		datamachine_set_engine_data( $parent_id, $engine );
		$called = false;
		BatchScheduler::processChunk(
			$parent_id,
			static function () use ( &$called ): bool {
				$called = true;
				return true;
			}
		);

		$this->assertFalse( $called );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'hydrate-id' ) );
	}

	/**
	 * @dataProvider legacy_terminal_status_provider
	 */
	public function test_predeployment_job_without_descriptor_releases_legacy_claim( string $status ): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$this->assertTrue( $this->processed->claim_item( self::SCOPE, self::SOURCE, 'legacy-' . $status, $job_id ) );

		$this->assertTrue( $this->jobs->complete_job( $job_id, $status ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'legacy-' . $status ) );
	}

	public function test_descriptorless_success_completes_job_owned_claim(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$this->assertTrue( $this->processed->claim_item( self::SCOPE, self::SOURCE, 'legacy-success', $job_id ) );

		StepLifecycleHandler::handleCompleted( $job_id, $this->legacyEngineData( 'legacy-success' ) );

		$this->assertTrue( $this->processed->has_item_been_processed( self::SCOPE, self::SOURCE, 'legacy-success' ) );
	}

	public function test_stale_descriptorless_success_cannot_overwrite_replacement_owner(): void {
		$legacy_job_id = $this->createJobWithClaim( array(), true );
		$this->assertTrue( $this->processed->claim_item( self::SCOPE, self::SOURCE, 'legacy-replaced', $legacy_job_id ) );
		$this->expireClaim( 'legacy-replaced' );
		$replacement = $this->claim( 'legacy-replaced', 905, 'replacement-revision' );

		StepLifecycleHandler::handleCompleted( $legacy_job_id, $this->legacyEngineData( 'legacy-replaced' ) );

		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'legacy-replaced' ) );
		StepLifecycleHandler::handleCompleted( 905, array( ProcessedItems::CLAIM_METADATA_KEY => $replacement ) );
		$this->assertSame( 'replacement-revision', $this->tracked->get( self::NAMESPACE, 'legacy-replaced' )['source_revision'] );
	}

	public function legacy_terminal_status_provider(): array {
		return array(
			'failure'      => array( JobStatus::failed( 'legacy' )->toString() ),
			'cancellation' => array( JobStatus::CANCELLED ),
		);
	}

	public function test_predeployment_stale_recovery_releases_legacy_claim(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$this->assertTrue( $this->processed->claim_item( self::SCOPE, self::SOURCE, 'legacy-recovery', $job_id ) );
		datamachine_merge_engine_data( $job_id, array( 'job_status' => JobStatus::failed( 'stale' )->toString() ) );

		( new RecoverStuckJobsAbility() )->execute(
			array(
				'dry_run' => false,
				'job_id'  => $job_id,
			)
		);
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'legacy-recovery' ) );
	}

	public function test_mixed_descriptor_and_legacy_claims_are_both_released(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claim( 'mixed-owned', $job_id, 'mixed-revision' );
		$this->assertTrue( $this->processed->claim_item( self::SCOPE, self::SOURCE, 'mixed-legacy', $job_id ) );

		StepLifecycleHandler::handleFailed( $job_id, array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'mixed-owned' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'mixed-legacy' ) );
	}

	public function test_mixed_descriptor_and_legacy_claims_are_both_completed(): void {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claim( 'mixed-success-owned', $job_id, 'mixed-success-revision' );
		$this->assertTrue( $this->processed->claim_item( self::SCOPE, self::SOURCE, 'mixed-success-legacy', $job_id ) );
		datamachine_set_engine_data(
			$job_id,
			array_merge(
				$this->legacyEngineData( 'mixed-success-legacy' ),
				array( ProcessedItems::CLAIM_METADATA_KEY => $claim )
			)
		);

		$this->assertTrue( $this->jobs->complete_job( $job_id, JobStatus::COMPLETED ) );

		$this->assertSame( 'mixed-success-revision', $this->tracked->get( self::NAMESPACE, 'mixed-success-owned' )['source_revision'] );
		$this->assertTrue( $this->processed->has_item_been_processed( self::SCOPE, self::SOURCE, 'mixed-success-legacy' ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'mixed-success-owned' ) );
		$this->assertSame( 2, RunMetrics::fromJob( $this->jobs->get_job( $job_id ) )['counts']['processed'] );
	}

	public function test_malformed_content_addressed_ref_releases_sidecar_claim(): void {
		$claim     = $this->claim( 'malformed-ref', 904, 'malformed-revision' );
		$parent_id = $this->createJobWithClaim( array(), true );
		$item      = array( 'data_packets' => array( $this->packet( $claim ) ) );
		BatchScheduler::start( $parent_id, 'test_batch_hook', array( $item ), array(), 'task' );
		$this->unscheduleTestBatch( $parent_id );

		$engine = datamachine_get_engine_data( $parent_id );
		$engine['batch_state']['items'][0]['data_packets'][0]['schema_version'] = 999;
		datamachine_set_engine_data( $parent_id, $engine );
		BatchScheduler::processChunk( $parent_id, static fn() => true );

		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'malformed-ref' ) );
	}

	private function context( string $flow_step_id, int $job_id ): ExecutionContext {
		return ExecutionContext::fromFlow( 1, 1, $flow_step_id, (string) $job_id, self::SOURCE );
	}

	private function claim( string $item_id, int $job_id, string $revision ): array {
		return $this->claimWithCompletion(
			$item_id,
			$job_id,
			array(
				'handler'          => 'tracked_item',
				'retain_processed' => false,
				'payload'          => array(
					'item' => array(
						'namespace'       => self::NAMESPACE,
						'item_id'         => $item_id,
						'item_type'       => 'source',
						'state'           => TrackedItems::STATE_GENERATED,
						'source_revision' => $revision,
					),
				),
			)
		);
	}

	private function claimWithCompletion( string $item_id, int $job_id, array $completion ): array {
		$claim = $this->context( 'actual-flow-step', $job_id )->claimItemOwnership(
			self::SCOPE,
			$item_id,
			ProcessedItems::DEFAULT_CLAIM_TTL_SECONDS,
			$completion
		);
		$this->assertIsArray( $claim );
		return $claim;
	}

	private function packet( array $claim ): array {
		return array(
			'type'     => 'fetch',
			'data'     => array( 'title' => 'Claimed item', 'body' => 'body' ),
			'metadata' => array( ProcessedItems::CLAIM_METADATA_KEY => $claim ),
		);
	}

	private function legacyEngineData( string $item_id ): array {
		return array(
			'item_identifier' => $item_id,
			'source_type'     => self::SOURCE,
			'flow_config'     => array(
				self::SCOPE => array( 'step_type' => 'fetch' ),
			),
		);
	}

	private function unscheduleTestBatch( int $parent_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions(
				'test_batch_hook',
				array(
					'parent_job_id' => $parent_id,
					'offset'        => 0,
				),
				GroupRegistrar::GROUP
			);
		}
	}

	private function createJobWithClaim( array $claim, bool $processing = false ): int {
		$job_id = $this->jobs->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'source'      => 'system',
				'label'       => 'Claim lifecycle test',
				'user_id'     => $this->admin_id,
			)
		);
		$this->assertIsInt( $job_id );
		if ( $processing ) {
			$this->jobs->start_job( $job_id );
		}
		datamachine_set_engine_data( $job_id, $claim ? array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) : array() );
		return $job_id;
	}

	private function createProcessingJobWithTrackedClaim( string $item_id, string $revision ): int {
		$job_id = $this->createJobWithClaim( array(), true );
		$claim  = $this->claim( $item_id, $job_id, $revision );
		datamachine_set_engine_data( $job_id, array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );
		return $job_id;
	}

	private function expireClaim( string $item_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET claim_expires_at = %s WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s',
				$this->processed->get_table_name(),
				'2000-01-01 00:00:00',
				self::SCOPE,
				self::SOURCE,
				$item_id
			)
		);
	}

	private function deleteTestRows(): void {
		global $wpdb;
		$wpdb->delete( $this->processed->get_table_name(), array( 'flow_step_id' => self::SCOPE ), array( '%s' ) );
		$wpdb->delete( $this->tracked->get_table_name(), array( 'namespace' => self::NAMESPACE ), array( '%s' ) );
	}
}
