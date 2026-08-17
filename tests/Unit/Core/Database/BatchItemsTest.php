<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Database integration coverage inspects Data Machine-owned operational rows directly.
/**
 * Durable batch item repository tests.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\BatchItems\BatchItems;
use DataMachine\Core\ActionScheduler\BatchScheduler;
use WP_UnitTestCase;

class BatchItemsTest extends WP_UnitTestCase {

	private BatchItems $repository;
	private int $batch_job_id;

	public function set_up(): void {
		parent::set_up();
		BatchItems::create_table();
		$this->repository   = new BatchItems();
		$this->batch_job_id = random_int( 100000, 999999 );
	}

	public function tear_down(): void {
		$this->repository->delete_batch( $this->batch_job_id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'test_batch_hook' );
		}
		parent::tear_down();
	}

	public function test_schema_identity_and_payload_checksum(): void {
		global $wpdb;
		$item = array( 'title' => 'one' );
		$insert = $this->repository->insert_batch( $this->batch_job_id, array( $item ), array( array( 'claim' => 'a' ) ) );
		$this->assertTrue( $insert['success'] );
		$this->assertTrue( $insert['created'] );

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE batch_job_id = %d AND item_index = 0', $this->repository->get_table_name(), $this->batch_job_id ),
			ARRAY_A
		);
		$this->assertSame( hash( 'sha256', (string) wp_json_encode( $item ) ), $row['payload_checksum'] );
		$conflict = $this->repository->insert_batch( $this->batch_job_id, array( array( 'title' => 'different' ) ), array( array() ) );
		$this->assertFalse( $conflict['success'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d', $this->repository->get_table_name(), $this->batch_job_id ) ) );
		$this->assertSame( wp_json_encode( $item ), $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE batch_job_id = %d AND item_index = 0', $this->repository->get_table_name(), $this->batch_job_id ) ) );
		$cleanup_conflict = $this->repository->insert_batch( $this->batch_job_id, array( $item ), array( array( 'claim' => 'different' ) ) );
		$this->assertFalse( $cleanup_conflict['success'] );
		$this->assertSame( wp_json_encode( array( 'claim' => 'a' ) ), $wpdb->get_var( $wpdb->prepare( 'SELECT cleanup_context FROM %i WHERE batch_job_id = %d AND item_index = 0', $this->repository->get_table_name(), $this->batch_job_id ) ) );
	}

	public function test_bulk_insert_and_exact_retry_are_idempotent(): void {
		global $wpdb;
		$items   = array_map( static fn( int $index ): array => array( 'index' => $index ), range( 0, 249 ) );
		$cleanup = array_fill( 0, count( $items ), array() );
		$first   = $this->repository->insert_batch( $this->batch_job_id, $items, $cleanup );
		$retry   = $this->repository->insert_batch( $this->batch_job_id, $items, $cleanup );

		$this->assertTrue( $first['success'] );
		$this->assertTrue( $first['created'] );
		$this->assertTrue( $retry['success'] );
		$this->assertTrue( $retry['existing'] );
		$this->assertSame( 250, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d', $this->repository->get_table_name(), $this->batch_job_id ) ) );
	}

	public function test_active_claim_is_excluded_and_expired_claim_is_taken_over(): void {
		global $wpdb;
		$this->assertTrue( $this->repository->insert_batch( $this->batch_job_id, array( array( 'id' => 1 ) ), array( array() ) )['success'] );
		$first = $this->repository->claim_chunk( $this->batch_job_id, 0, 1, 60 );
		$this->assertCount( 1, $first );
		$this->assertSame( array(), $this->repository->claim_chunk( $this->batch_job_id, 0, 1, 60 ) );

		$wpdb->update(
			$this->repository->get_table_name(),
			array( 'lease_expires_at' => '2000-01-01 00:00:00' ),
			array( 'batch_job_id' => $this->batch_job_id, 'item_index' => 0 ),
			array( '%s' ),
			array( '%d', '%d' )
		);
		$second = $this->repository->claim_chunk( $this->batch_job_id, 0, 1, 60 );
		$this->assertCount( 1, $second );
		$this->assertNotSame( $first[0]['lease_token'], $second[0]['lease_token'] );
	}

	public function test_claim_owner_failure_rolls_back_the_lease(): void {
		global $wpdb;
		$this->assertTrue( $this->repository->insert_batch( $this->batch_job_id, array( array( 'id' => 1 ) ), array( array() ) )['success'] );

		$claimed = $this->repository->claim_chunk( $this->batch_job_id, 0, 1, 60, static fn(): bool => false );

		$this->assertSame( array(), $claimed );
		$this->assertSame( BatchItems::STATE_READY, $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM %i WHERE batch_job_id = %d AND item_index = 0', $this->repository->get_table_name(), $this->batch_job_id ) ) );
	}

	public function test_stale_token_is_fenced_and_release_supports_partial_retry(): void {
		global $wpdb;
		$this->assertTrue( $this->repository->insert_batch( $this->batch_job_id, array( array( 'id' => 1 ), array( 'id' => 2 ) ), array( array(), array() ) )['success'] );
		$claimed = $this->repository->claim_chunk( $this->batch_job_id, 0, 2, 60 );
		$this->assertFalse( $this->repository->complete( $this->batch_job_id, 0, 'stale-token', 11 ) );
		$this->assertTrue( $this->repository->complete( $this->batch_job_id, 0, $claimed[0]['lease_token'], 11 ) );
		$this->assertTrue( $this->repository->release( $this->batch_job_id, 1, $claimed[1]['lease_token'] ) );

		$retry = $this->repository->claim_chunk( $this->batch_job_id, 0, 2, 60 );
		$this->assertCount( 1, $retry );
		$this->assertSame( 1, (int) $retry[0]['item_index'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d AND state = %s', $this->repository->get_table_name(), $this->batch_job_id, BatchItems::STATE_COMPLETED ) ) );
	}

	public function test_cancellation_discard_fences_active_claim(): void {
		$this->assertTrue( $this->repository->insert_batch( $this->batch_job_id, array( array( 'id' => 1 ) ), array( array() ) )['success'] );
		$claimed = $this->repository->claim_chunk( $this->batch_job_id, 0, 1, 60 );
		$request = $this->repository->request_cancellation( $this->batch_job_id );

		$this->assertTrue( $request['success'] );
		$this->assertCount( 0, $request['rows'] );
		$this->assertFalse( $this->repository->complete( $this->batch_job_id, 0, $claimed[0]['lease_token'], 99 ) );
		$this->assertSame( 0, $this->repository->first_outstanding_index( $this->batch_job_id ) );
		$result = $this->repository->discard_outstanding( $this->batch_job_id );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['rows'] );
		$this->assertNull( $this->repository->first_outstanding_index( $this->batch_job_id ) );
	}

	public function test_stale_worker_cannot_discard_replacement_cancel_pending_claim(): void {
		global $wpdb;
		$this->assertTrue( $this->repository->insert_batch( $this->batch_job_id, array( array( 'id' => 1 ) ), array( array() ) )['success'] );
		$first = $this->repository->claim_chunk( $this->batch_job_id, 0, 1, 60 );
		$wpdb->update(
			$this->repository->get_table_name(),
			array( 'lease_expires_at' => '2000-01-01 00:00:00' ),
			array( 'batch_job_id' => $this->batch_job_id, 'item_index' => 0 ),
			array( '%s' ),
			array( '%d', '%d' )
		);
		$replacement = $this->repository->claim_chunk( $this->batch_job_id, 0, 1, 60 );
		$this->assertTrue( $this->repository->request_cancellation( $this->batch_job_id )['success'] );
		$this->assertFalse( $this->repository->discard_cancel_pending( $this->batch_job_id, 0, $first[0]['lease_token'] ) );
		$this->assertTrue( $this->repository->discard_cancel_pending( $this->batch_job_id, 0, $replacement[0]['lease_token'] ) );
	}

	public function test_corrupt_payload_is_claimed_as_invalid_without_typed_value(): void {
		global $wpdb;
		$this->assertTrue( $this->repository->insert_batch( $this->batch_job_id, array( array( 'id' => 1 ) ), array( array() ) )['success'] );
		$wpdb->update(
			$this->repository->get_table_name(),
			array( 'payload' => '{invalid-json' ),
			array( 'batch_job_id' => $this->batch_job_id, 'item_index' => 0 ),
			array( '%s' ),
			array( '%d', '%d' )
		);
		$claimed = $this->repository->claim_chunk( $this->batch_job_id, 0, 1, 60 );
		$this->assertCount( 1, $claimed );
		$this->assertFalse( $claimed[0]['payload_valid'] );
		$this->assertSame( array(), $claimed[0]['payload'] );
	}

	public function test_start_does_not_schedule_when_parent_state_cannot_persist(): void {
		global $wpdb;
		$scheduled = 0;
		$filter    = static function () use ( &$scheduled ): int {
			++$scheduled;
			return 123;
		};
		add_filter( 'pre_as_schedule_single_action', $filter );
		try {
			$result = BatchScheduler::start( $this->batch_job_id, 'test_batch_hook', array( array( 'id' => 1 ) ) );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $filter );
		}
		$this->assertFalse( $result['scheduled'] );
		$this->assertSame( 0, $scheduled );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d', $this->repository->get_table_name(), $this->batch_job_id ) ) );
	}
}
