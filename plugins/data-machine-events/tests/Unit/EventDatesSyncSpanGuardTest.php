<?php
/**
 * Event-dates-sync occurrence-span guard tests.
 *
 * Verifies that the save_post derivation (data_machine_events_sync_datetime_meta)
 * strips fabricated series-range end datetimes (issue #199) before they
 * reach the datamachine_event_dates table, and keeps genuine occurrence
 * ends (same-night, overnight, short runs).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.64.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use WP_UnitTestCase;

class EventDatesSyncSpanGuardTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_max_event_span_hours' );
		parent::tearDown();
	}

	private function insert_event_with_block( array $attrs ): int {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Sync Span Guard ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode( $attrs ) . ' /-->',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	public function test_series_range_end_is_stripped_from_the_dates_row(): void {
		$post_id = $this->insert_event_with_block(
			array(
				'startDate' => '2026-09-11',
				'startTime' => '16:00',
				'endDate'   => '2027-06-18',
				'endTime'   => '21:00',
				'venue'     => 'Capital Hotel',
			)
		);

		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertSame( '2026-09-11 16:00:00', $stored->start_datetime );
		$this->assertNull( $stored->end_datetime );
	}

	public function test_missing_end_time_defaults_would_be_stripped_across_series_range(): void {
		// endDate far out with no endTime: sync derives 23:59:59 — still a
		// fabricated series range and must not reach the table.
		$post_id = $this->insert_event_with_block(
			array(
				'startDate' => '2026-09-12',
				'startTime' => '19:30',
				'endDate'   => '2027-06-19',
			)
		);

		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertNull( $stored->end_datetime );
	}

	public function test_same_night_end_is_preserved(): void {
		// Time-only end (no endDate): sync derives a same-day end.
		$post_id = $this->insert_event_with_block(
			array(
				'startDate' => '2026-09-12',
				'startTime' => '19:30',
				'endTime'   => '23:00',
			)
		);

		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertSame( '2026-09-12 23:00:00', $stored->end_datetime );

		// Explicit same-day endDate + endTime: identical result.
		$post_id_two = $this->insert_event_with_block(
			array(
				'startDate' => '2026-09-12',
				'startTime' => '19:30',
				'endDate'   => '2026-09-12',
				'endTime'   => '23:00',
			)
		);

		$stored_two = EventDatesTable::get( $post_id_two );
		$this->assertSame( '2026-09-12 23:00:00', $stored_two->end_datetime );
	}

	public function test_short_multi_day_run_end_is_preserved(): void {
		$post_id = $this->insert_event_with_block(
			array(
				'startDate' => '2026-12-03',
				'startTime' => '19:00',
				'endDate'   => '2026-12-05',
				'endTime'   => '23:00',
			)
		);

		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertSame( '2026-12-05 23:00:00', $stored->end_datetime );
	}

	public function test_raised_threshold_preserves_series_range_end(): void {
		add_filter( 'data_machine_events_max_event_span_hours', static fn() => 8760 );

		$post_id = $this->insert_event_with_block(
			array(
				'startDate' => '2026-09-11',
				'startTime' => '16:00',
				'endDate'   => '2027-06-18',
				'endTime'   => '21:00',
			)
		);

		$stored = EventDatesTable::get( $post_id );
		$this->assertSame( '2027-06-18 21:00:00', $stored->end_datetime );
	}
}
