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
	// Claims
	// -----------------------------------------------------------------

	public function test_claim_item_converts_existing_processed_row_to_claim(): void {
		global $wpdb;

		$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'claim-existing', 10 );

		$this->assertTrue( $this->db->claim_item( $this->flow_step_id, $this->source_type, 'claim-existing', 11 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, job_id, claim_expires_at FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s',
				$this->db->get_table_name(),
				$this->flow_step_id,
				$this->source_type,
				'claim-existing'
			),
			ARRAY_A
		);

		$this->assertSame( ProcessedItems::STATUS_CLAIMED, $row['status'] );
		$this->assertSame( '11', $row['job_id'] );
		$this->assertNotEmpty( $row['claim_expires_at'] );
	}

	public function test_claim_item_does_not_steal_active_claim(): void {
		$this->assertTrue( $this->db->claim_item( $this->flow_step_id, $this->source_type, 'active-claim', 10 ) );
		$this->assertFalse( $this->db->claim_item( $this->flow_step_id, $this->source_type, 'active-claim', 11 ) );
	}

	public function test_deferral_count_persists_across_source_reappearance_and_same_job_is_idempotent(): void {
		$claim = $this->owned_claim( 'defer-persistent', 501 );
		$first = $this->db->record_owned_deferral_attempt( $claim, 501 );
		$again = $this->db->record_owned_deferral_attempt( $claim, 501 );

		$this->assertSame( 1, $first['attempts'] );
		$this->assertSame( $first, $again );
		$this->assertSame( array( 'attempts' => 1, 'exhausted' => false ), $this->db->finalize_owned_deferral_in_transaction( $claim, 501 ) );

		$reappeared = $this->owned_claim( 'defer-persistent', 502 );
		$this->assertSame( 2, $this->db->record_owned_deferral_attempt( $reappeared, 502 )['attempts'] );
	}

	public function test_reappearance_release_redefers_history_but_fresh_release_deletes(): void {
		$claim = $this->owned_claim( 'release-history', 511 );
		$this->db->record_owned_deferral_attempt( $claim, 511 );
		$this->db->finalize_owned_deferral_in_transaction( $claim, 511 );

		$reappeared = $this->owned_claim( 'release-history', 512 );
		$this->assertSame(
			1,
			$this->db->release_owned_claim( $this->flow_step_id, $this->source_type, 'release-history', $reappeared['ownership_token'] )
		);
		$row = $this->row( 'release-history' );
		$this->assertSame( ProcessedItems::STATUS_DEFERRED, $row['status'] );
		$this->assertSame( '1', $row['deferral_count'] );
		$this->assertNull( $row['claim_token'] );

		$fresh = $this->owned_claim( 'release-fresh', 513 );
		$this->assertSame( 1, $this->db->release_owned_claim( $this->flow_step_id, $this->source_type, 'release-fresh', $fresh['ownership_token'] ) );
		$this->assertNull( $this->row( 'release-fresh' ) );
	}

	public function test_release_by_job_redefers_history_and_is_idempotent_under_stale_ownership(): void {
		$claim = $this->owned_claim( 'job-release-history', 521 );
		$this->db->record_owned_deferral_attempt( $claim, 521 );
		$this->db->finalize_owned_deferral_in_transaction( $claim, 521 );
		$reappeared = $this->owned_claim( 'job-release-history', 522 );

		$this->assertSame( 0, $this->db->release_owned_claim( $this->flow_step_id, $this->source_type, 'job-release-history', $claim['ownership_token'] ) );
		$this->assertTrue( $this->db->owns_active_claim( $reappeared, 522 ) );
		$this->assertSame( 1, $this->db->release_claims_for_job( 522 ) );
		$this->assertSame( 0, $this->db->release_claims_for_job( 522 ) );
		$this->assertSame( ProcessedItems::STATUS_DEFERRED, $this->row( 'job-release-history' )['status'] );
	}

	public function test_third_deferral_terminalizes_as_processed(): void {
		for ( $attempt = 1; $attempt <= ProcessedItems::MAX_DEFERRAL_ATTEMPTS; ++$attempt ) {
			$job_id = 600 + $attempt;
			$claim  = $this->owned_claim( 'defer-cap', $job_id );
			$state  = $this->db->record_owned_deferral_attempt( $claim, $job_id );
			$this->assertSame( $attempt, $state['attempts'] );
			$this->assertSame( $attempt === ProcessedItems::MAX_DEFERRAL_ATTEMPTS, $state['exhausted'] );
			$this->assertSame( $state, $this->db->finalize_owned_deferral_in_transaction( $claim, $job_id ) );
		}

		$this->assertTrue( $this->db->has_item_been_processed( $this->flow_step_id, $this->source_type, 'defer-cap' ) );
	}

	public function test_stale_deferral_report_only_returns_unseen_rows_outside_window(): void {
		$stale = $this->owned_claim( 'stale-defer', 801 );
		$fresh = $this->owned_claim( 'fresh-defer', 802 );
		$this->db->record_owned_deferral_attempt( $stale, 801 );
		$this->db->finalize_owned_deferral_in_transaction( $stale, 801 );
		$this->db->record_owned_deferral_attempt( $fresh, 802 );
		$this->db->finalize_owned_deferral_in_transaction( $fresh, 802 );

		global $wpdb;
		$wpdb->update(
			$this->db->get_table_name(),
			array( 'deferred_at' => gmdate( 'Y-m-d H:i:s', time() - ( 72 * HOUR_IN_SECONDS ) ) ),
			array( 'flow_step_id' => $this->flow_step_id, 'source_type' => $this->source_type, 'item_identifier' => 'stale-defer' ),
			array( '%s' ),
			array( '%s', '%s', '%s' )
		);

		$page = $this->db->find_stale_deferrals( 48, 10 );
		$this->assertSame( array( 'stale-defer' ), array_column( $page['items'], 'item_identifier' ) );
		$this->assertFalse( $page['has_more'] );

		$reappeared = $this->owned_claim( 'stale-defer', 803 );
		$this->assertNotEmpty( $reappeared );
		$this->assertSame( array(), $this->db->find_stale_deferrals( 48, 10 )['items'] );
	}

	public function test_stale_deferral_cursor_reaches_every_row_without_lying_about_count(): void {
		$identifiers = array( 'cursor-a', 'cursor-b', 'cursor-c' );
		foreach ( $identifiers as $offset => $identifier ) {
			$job_id = 820 + $offset;
			$claim  = $this->owned_claim( $identifier, $job_id );
			$this->db->record_owned_deferral_attempt( $claim, $job_id );
			$this->db->finalize_owned_deferral_in_transaction( $claim, $job_id );
		}
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET deferred_at = %s WHERE flow_step_id = %s AND item_identifier IN (%s, %s, %s)',
				$this->db->get_table_name(),
				gmdate( 'Y-m-d H:i:s', time() - ( 72 * HOUR_IN_SECONDS ) ),
				$this->flow_step_id,
				...$identifiers
			)
		);

		$first  = $this->db->find_stale_deferrals( 48, 2 );
		$second = $this->db->find_stale_deferrals( 48, 2, $first['next_after_id'] );
		$this->assertCount( 2, $first['items'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertCount( 1, $second['items'] );
		$this->assertFalse( $second['has_more'] );
		$this->assertSame( 0, $second['next_after_id'] );
		$this->assertSame( $identifiers, array_column( array_merge( $first['items'], $second['items'] ), 'item_identifier' ) );
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

	public function test_deferred_status_timestamp_index_exists(): void {
		global $wpdb;
		$table = $this->db->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'status_deferred_at'" );
		$this->assertNotEmpty( $rows, 'Deferred identity reporting requires its bounded status/timestamp index.' );
	}

	public function test_deferral_schema_repairs_malformed_same_name_column_and_index(): void {
		global $wpdb;
		$table = $this->db->get_table_name();
		try {
			$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'schema-repair-row', 901 );
			$rows_before = $this->schema_repair_rows();
			$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN `last_seen_at` VARCHAR(30) NOT NULL DEFAULT 'bad'" );
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX `status_deferred_at`, ADD KEY `status_deferred_at` (`deferred_at`, `status`)" );
			$this->assertFalse( ProcessedItems::validate_deferral_schema( $table ) );

			ProcessedItems::ensure_deferral_schema( $table );
			$this->assertTrue( ProcessedItems::validate_deferral_schema( $table ) );
			$this->assert_deferral_column_metadata();
			$this->assert_deferral_index_metadata();
			$this->assertSame( $rows_before, $this->schema_repair_rows() );
		} finally {
			ProcessedItems::ensure_deferral_schema( $table );
		}
	}

	public function test_deferral_schema_repairs_unique_and_prefix_indexes(): void {
		global $wpdb;
		$table = $this->db->get_table_name();
		try {
			$this->db->add_processed_item( $this->flow_step_id, $this->source_type, 'index-repair-row', 902 );
			$rows_before = $this->schema_repair_rows();
			$this->assert_index_repair(
				"ALTER TABLE {$table} DROP INDEX `status_deferred_at`, ADD UNIQUE KEY `status_deferred_at` (`status`, `deferred_at`)",
				$rows_before
			);
			$this->assert_index_repair(
				"ALTER TABLE {$table} DROP INDEX `status_deferred_at`, ADD KEY `status_deferred_at` (`status`(8), `deferred_at`)",
				$rows_before
			);
		} finally {
			ProcessedItems::ensure_deferral_schema( $table );
		}
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

	/** Claim an exact test identity and return its lifecycle descriptor. */
	private function owned_claim( string $item_identifier, int $job_id ): array {
		$token = $this->db->claim_item_owned( $this->flow_step_id, $this->source_type, $item_identifier, $job_id );
		$this->assertIsString( $token );
		return array(
			'identity_scope'  => $this->flow_step_id,
			'source_type'     => $this->source_type,
			'item_identifier' => $item_identifier,
			'ownership_token' => $token,
		);
	}

	/** Read one test ledger row. */
	private function row( string $item_identifier ): ?array {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s',
				$this->db->get_table_name(),
				$this->flow_step_id,
				$this->source_type,
				$item_identifier
			),
			ARRAY_A
		) ?: null;
	}

	/** Assert canonical durable-deferral column definitions. */
	private function assert_deferral_column_metadata(): void {
		global $wpdb;
		$expected = array(
			'deferral_count'       => array( '/^int(?:\(\d+\))? unsigned$/', 'NO', '0' ),
			'last_deferral_job_id' => array( '/^bigint(?:\(\d+\))? unsigned$/', 'YES', null ),
			'deferred_at'          => array( '/^datetime$/', 'YES', null ),
			'last_seen_at'         => array( '/^datetime$/', 'YES', null ),
		);
		foreach ( $expected as $column => [ $type, $nullable, $default ] ) {
			$actual = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $this->db->get_table_name(), $column ), ARRAY_A );
			$this->assertIsArray( $actual );
			$this->assertMatchesRegularExpression( $type, strtolower( $actual['Type'] ) );
			$this->assertSame( $nullable, $actual['Null'] );
			$this->assertSame( $default, $actual['Default'] );
		}
	}

	/** Assert exact operational index shape and attributes. */
	private function assert_deferral_index_metadata(): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $this->db->get_table_name(), 'status_deferred_at' ), ARRAY_A );
		usort( $rows, static fn( array $left, array $right ): int => (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index'] );
		$this->assertSame( array( 'status', 'deferred_at' ), array_column( $rows, 'Column_name' ) );
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $rows, 'Seq_in_index' ) ) );
		$this->assertSame( array( 1, 1 ), array_map( 'intval', array_column( $rows, 'Non_unique' ) ) );
		$this->assertSame( array( null, null ), array_column( $rows, 'Sub_part' ) );
		$this->assertSame( array( 'BTREE', 'BTREE' ), array_map( 'strtoupper', array_column( $rows, 'Index_type' ) ) );
	}

	/** Malform, repair, and verify one operational index shape. */
	private function assert_index_repair( string $alter_query, array $rows_before ): void {
		global $wpdb;
		$wpdb->query( $alter_query );
		$table = $this->db->get_table_name();
		$this->assertFalse( ProcessedItems::validate_deferral_schema( $table ) );
		ProcessedItems::ensure_deferral_schema( $table );
		$this->assertTrue( ProcessedItems::validate_deferral_schema( $table ) );
		$this->assert_deferral_index_metadata();
		$this->assertSame( $rows_before, $this->schema_repair_rows() );
	}

	/** Snapshot row identity and lifecycle data unaffected by schema repair. */
	private function schema_repair_rows(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, flow_step_id, source_type, item_identifier, job_id, status FROM %i ORDER BY id ASC',
				$this->db->get_table_name()
			),
			ARRAY_A
		);
	}
}
