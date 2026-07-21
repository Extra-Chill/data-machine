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
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\Database\TrackedItems\TrackedItems;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\JobStatus;
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
	private ?Closure $schedule_failure_filter = null;
	private ?Closure $chunk_size_filter       = null;

	public function set_up(): void {
		parent::set_up();
		$this->processed = new ProcessedItems();
		$this->tracked   = new TrackedItems();
		$this->jobs      = new Jobs();
		$this->processed->create_table();
		$this->tracked->create_table();
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

		$result = ( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => false ) );

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

		( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => false ) );
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
		$claim = $this->context( 'actual-flow-step', $job_id )->claimItemOwnership(
			self::SCOPE,
			$item_id,
			ProcessedItems::DEFAULT_CLAIM_TTL_SECONDS,
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

	private function unscheduleTestBatch( int $parent_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions(
				'test_batch_hook',
				array(
					'parent_job_id' => $parent_id,
					'offset'        => 0,
				),
				'data-machine'
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
