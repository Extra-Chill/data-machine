<?php
/**
 * DB-backed source-item eligibility classification tests.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use WP_UnitTestCase;

class ExecutionContextSourceEligibilityTest extends WP_UnitTestCase {

	private ProcessedItems $db;
	private ExecutionContext $context;
	private string $flow_step_id = '2945_source_eligibility';
	private string $source_type  = 'generic_source';

	public function set_up(): void {
		parent::set_up();
		$this->db      = new ProcessedItems();
		$this->context = ExecutionContext::fromFlow( 29, 45, $this->flow_step_id, '2945', $this->source_type );
		$this->db->delete_processed_items( array( 'flow_step_id' => $this->flow_step_id ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'datamachine_should_reprocess_item' );
		$this->db->delete_processed_items( array( 'flow_step_id' => $this->flow_step_id ) );
		parent::tear_down();
	}

	public function test_classifies_mixed_ordered_states_without_lifecycle_writes(): void {
		global $wpdb;

		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'processed', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'reprocess', 1 );
		$this->db->claim_item( $this->flow_step_id, $this->source_type, 'active-claim', 2 );
		$this->db->claim_item( $this->flow_step_id, $this->source_type, 'expired-claim', 3 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->db->get_table_name(),
			array( 'claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array(
				'flow_step_id'    => $this->flow_step_id,
				'source_type'     => $this->source_type,
				'item_identifier' => 'expired-claim',
			),
			array( '%s' ),
			array( '%s', '%s', '%s' )
		);

		$filter_contexts = array();
		add_filter(
			'datamachine_should_reprocess_item',
			static function ( bool $skip, array $context ) use ( &$filter_contexts ): bool {
				$filter_contexts[] = $context;
				return 'reprocess' === $context['item_identifier'] ? false : $skip;
			},
			10,
			2
		);

		$before = $this->lifecycle_rows();
		$result = $this->context->classifySourceItems(
			array( 'fresh', 'processed', 'active-claim', 'reprocess', 'expired-claim', 'fresh', 'last' ),
			3
		);
		$after = $this->lifecycle_rows();

		$this->assertSame(
			array(
				ExecutionContext::ITEM_ELIGIBLE,
				ExecutionContext::ITEM_PROCESSED_SKIPPED,
				ExecutionContext::ITEM_ACTIVELY_CLAIMED,
				ExecutionContext::ITEM_PROCESSED_REPROCESS_ELIGIBLE,
				ExecutionContext::ITEM_ELIGIBLE,
				ExecutionContext::ITEM_ELIGIBLE_OUTSIDE_MAX_ITEMS,
				ExecutionContext::ITEM_ELIGIBLE_OUTSIDE_MAX_ITEMS,
			),
			array_column( $result['classifications'], 'status' )
		);
		$this->assertSame( array( 'fresh', 'reprocess', 'expired-claim' ), $result['selected_identifiers'] );
		$this->assertSame(
			array(
				'total'                        => 7,
				'unique'                       => 6,
				'duplicates'                   => 1,
				'eligible'                     => 5,
				'processed_skipped'            => 1,
				'processed_reprocess_eligible' => 1,
				'actively_claimed'             => 1,
				'eligible_outside_max_items'   => 2,
				'selected'                     => 3,
				'max_items'                    => 3,
			),
			$result['diagnostics']
		);
		$this->assertSame( $before, $after, 'Classification must not acquire, release, expire, or process lifecycle rows.' );
		$this->assertCount( 7, $filter_contexts );
		$this->assertSame( $this->flow_step_id, $filter_contexts[0]['flow_step_id'] );
		$this->assertSame( $this->source_type, $filter_contexts[0]['source_type'] );
		$this->assertSame( 2945, $filter_contexts[0]['job_id'] );
	}

	public function test_two_thousand_identifiers_use_bounded_chunked_reads(): void {
		global $wpdb;

		$identifiers  = array_map( static fn ( int $index ): string => 'candidate-' . $index, range( 1, 2000 ) );
		$query_count = $wpdb->num_queries;

		$result = $this->context->classifySourceItems( $identifiers, 10 );
		$reads  = $wpdb->num_queries - $query_count;

		$this->assertSame( 4, $reads, 'A 500-identifier chunk size should issue four lifecycle reads.' );
		$this->assertSame( array_slice( $identifiers, 0, 10 ), $result['selected_identifiers'] );
		$this->assertSame( 1990, $result['diagnostics']['eligible_outside_max_items'] );
		$this->assertCount( 2000, $result['classifications'] );
	}

	public function test_fetch_handler_uses_the_same_classification_decisions(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'processed', 1 );
		$this->db->claim_item( $this->flow_step_id, $this->source_type, 'claimed', 2 );

		$items = array(
			array( 'title' => 'Fresh', 'metadata' => array( 'item_identifier' => 'fresh' ) ),
			array( 'title' => 'Processed', 'metadata' => array( 'item_identifier' => 'processed' ) ),
			array( 'title' => 'Claimed', 'metadata' => array( 'item_identifier' => 'claimed' ) ),
			array( 'title' => 'Untracked' ),
		);
		$handler = new class( $this->source_type ) extends FetchHandler {
			protected function executeFetch( array $config, ExecutionContext $context ): array {
				return array();
			}
		};

		$method = new \ReflectionMethod( FetchHandler::class, 'filterProcessed' );
		$method->setAccessible( true );
		$filtered = $method->invoke( $handler, $items, $this->context );

		$this->assertSame( array( 'Fresh', 'Untracked' ), array_column( $filtered, 'title' ) );
		$this->assertSame(
			array( 'fresh' ),
			$this->context->classifySourceItems( array( 'fresh', 'processed', 'claimed' ) )['selected_identifiers']
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function lifecycle_rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, flow_step_id, source_type, item_identifier, job_id, status, claim_expires_at, claim_token, processed_timestamp FROM %i WHERE flow_step_id = %s ORDER BY id',
				$this->db->get_table_name(),
				$this->flow_step_id
			),
			ARRAY_A
		);
	}
}
