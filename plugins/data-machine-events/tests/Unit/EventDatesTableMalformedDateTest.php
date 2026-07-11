<?php
/**
 * EventDatesTable malformed-date guardrail tests.
 *
 * Verifies that EventDatesTable::upsert() rejects malformed/placeholder
 * date strings instead of letting MySQL coerce them to 0000-00-00.
 * Covers issue #395.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.47.4
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;

class EventDatesTableMalformedDateTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	private function create_event(): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Malformed Date Test ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'draft',
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	public function test_upsert_accepts_valid_datetime(): void {
		$post_id  = $this->create_event();
		$datetime = '2026-07-15 19:30:00';

		$result = EventDatesTable::upsert( $post_id, $datetime );

		$this->assertTrue( $result );

		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertEquals( $datetime, $stored->start_datetime );
	}

	public function test_upsert_rejects_placeholder_day(): void {
		$post_id  = $this->create_event();
		$datetime = '2026-07-?? 00:00:00';

		$result = EventDatesTable::upsert( $post_id, $datetime );

		$this->assertFalse( $result );
		$this->assertNull( EventDatesTable::get( $post_id ) );
	}

	public function test_upsert_rejects_placeholder_month_and_day(): void {
		$post_id  = $this->create_event();
		$datetime = '2026-??-?? 00:00:00';

		$result = EventDatesTable::upsert( $post_id, $datetime );

		$this->assertFalse( $result );
		$this->assertNull( EventDatesTable::get( $post_id ) );
	}

	public function test_upsert_rejects_mysql_zero_date(): void {
		$post_id  = $this->create_event();
		$datetime = '0000-00-00 00:00:00';

		$result = EventDatesTable::upsert( $post_id, $datetime );

		$this->assertFalse( $result );
		$this->assertNull( EventDatesTable::get( $post_id ) );
	}

	public function test_upsert_rejects_impossible_calendar_date(): void {
		$post_id  = $this->create_event();
		$datetime = '2026-02-30 00:00:00';

		$result = EventDatesTable::upsert( $post_id, $datetime );

		$this->assertFalse( $result );
		$this->assertNull( EventDatesTable::get( $post_id ) );
	}

	public function test_upsert_rejects_malformed_end_datetime(): void {
		$post_id      = $this->create_event();
		$start        = '2026-07-15 19:30:00';
		$bad_end      = '2026-07-?? 23:59:59';

		$result = EventDatesTable::upsert( $post_id, $start, $bad_end );

		$this->assertFalse( $result );
		$this->assertNull( EventDatesTable::get( $post_id ) );
	}

	public function test_upsert_accepts_null_end_datetime(): void {
		$post_id = $this->create_event();
		$start   = '2026-07-15 19:30:00';

		$result = EventDatesTable::upsert( $post_id, $start, null );

		$this->assertTrue( $result );

		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertEquals( $start, $stored->start_datetime );
	}

	public function test_find_zero_date_rows_finds_existing_zero_rows(): void {
		global $wpdb;

		$post_id = $this->create_event();
		$table   = EventDatesTable::table_name();

		// Bypass the guardrail to simulate a pre-existing zero-date row
		// (the state that existed before this fix was deployed).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$table,
			array(
				'post_id'        => $post_id,
				'start_datetime' => '0000-00-00 00:00:00',
				'end_datetime'   => '0000-00-00 00:00:00',
				'post_status'    => 'draft',
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$rows = EventDatesTable::find_zero_date_rows();

		$this->assertNotEmpty( $rows );

		$found = false;
		foreach ( $rows as $row ) {
			if ( $row['post_id'] === $post_id ) {
				$found = true;
				$this->assertEquals( '0000-00-00 00:00:00', $row['start_datetime'] );
				break;
			}
		}

		$this->assertTrue( $found, 'find_zero_date_rows must surface the zero-date row' );
	}

	public function test_find_zero_date_rows_empty_when_all_valid(): void {
		$post_id = $this->create_event();
		EventDatesTable::upsert( $post_id, '2026-07-15 19:30:00' );

		$rows = EventDatesTable::find_zero_date_rows();

		// No zero-date rows should exist after a valid upsert.
		foreach ( $rows as $row ) {
			$this->assertNotEquals( $post_id, $row['post_id'] );
		}
	}
}
