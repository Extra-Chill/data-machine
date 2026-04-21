<?php
/**
 * ProcessedItems Revisit API Tests
 *
 * Covers the time-windowed query methods added in 0.71.0:
 *   - get_processed_at
 *   - has_been_processed_within
 *   - find_stale
 *   - find_never_processed
 *
 * Also verifies the composite (flow_step_id, source_type, processed_timestamp)
 * index is created on activation.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use WP_UnitTestCase;

class ProcessedItemsTest extends WP_UnitTestCase {

	private ProcessedItems $db;
	private string $flow_step_id = '77_777';
	private string $source_type  = 'wiki_post';

	public function set_up(): void {
		parent::set_up();
		$this->db = new ProcessedItems();

		// Ensure isolation from other tests that might write to this table.
		$this->db->delete_processed_items( array( 'flow_step_id' => $this->flow_step_id ) );
	}

	public function tear_down(): void {
		$this->db->delete_processed_items( array( 'flow_step_id' => $this->flow_step_id ) );
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// get_processed_at
	// -----------------------------------------------------------------

	public function test_get_processed_at_returns_null_for_unknown_item(): void {
		$this->assertNull(
			$this->db->get_processed_at( $this->flow_step_id, $this->source_type, 'never-seen' )
		);
	}

	public function test_get_processed_at_returns_unix_timestamp_for_known_item(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'seen-1', 123 );

		$before = time() - 5;
		$after  = time() + 5;

		$ts = $this->db->get_processed_at( $this->flow_step_id, $this->source_type, 'seen-1' );

		$this->assertIsInt( $ts );
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( $after, $ts );
	}

	// -----------------------------------------------------------------
	// has_been_processed_within
	// -----------------------------------------------------------------

	public function test_has_been_processed_within_returns_false_when_never_processed(): void {
		$this->assertFalse(
			$this->db->has_been_processed_within( $this->flow_step_id, $this->source_type, 'never-seen', 7 )
		);
	}

	public function test_has_been_processed_within_returns_true_for_fresh_row(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'fresh-id', 1 );

		$this->assertTrue(
			$this->db->has_been_processed_within( $this->flow_step_id, $this->source_type, 'fresh-id', 7 )
		);
	}

	public function test_has_been_processed_within_returns_false_for_old_row(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'old-id', 1 );
		$this->backdate_rows( array( 'old-id' ), 30 );

		$this->assertFalse(
			$this->db->has_been_processed_within( $this->flow_step_id, $this->source_type, 'old-id', 7 )
		);
	}

	public function test_has_been_processed_within_rejects_zero_days(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'fresh-id-2', 1 );

		$this->assertFalse(
			$this->db->has_been_processed_within( $this->flow_step_id, $this->source_type, 'fresh-id-2', 0 )
		);
	}

	// -----------------------------------------------------------------
	// find_stale
	// -----------------------------------------------------------------

	public function test_find_stale_returns_empty_on_empty_candidate_list(): void {
		$this->assertSame( array(), $this->db->find_stale( $this->flow_step_id, $this->source_type, array(), 7 ) );
	}

	public function test_find_stale_returns_empty_when_all_candidates_fresh(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'a', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'b', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'c', 1 );

		$stale = $this->db->find_stale(
			$this->flow_step_id,
			$this->source_type,
			array( 'a', 'b', 'c' ),
			7
		);

		$this->assertSame( array(), $stale );
	}

	public function test_find_stale_returns_all_when_all_candidates_stale(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'a', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'b', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'c', 1 );
		$this->backdate_rows( array( 'a', 'b', 'c' ), 30 );

		$stale = $this->db->find_stale(
			$this->flow_step_id,
			$this->source_type,
			array( 'a', 'b', 'c' ),
			7
		);

		sort( $stale );
		$this->assertSame( array( 'a', 'b', 'c' ), $stale );
	}

	public function test_find_stale_returns_only_stale_on_mixed_input(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'fresh-1', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'stale-1', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'stale-2', 1 );
		$this->backdate_rows( array( 'stale-1', 'stale-2' ), 30 );

		$stale = $this->db->find_stale(
			$this->flow_step_id,
			$this->source_type,
			array( 'fresh-1', 'stale-1', 'stale-2', 'never-seen' ),
			7
		);

		sort( $stale );
		$this->assertSame( array( 'stale-1', 'stale-2' ), $stale );
	}

	public function test_find_stale_honors_limit(): void {
		foreach ( range( 1, 5 ) as $i ) {
			$this->db->add_processed_item( $this->flow_step_id, $this->source_type, "item-{$i}", 1 );
		}
		$this->backdate_rows( array( 'item-1', 'item-2', 'item-3', 'item-4', 'item-5' ), 30 );

		$stale = $this->db->find_stale(
			$this->flow_step_id,
			$this->source_type,
			array( 'item-1', 'item-2', 'item-3', 'item-4', 'item-5' ),
			7,
			2
		);

		$this->assertCount( 2, $stale );
	}

	public function test_find_stale_rejects_bad_max_age_days(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'x', 1 );

		$this->assertSame( array(), $this->db->find_stale( $this->flow_step_id, $this->source_type, array( 'x' ), 0 ) );
		$this->assertSame( array(), $this->db->find_stale( $this->flow_step_id, $this->source_type, array( 'x' ), -1 ) );
	}

	// -----------------------------------------------------------------
	// find_never_processed
	// -----------------------------------------------------------------

	public function test_find_never_processed_returns_empty_on_empty_candidate_list(): void {
		$this->assertSame(
			array(),
			$this->db->find_never_processed( $this->flow_step_id, $this->source_type, array() )
		);
	}

	public function test_find_never_processed_returns_all_when_none_exist(): void {
		$never = $this->db->find_never_processed(
			$this->flow_step_id,
			$this->source_type,
			array( 'new-1', 'new-2', 'new-3' )
		);

		$this->assertSame( array( 'new-1', 'new-2', 'new-3' ), $never );
	}

	public function test_find_never_processed_returns_empty_when_all_exist(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'x', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'y', 1 );

		$never = $this->db->find_never_processed(
			$this->flow_step_id,
			$this->source_type,
			array( 'x', 'y' )
		);

		$this->assertSame( array(), $never );
	}

	public function test_find_never_processed_returns_subset_on_mixed_input(): void {
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'known-1', 1 );
		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'known-2', 1 );

		$never = $this->db->find_never_processed(
			$this->flow_step_id,
			$this->source_type,
			array( 'known-1', 'new-1', 'known-2', 'new-2' )
		);

		$this->assertSame( array( 'new-1', 'new-2' ), $never );
	}

	public function test_find_never_processed_honors_limit(): void {
		$never = $this->db->find_never_processed(
			$this->flow_step_id,
			$this->source_type,
			array( 'a', 'b', 'c', 'd', 'e' ),
			2
		);

		$this->assertCount( 2, $never );
		$this->assertSame( array( 'a', 'b' ), $never );
	}

	public function test_find_never_processed_scopes_by_source_type(): void {
		$this->db->add_processed_item( $this->flow_step_id, 'different_source', 'only-there', 1 );

		$never = $this->db->find_never_processed(
			$this->flow_step_id,
			$this->source_type,
			array( 'only-there' )
		);

		$this->assertSame( array( 'only-there' ), $never );
	}

	// -----------------------------------------------------------------
	// Index / schema
	// -----------------------------------------------------------------

	public function test_composite_flow_source_ts_index_exists(): void {
		global $wpdb;

		$table = $this->db->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'flow_source_ts'" );

		$this->assertNotEmpty( $rows, 'Composite index flow_source_ts should exist after table creation.' );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Backdate a specific set of rows so they look old enough to be stale.
	 *
	 * @param string[] $identifiers Item identifiers to backdate.
	 * @param int      $days_ago    How many days back to set the timestamp.
	 */
	private function backdate_rows( array $identifiers, int $days_ago ): void {
		global $wpdb;

		$table    = $this->db->get_table_name();
		$backdate = gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );

		foreach ( $identifiers as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET processed_timestamp = %s WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s',
					$table,
					$backdate,
					$this->flow_step_id,
					$this->source_type,
					$id
				)
			);
		}
	}
}
