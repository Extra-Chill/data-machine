<?php
/**
 * Tests for the source-agnostic FreshCandidateCollector primitive.
 *
 * The collector helps fetch handlers paginate a source while skipping
 * already-processed and currently-claimed items. These tests stub
 * ExecutionContext so the primitive can be exercised without a live database.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\Fetch
 */

namespace DataMachine\Tests\Unit\Core\Steps\Fetch;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
use PHPUnit\Framework\TestCase;

/**
 * Test-only collector that overrides the database-touching raw check.
 *
 * Lets us simulate the `datamachine_should_reprocess_item` filter override
 * (item is in the processed table, but `isItemProcessed()` returned false)
 * without standing up a full WP_UnitTestCase environment.
 */
class StubFreshCandidateCollector extends FreshCandidateCollector {

	/** @var array<string,bool> */
	private array $raw_processed_map = array();

	public function setRawProcessed( string $identifier, bool $processed ): void {
		$this->raw_processed_map[ $identifier ] = $processed;
	}

	protected function rawIsProcessed( string $identifier ): bool {
		return $this->raw_processed_map[ $identifier ] ?? false;
	}
}

class FreshCandidateCollectorTest extends TestCase {

	// -----------------------------------------------------------------
	// Acceptance — fresh items pass through.
	// -----------------------------------------------------------------

	public function test_fresh_candidate_is_accepted(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 5 );

