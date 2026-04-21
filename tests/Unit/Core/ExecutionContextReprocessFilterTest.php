<?php
/**
 * ExecutionContext `datamachine_should_reprocess_item` Filter Tests
 *
 * Coverage for the revisit-window wire point added in 0.71.0:
 *   - default behavior (no filter) unchanged
 *   - filter can force a processed item back into the pipeline
 *   - filter context payload carries the expected keys
 *   - direct / standalone modes bypass the check entirely
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\ExecutionContext;
use WP_UnitTestCase;

class ExecutionContextReprocessFilterTest extends WP_UnitTestCase {

	private ProcessedItems $db;
	private int $pipeline_id = 999;
	private int $flow_id     = 88888;
	private string $flow_step_id;
	private string $handler_type = 'wiki_post';
	private string $item_identifier = 'post-42';

	public function set_up(): void {
		parent::set_up();
		$this->db           = new ProcessedItems();
		$this->flow_step_id = '1_' . $this->flow_id;
		$this->db->delete_processed_items( array( 'flow_step_id' => $this->flow_step_id ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'datamachine_should_reprocess_item' );
		$this->db->delete_processed_items( array( 'flow_step_id' => $this->flow_step_id ) );
		parent::tear_down();
	}

	public function test_default_behavior_unchanged_when_item_not_processed(): void {
		$ctx = ExecutionContext::fromFlow(
			$this->pipeline_id,
			$this->flow_id,
			$this->flow_step_id,
			null,
			$this->handler_type
		);

		$this->assertFalse( $ctx->isItemProcessed( $this->item_identifier ) );
	}

	public function test_default_behavior_unchanged_when_item_processed(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->handler_type, $this->item_identifier, 1 );

		$ctx = ExecutionContext::fromFlow(
			$this->pipeline_id,
			$this->flow_id,
			$this->flow_step_id,
			null,
			$this->handler_type
		);

		$this->assertTrue( $ctx->isItemProcessed( $this->item_identifier ) );
	}

	public function test_filter_can_force_reprocessing_of_seen_item(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->handler_type, $this->item_identifier, 1 );

		add_filter(
			'datamachine_should_reprocess_item',
			static function ( $skip, $context ) {
				// Force reprocess regardless of history.
				return false;
			},
			10,
			2
		);

		$ctx = ExecutionContext::fromFlow(
			$this->pipeline_id,
			$this->flow_id,
			$this->flow_step_id,
			null,
			$this->handler_type
		);

		$this->assertFalse(
			$ctx->isItemProcessed( $this->item_identifier ),
			'Filter returning false should mark item as not-skipped (i.e. process again).'
		);
	}

	public function test_filter_context_payload_carries_expected_keys(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->handler_type, $this->item_identifier, 1 );

		$captured = null;

		add_filter(
			'datamachine_should_reprocess_item',
			function ( $skip, $context ) use ( &$captured ) {
				$captured = $context;
				return $skip;
			},
			10,
			2
		);

		$ctx = ExecutionContext::fromFlow(
			$this->pipeline_id,
			$this->flow_id,
			$this->flow_step_id,
			'7',
			$this->handler_type
		);

		$ctx->isItemProcessed( $this->item_identifier );

		$this->assertIsArray( $captured );
		$this->assertSame( $this->flow_step_id, $captured['flow_step_id'] );
		$this->assertSame( $this->handler_type, $captured['source_type'] );
		$this->assertSame( $this->item_identifier, $captured['item_identifier'] );
		$this->assertSame( 7, $captured['job_id'] );
	}

	public function test_filter_not_invoked_in_direct_mode(): void {
		$invoked = false;

		add_filter(
			'datamachine_should_reprocess_item',
			function ( $skip, $context ) use ( &$invoked ) {
				$invoked = true;
				return $skip;
			},
			10,
			2
		);

		$ctx = ExecutionContext::direct( $this->handler_type );
		$this->assertFalse( $ctx->isItemProcessed( $this->item_identifier ) );
		$this->assertFalse( $invoked, 'Filter should not fire in direct mode.' );
	}

	public function test_filter_not_invoked_in_standalone_mode(): void {
		$invoked = false;

		add_filter(
			'datamachine_should_reprocess_item',
			function ( $skip, $context ) use ( &$invoked ) {
				$invoked = true;
				return $skip;
			},
			10,
			2
		);

		$ctx = ExecutionContext::standalone( null, $this->handler_type );
		$this->assertFalse( $ctx->isItemProcessed( $this->item_identifier ) );
		$this->assertFalse( $invoked, 'Filter should not fire in standalone mode.' );
	}
}
