<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration coverage owns and cleans its claim rows.
/**
 * SyncRunner bounded-stop claim lifecycle coverage (issue #3964).
 *
 * @package DataMachine\Tests\Unit\Engine\Debug
 */

namespace DataMachine\Tests\Unit\Engine\Debug;

use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\JobStatus;
use DataMachine\Core\Steps\FlowStepConfigFactory;
use DataMachine\Core\Steps\Step;
use DataMachine\Engine\Debug\SyncRunner;
use WP_UnitTestCase;

class SyncRunnerClaimReleaseTest extends WP_UnitTestCase {

	public const SCOPE  = 'sync-runner-claim-scope';
	public const SOURCE = 'sync-runner-claim-source';

	private Jobs $jobs;
	private ProcessedItems $processed;
	private $step_types_filter;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();

		$this->jobs      = new Jobs();
		$this->processed = new ProcessedItems();
		$this->processed->create_table();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		SyncRunnerClaimSourceStep::$item_count = 0;

		$this->step_types_filter = static function ( array $types ): array {
			$types['sync_claim_source'] = array(
				'class'        => SyncRunnerClaimSourceStep::class,
				'uses_handler' => false,
			);
			$types['sync_claim_sink']   = array(
				'class'        => SyncRunnerClaimSinkStep::class,
				'uses_handler' => false,
			);
			return $types;
		};
		add_filter( 'datamachine_step_types', $this->step_types_filter, PHP_INT_MAX );
		StepTypeAbilities::clearCache();

		$this->deleteClaimRows();
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_step_types', $this->step_types_filter, PHP_INT_MAX );
		StepTypeAbilities::clearCache();
		SyncRunnerClaimSourceStep::$item_count = 0;
		$this->deleteClaimRows();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_max_items_stop_releases_claims_and_terminates_job(): void {
		SyncRunnerClaimSourceStep::$item_count = 6;
		$flow_id                               = $this->createFlow();

		$packet = ( new SyncRunner() )->runFlow( $flow_id, array( 'max_items' => 2 ) );

		$this->assertSame( 'max_items', $packet['stopped_reason'] );
		$this->assertSame( 1, $packet['counts']['steps_executed'] );
		$this->assertSame( 2, $packet['counts']['packets_seen'] );

		$job_id = (int) $packet['job_id'];
		$this->assertGreaterThan( 0, $job_id );
		$this->assertBoundedStopCleanedUp( $job_id, 'sync_runner_max_items' );
	}

	public function test_max_steps_stop_releases_claims_and_terminates_job(): void {
		SyncRunnerClaimSourceStep::$item_count = 3;
		$flow_id                               = $this->createFlow();

		$packet = ( new SyncRunner() )->runFlow(
			$flow_id,
			array(
				'max_steps' => 1,
				'max_items' => 50,
			)
		);

		$this->assertSame( 'max_steps', $packet['stopped_reason'] );
		$this->assertSame( 1, $packet['counts']['steps_executed'] );

		$job_id = (int) $packet['job_id'];
		$this->assertGreaterThan( 0, $job_id );
		$this->assertBoundedStopCleanedUp( $job_id, 'sync_runner_max_steps' );
	}

	public function test_full_run_completes_and_keeps_committed_items(): void {
		SyncRunnerClaimSourceStep::$item_count = 3;
		$flow_id                               = $this->createFlow();

		$packet = ( new SyncRunner() )->runFlow( $flow_id, array( 'max_items' => 50 ) );

		$this->assertTrue( $packet['success'] );
		$this->assertSame( 'completed', $packet['stopped_reason'] );
		$this->assertSame( 2, $packet['counts']['steps_executed'] );
		$this->assertSame( array(), $packet['errors'] );

		$job_id = (int) $packet['job_id'];
		$job    = $this->jobs->get_job( $job_id );
		$this->assertSame( JobStatus::COMPLETED, $job['status'] ?? '' );

		$this->assertSame( 0, $this->countRows( ProcessedItems::STATUS_CLAIMED ) );
		$this->assertSame( 3, $this->countRows( ProcessedItems::STATUS_PROCESSED ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $this->processed->has_item_been_processed( self::SCOPE, self::SOURCE, 'item-' . $i ) );
		}
	}