		$this->assertTrue( $collector->offer( 'item-1', array( 'id' => 1 ) ) );
		$this->assertSame( 1, $collector->count() );
		$this->assertSame( array( array( 'id' => 1 ) ), $collector->getAccepted() );
	}

	public function test_payload_defaults_to_identifier(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 5 );

		$collector->offer( 'plain-id' );
		$this->assertSame( array( 'plain-id' ), $collector->getAccepted() );
	}

	public function test_empty_identifier_is_rejected_without_counting(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 5 );

		$this->assertFalse( $collector->offer( '' ) );
		$this->assertSame( 0, $collector->count() );
		$this->assertSame( 0, $collector->getDiagnostics()['raw_seen'] );
	}

	// -----------------------------------------------------------------
	// Processed candidate skip.
	// -----------------------------------------------------------------

	public function test_processed_candidate_is_skipped_with_diagnostic(): void {
		$context = $this->buildContext( array( 'item-2' => true ) );
		$collector = new FreshCandidateCollector( $context, 5 );

		$this->assertTrue( $collector->offer( 'item-1' ) );
		$this->assertFalse( $collector->offer( 'item-2' ) );
		$this->assertTrue( $collector->offer( 'item-3' ) );

		$diag = $collector->getDiagnostics();
		$this->assertSame( 3, $diag['raw_seen'] );
		$this->assertSame( 2, $diag['accepted'] );
		$this->assertSame( 1, $diag['processed_skipped'] );
		$this->assertSame( 0, $diag['claimed_skipped'] );
	}

	// -----------------------------------------------------------------
	// Claimed candidate skip.
	// -----------------------------------------------------------------

	public function test_claimed_candidate_is_skipped_with_diagnostic(): void {
		$context = $this->buildContext( array(), array( 'item-2' => true ) );
		$collector = new FreshCandidateCollector( $context, 5 );

		$this->assertTrue( $collector->offer( 'item-1' ) );
		$this->assertFalse( $collector->offer( 'item-2' ) );

		$diag = $collector->getDiagnostics();
		$this->assertSame( 0, $diag['processed_skipped'] );
		$this->assertSame( 1, $diag['claimed_skipped'] );
		$this->assertSame( 1, $diag['accepted'] );
	}

	public function test_processed_check_runs_before_claim_check(): void {
		// Item that is BOTH processed and claimed should attribute to the
		// processed bucket, since the default skip decision is dominated by
		// the persisted processed row.
		$context = $this->buildContext(
			array( 'item-1' => true ),
			array( 'item-1' => true )
		);
		$collector = new FreshCandidateCollector( $context, 5 );

		$collector->offer( 'item-1' );
		$diag = $collector->getDiagnostics();
		$this->assertSame( 1, $diag['processed_skipped'] );
		$this->assertSame( 0, $diag['claimed_skipped'] );
	}

	// -----------------------------------------------------------------
	// Reprocess override — filter forces a processed row back through.
	// -----------------------------------------------------------------

	public function test_reprocess_override_is_accepted_and_diagnosed(): void {
		// `isItemProcessed()` returns false because the reprocess filter
		// overrode the default skip — but the underlying row still exists.
		$context = $this->buildContext( array(), array() );
		$collector = new StubFreshCandidateCollector( $context, 5 );
		$collector->setRawProcessed( 'revisit-me', true );

		$this->assertTrue( $collector->offer( 'revisit-me', array( 'id' => 'revisit-me' ) ) );

		$diag = $collector->getDiagnostics();
		$this->assertSame( 1, $diag['accepted'] );
		$this->assertSame( 1, $diag['reprocess_accepted'] );
		$this->assertSame( 0, $diag['processed_skipped'] );
	}

	public function test_truly_fresh_candidate_does_not_count_as_reprocess(): void {
		$context = $this->buildContext();
		$collector = new StubFreshCandidateCollector( $context, 5 );
		$collector->setRawProcessed( 'never-seen', false );

		$collector->offer( 'never-seen' );
		$this->assertSame( 0, $collector->getDiagnostics()['reprocess_accepted'] );
	}

	// -----------------------------------------------------------------
	// Max candidate collection.
	// -----------------------------------------------------------------

	public function test_collector_stops_accepting_once_full(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 2 );

		$this->assertTrue( $collector->offer( 'item-1' ) );
		$this->assertTrue( $collector->offer( 'item-2' ) );
		$this->assertTrue( $collector->isFull() );
		$this->assertFalse( $collector->offer( 'item-3' ) );
		$this->assertSame( 2, $collector->count() );
	}

	public function test_max_items_zero_means_unlimited(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 0 );

		for ( $i = 0; $i < 50; $i++ ) {
			$collector->offer( "item-{$i}" );
		}

		$this->assertSame( 50, $collector->count() );
		$this->assertFalse( $collector->isFull() );
	}

	public function test_full_collector_does_not_increment_processed_skipped(): void {
		// Once full, additional candidates short-circuit before consulting
		// processed/claim state — the collector is done caring.
		$context = $this->buildContext( array( 'item-3' => true ) );
		$collector = new FreshCandidateCollector( $context, 2 );

		$collector->offer( 'item-1' );
		$collector->offer( 'item-2' );
		$collector->offer( 'item-3' ); // would-be skip, but collector is full
		$collector->offer( 'item-4' );

		$diag = $collector->getDiagnostics();
		$this->assertSame( 2, $diag['accepted'] );
		$this->assertSame( 0, $diag['processed_skipped'] );
		$this->assertSame( 4, $diag['raw_seen'] );
	}

	// -----------------------------------------------------------------
	// Source exhaustion.
	// -----------------------------------------------------------------

	public function test_source_exhaustion_is_recorded(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 5 );

		$collector->offer( 'item-1' );
		$this->assertFalse( $collector->isExhausted() );
		$this->assertFalse( $collector->getDiagnostics()['source_exhausted'] );

		$collector->markExhausted();

		$this->assertTrue( $collector->isExhausted() );
		$this->assertTrue( $collector->getDiagnostics()['source_exhausted'] );
	}

	public function test_mark_exhausted_is_idempotent(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 5 );

		$collector->markExhausted();
		$collector->markExhausted();

		$this->assertTrue( $collector->isExhausted() );
	}

	// -----------------------------------------------------------------
	// Duplicate identifier guard within a single scan.
	// -----------------------------------------------------------------

	public function test_duplicate_identifier_is_skipped_within_scan(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 5 );

		$this->assertTrue( $collector->offer( 'item-1' ) );
		$this->assertFalse( $collector->offer( 'item-1' ) );

		$diag = $collector->getDiagnostics();
		$this->assertSame( 1, $diag['accepted'] );
		$this->assertSame( 1, $diag['duplicate_skipped'] );
	}

	// -----------------------------------------------------------------
	// Diagnostic shape.
	// -----------------------------------------------------------------

	public function test_diagnostics_shape_is_stable(): void {
		$collector = new FreshCandidateCollector( $this->buildContext(), 7 );

		$diag = $collector->getDiagnostics();

		$this->assertSame(
			array(
				'raw_seen',
				'accepted',
				'processed_skipped',
				'claimed_skipped',
				'duplicate_skipped',
				'reprocess_accepted',
				'max_items',
				'source_exhausted',
			),
			array_keys( $diag )
		);
		$this->assertSame( 7, $diag['max_items'] );
	}

	// -----------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------

	/**
	 * Build an ExecutionContext mock that returns canned processed/claim
	 * answers from the supplied lookup maps.
	 *
	 * @param array<string,bool> $processed_map  identifier => isItemProcessed result
	 * @param array<string,bool> $claimed_map    identifier => isItemClaimed result
	 */
	private function buildContext( array $processed_map = array(), array $claimed_map = array() ): ExecutionContext {
		$context = $this->getMockBuilder( ExecutionContext::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'isItemProcessed', 'isItemClaimed', 'isDirect', 'isStandalone', 'getFlowStepId', 'getHandlerType' ) )
			->getMock();

		$context->method( 'isItemProcessed' )->willReturnCallback(
			static function ( string $identifier ) use ( $processed_map ): bool {
				return $processed_map[ $identifier ] ?? false;
			}
		);
		$context->method( 'isItemClaimed' )->willReturnCallback(
			static function ( string $identifier ) use ( $claimed_map ): bool {
				return $claimed_map[ $identifier ] ?? false;
			}
		);
		$context->method( 'isDirect' )->willReturn( false );
		$context->method( 'isStandalone' )->willReturn( false );
		$context->method( 'getFlowStepId' )->willReturn( 'flow-step-1' );
		$context->method( 'getHandlerType' )->willReturn( 'test_source' );

		return $context;
	}
}
