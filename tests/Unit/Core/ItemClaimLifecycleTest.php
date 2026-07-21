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
		$this->deleteTestRows();
		parent::tear_down();
	}

	public function test_two_flow_steps_race_one_shared_identity(): void {
		$first = $this->context( 'flow-step-a', 101 )->claimItemOwnership( self::SCOPE, 'shared-id' );
		$second = $this->context( 'flow-step-b', 102 )->claimItemOwnership( self::SCOPE, 'shared-id' );

		$this->assertIsArray( $first );
		$this->assertFalse( $second );
	}

	public function test_expired_owner_cannot_complete_or_release_replacement(): void {
		$old = $this->claim( 'expiry-id', 201, 'old-revision' );
		$this->expireClaim( 'expiry-id' );
		StepLifecycleHandler::handleCompleted( 201, array( ProcessedItems::CLAIM_METADATA_KEY => $old ) );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'expiry-id' ) );

		$new = $this->claim( 'expiry-id', 202, 'new-revision' );

		StepLifecycleHandler::handleCompleted( 201, array( ProcessedItems::CLAIM_METADATA_KEY => $old ) );
		StepLifecycleHandler::handleFailed( 201, array( ProcessedItems::CLAIM_METADATA_KEY => $old ) );

		$this->assertTrue( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'expiry-id' ) );
		$this->assertNull( $this->tracked->get( self::NAMESPACE, 'expiry-id' ) );

		StepLifecycleHandler::handleCompleted( 202, array( ProcessedItems::CLAIM_METADATA_KEY => $new ) );
		$this->assertSame( 'new-revision', $this->tracked->get( self::NAMESPACE, 'expiry-id' )['source_revision'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'expiry-id' ) );
	}

	public function test_successful_terminal_completion_persists_tracked_revision(): void {
		$claim = $this->claim( 'success-id', 301, 'success-revision' );
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
		$claim = $this->claim( 'schedule-id', 701, 'schedule-revision' );
		$parent_id = $this->createJobWithClaim( array(), true );
		$this->schedule_failure_filter = static fn() => 0;
		add_filter( 'pre_as_schedule_single_action', $this->schedule_failure_filter );

		$result = BatchScheduler::start( $parent_id, 'test_batch_hook', array( $this->packet( $claim ) ), array(), 'pipeline' );

		$this->assertFalse( $result['scheduled'] );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'schedule-id' ) );
	}

	public function test_child_creation_failure_releases_item_claim(): void {
		$claim = $this->claim( 'child-id', 801, 'child-revision' );
		$parent_id = $this->createJobWithClaim( array(), true );
		BatchScheduler::start( $parent_id, 'test_batch_hook', array( $this->packet( $claim ) ), array(), 'pipeline' );

		BatchScheduler::processChunk( $parent_id, static fn() => false );

		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'child-id' ) );
	}

	public function test_batch_cancellation_releases_unscheduled_claims(): void {
		$claim = $this->claim( 'cancel-id', 901, 'cancel-revision' );
		$parent_id = $this->createJobWithClaim( array(), true );
		BatchScheduler::start( $parent_id, 'test_batch_hook', array( $this->packet( $claim ) ), array(), 'pipeline' );

		$this->assertTrue( BatchScheduler::cancel( $parent_id ) );
		$this->assertFalse( $this->processed->has_active_claim( self::SCOPE, self::SOURCE, 'cancel-id' ) );
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
				'keep_processed' => false,
				'tracked_item'   => array(
					'namespace'       => self::NAMESPACE,
					'item_id'         => $item_id,
					'item_type'       => 'source',
					'state'           => TrackedItems::STATE_GENERATED,
					'source_revision' => $revision,
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
