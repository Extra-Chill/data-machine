<?php
/**
 * EventDatesTable occurrence-span guard tests.
 *
 * Verifies that EventDatesTable::upsert() strips fabricated series-range
 * end datetimes (issue #199) instead of persisting them, while keeping the
 * row's good start data.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.64.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;

class EventDatesTableSpanGuardTest extends WP_UnitTestCase {

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

	private function create_event(): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Span Guard Test ' . uniqid(),
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	public function test_upsert_strips_series_range_end_but_keeps_row(): void {
		$post_id = $this->create_event();
		$start   = '2026-09-10 20:00:00';
		// The Annapolis leak from issue #199: 281 days.
		$end = '2027-06-18 21:00:00';

		$this->assertTrue( EventDatesTable::upsert( $post_id, $start, $end ) );

		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertSame( $start, $stored->start_datetime );
		$this->assertNull( $stored->end_datetime );
	}

	public function test_upsert_keeps_plausible_end(): void {
		$post_id = $this->create_event();
		$start   = '2026-09-10 20:00:00';
		$end     = '2026-09-13 23:00:00';

		$this->assertTrue( EventDatesTable::upsert( $post_id, $start, $end ) );

		$stored = EventDatesTable::get( $post_id );
		$this->assertSame( $end, $stored->end_datetime );
	}

	public function test_filter_can_raise_threshold_to_preserve_long_runs(): void {
		add_filter( 'data_machine_events_max_event_span_hours', static fn() => 8760 );

		$post_id = $this->create_event();
		$start   = '2026-09-10 20:00:00';
		$end     = '2027-06-18 21:00:00';

		$this->assertTrue( EventDatesTable::upsert( $post_id, $start, $end ) );

		$stored = EventDatesTable::get( $post_id );
		$this->assertSame( $end, $stored->end_datetime );
	}

	public function test_stripped_end_can_be_cleared_by_a_later_upsert(): void {
		$post_id = $this->create_event();

		EventDatesTable::upsert( $post_id, '2026-09-10 20:00:00', '2027-06-18 21:00:00' );
		$this->assertNull( EventDatesTable::get( $post_id )->end_datetime );

		EventDatesTable::upsert( $post_id, '2026-09-10 20:00:00', '2026-09-10 23:00:00' );
		$this->assertSame( '2026-09-10 23:00:00', EventDatesTable::get( $post_id )->end_datetime );
	}

	public function test_fires_action_when_end_is_stripped(): void {
		$post_id  = $this->create_event();
		$captured = array();

		add_action(
			'datamachine_event_dates_end_span_stripped',
			static function ( $stripped_post_id, $start, $end ) use ( &$captured ) {
				$captured[] = array( $stripped_post_id, $start, $end );
			},
			10,
			3
		);

		EventDatesTable::upsert( $post_id, '2026-09-10 20:00:00', '2026-12-30 22:00:00' );

		remove_all_actions( 'datamachine_event_dates_end_span_stripped' );

		$this->assertCount( 1, $captured );
		$this->assertSame( $post_id, $captured[0][0] );
		$this->assertSame( '2026-12-30 22:00:00', $captured[0][2] );
	}
}