	private function assertBoundedStopCleanedUp( int $job_id, string $reason ): void {
		$job = $this->jobs->get_job( $job_id );
		$this->assertSame( JobStatus::CANCELLED, $job['status'] ?? '' );
		$this->assertTrue( JobStatus::isStatusFinal( (string) $job['status'] ) );
		$this->assertFalse( JobStatus::isStatusFailure( (string) $job['status'] ) );
		$this->assertSame( $reason, $job['engine_data']['job_status_reason'] ?? '' );

		$this->assertSame( 0, $this->countRows( ProcessedItems::STATUS_CLAIMED ), 'A bounded stop must not leave claimed rows behind.' );
		$this->assertSame( 0, $this->countRows( ProcessedItems::STATUS_PROCESSED ), 'A bounded stop must not commit items.' );

		// The next real run can claim the same items again.
		$this->assertIsString( $this->processed->claim_item_owned( self::SCOPE, self::SOURCE, 'item-0', $job_id + 1000 ) );
	}

	private function countRows( string $status ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE flow_step_id = %s AND status = %s',
				$this->processed->get_table_name(),
				self::SCOPE,
				$status
			)
		);
	}

	private function deleteClaimRows(): void {
		global $wpdb;
		$wpdb->delete( $this->processed->get_table_name(), array( 'flow_step_id' => self::SCOPE ), array( '%s' ) );
	}

	private function createFlow(): int {
		$pipeline_id = ( new Pipelines() )->create_pipeline(
			array(
				'pipeline_name'   => 'Sync Runner Claim Pipeline',
				'pipeline_config' => array(),
				'user_id'         => get_current_user_id(),
			)
		);
		$this->assertIsInt( $pipeline_id );

		$flows   = new Flows();
		$flow_id = $flows->create_flow(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => 'Sync Runner Claim Flow',
				'flow_config'       => array(),
				'scheduling_config' => array( 'enabled' => true ),
				'user_id'           => get_current_user_id(),
			)
		);
		$this->assertIsInt( $flow_id );

		$flow_config = array(
			'sync_source' => FlowStepConfigFactory::build(
				array(
					'flow_step_id'     => 'sync_source',
					'pipeline_step_id' => 'pipeline_sync_source',
					'step_type'        => 'sync_claim_source',
					'execution_order'  => 0,
					'pipeline_id'      => $pipeline_id,
					'flow_id'          => $flow_id,
					'queue_mode'       => 'static',
				)
			),
			'sync_sink'   => FlowStepConfigFactory::build(
				array(
					'flow_step_id'     => 'sync_sink',
					'pipeline_step_id' => 'pipeline_sync_sink',
					'step_type'        => 'sync_claim_sink',
					'execution_order'  => 1,
					'pipeline_id'      => $pipeline_id,
					'flow_id'          => $flow_id,
					'queue_mode'       => 'static',
				)
			),
		);
		$this->assertTrue( $flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) ) );

		return $flow_id;
	}
}

/**
 * Source step that claims every item it fetches, like a real fetch handler.
 */
class SyncRunnerClaimSourceStep extends Step {

	public static int $item_count = 0;

	public function __construct() {
		parent::__construct( 'sync_claim_source' );
	}

	protected function validateStepConfiguration(): bool {
		return true;
	}

	protected function executeStep(): array {
		$processed = new ProcessedItems();
		$packets   = array();
		for ( $i = 0; $i < self::$item_count; $i++ ) {
			$identifier = 'item-' . $i;
			$token      = $processed->claim_item_owned( SyncRunnerClaimReleaseTest::SCOPE, SyncRunnerClaimReleaseTest::SOURCE, $identifier, (int) $this->job_id );
			if ( ! is_string( $token ) ) {
				continue;
			}
			$packets[] = array(
				'type'      => 'fetch',
				'timestamp' => time(),
				'data'      => array(
					'title' => 'Item ' . $i,
					'body'  => 'Claimed source item.',
				),
				'metadata'  => array(
					'source_type'     => SyncRunnerClaimReleaseTest::SOURCE,
					'item_identifier' => $identifier,
					'claim_token'     => $token,
				),
			);
		}
		return $packets;
	}
}

/**
 * Sink step that commits each claimed item, like a real publish handler.
 */
class SyncRunnerClaimSinkStep extends Step {

	public function __construct() {
		parent::__construct( 'sync_claim_sink' );
	}

	protected function validateStepConfiguration(): bool {
		return true;
	}

	protected function executeStep(): array {
		$processed = new ProcessedItems();
		foreach ( $this->dataPackets as $packet ) {
			$metadata = $packet['metadata'] ?? array();
			$processed->complete_owned_claim(
				SyncRunnerClaimReleaseTest::SCOPE,
				SyncRunnerClaimReleaseTest::SOURCE,
				(string) ( $metadata['item_identifier'] ?? '' ),
				(string) ( $metadata['claim_token'] ?? '' ),
				(int) $this->job_id
			);
		}
		return $this->dataPackets;
	}
}
