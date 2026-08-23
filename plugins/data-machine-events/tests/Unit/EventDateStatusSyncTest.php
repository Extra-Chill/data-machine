<?php
/**
 * Event-date status lifecycle and repair tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Cli\Check\CheckEventDateStatusCommand;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use WP_UnitTestCase;

class EventDateStatusSyncTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	private function create_event( string $status = 'publish' ): int {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Status Sync ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => $status,
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2030-08-23","startTime":"20:00"} /-->',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( $status, EventDatesTable::get( $post_id )->post_status );

		return $post_id;
	}

	private function force_index_status( int $post_id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			EventDatesTable::table_name(),
			array( 'post_status' => $status ),
			array( 'post_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function test_canonical_status_is_propagated_across_transitions_and_same_status_saves(): void {
		$post_id = $this->create_event( 'publish' );

		foreach ( array( 'draft', 'private', 'pending', 'publish' ) as $status ) {
			$this->assertSame( $post_id, wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ) ) );
			$this->assertSame( $status, EventDatesTable::get( $post_id )->post_status );
		}

		$this->force_index_status( $post_id, 'draft' );
		$this->assertSame( $post_id, wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) ) );
		$this->assertSame( 'publish', EventDatesTable::get( $post_id )->post_status );
	}

	public function test_trash_untrash_and_permanent_delete_follow_canonical_lifecycle(): void {
		$post_id = $this->create_event( 'publish' );

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $post_id ) );
		$this->assertSame( 'trash', EventDatesTable::get( $post_id )->post_status );

		$this->assertInstanceOf( \WP_Post::class, wp_untrash_post( $post_id ) );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 'draft', EventDatesTable::get( $post_id )->post_status );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $post_id, true ) );
		$this->assertNull( EventDatesTable::get( $post_id ) );
	}

	public function test_upsert_uses_canonical_status_and_rejects_missing_posts(): void {
		$post_id = $this->create_event( 'pending' );

		$this->assertTrue( EventDatesTable::upsert( $post_id, '2031-01-01 20:00:00', null, 'publish' ) );
		$this->assertSame( 'pending', EventDatesTable::get( $post_id )->post_status );

		$this->assertFalse( EventDatesTable::upsert( 999999999, '2031-01-01 20:00:00' ) );
	}

	public function test_repair_is_bounded_resumable_and_handles_orphans_idempotently(): void {
		global $wpdb;

		$first  = $this->create_event( 'draft' );
		$second = $this->create_event( 'private' );
		$this->force_index_status( $first, 'publish' );
		$this->force_index_status( $second, 'publish' );

		$orphan_id = $second + 100000;
		$wpdb->insert(
			EventDatesTable::table_name(),
			array(
				'post_id'        => $orphan_id,
				'start_datetime' => '2031-01-02 20:00:00',
				'end_datetime'   => null,
				'post_status'    => 'publish',
			),
			array( '%d', '%s', null, '%s' )
		);

		$batch = EventDatesTable::find_status_drift_batch( $first - 1, 1 );
		$this->assertCount( 1, $batch['rows'] );
		$this->assertTrue( $batch['has_more'] );
		$this->assertSame( $first, $batch['next_cursor'] );
		$this->assertSame( 'publish', EventDatesTable::get( $first )->post_status, 'Audit must not write.' );

		$this->assertSame( 'status_updated', EventDatesTable::repair_status_drift_row( $first ) );
		$this->assertSame( 'draft', EventDatesTable::get( $first )->post_status );
		$this->assertSame( 'unchanged', EventDatesTable::repair_status_drift_row( $first ) );

		$this->assertSame( 'orphan_deleted', EventDatesTable::repair_status_drift_row( $orphan_id ) );
		$this->assertSame( 'unchanged', EventDatesTable::repair_status_drift_row( $orphan_id ) );

		$resumed = EventDatesTable::find_status_drift_batch( $batch['next_cursor'], 10 );
		$this->assertSame( array( $second ), array_column( $resumed['rows'], 'post_id' ) );
	}

	public function test_cli_defaults_to_dry_run_and_requires_apply(): void {
		$post_id = $this->create_event( 'draft' );
		$this->force_index_status( $post_id, 'publish' );
		$command = new CheckEventDateStatusCommand();

		ob_start();
		$command( array(), array( 'after-id' => $post_id - 1, 'batch-size' => 1 ) );
		ob_end_clean();
		$this->assertSame( 'publish', EventDatesTable::get( $post_id )->post_status );

		ob_start();
		$command( array(), array( 'after-id' => $post_id - 1, 'batch-size' => 1, 'apply' => true ) );
		ob_end_clean();
		$this->assertSame( 'draft', EventDatesTable::get( $post_id )->post_status );
	}
}
