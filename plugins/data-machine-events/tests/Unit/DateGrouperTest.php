<?php
/**
 * DateGrouper same-night classification tests (issue #833).
 *
 * Guards the grouping layer against re-expanding single-night events into
 * multi-day continuations: a 9 PM → 2 AM bar show must appear once, under
 * its start date, without a "through" badge or a ghost "Ongoing" entry on
 * the following day.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.62.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper;
use DataMachineEvents\Core\DateTimeParser;
use WP_UnitTestCase;

class DateGrouperTest extends WP_UnitTestCase {

	/**
	 * Build one paged-event item in the shape group_events_by_date() expects.
	 */
	private function make_item( string $start_date, string $start_time, string $end_date = '', string $end_time = '' ): array {
		$event_data = array(
			'startDate' => $start_date,
			'startTime' => $start_time,
		);

		if ( '' !== $end_date ) {
			$event_data['endDate'] = $end_date;
		}

		if ( '' !== $end_time ) {
			$event_data['endTime'] = $end_time;
		}

		return array(
			'post'       => self::factory()->post->create_and_get(),
			'datetime'   => DateTimeParser::safeCreate( $start_date . ' ' . $start_time, wp_timezone() ),
			'event_data' => $event_data,
		);
	}

	/**
	 * Issue #833 core case: 21:00 → 02:00 next day is one date group on the
	 * start date only, with no multi-day / continuation flags.
	 */
	public function test_after_midnight_end_is_listed_once_under_start_date() {
		$groups = DateGrouper::group_events_by_date(
			array( $this->make_item( '2026-07-10', '21:00:00', '2026-07-11', '02:00:00' ) ),
			true
		);

		$this->assertSame( array( '2026-07-10' ), array_keys( $groups ), 'The event must appear under its start date only.' );

		$event = $groups['2026-07-10']['events'][0];
		$this->assertFalse( $event['display_context']['is_multi_day'] );
		$this->assertFalse( $event['display_context']['is_continuation'] );
		$this->assertTrue( $event['display_context']['is_start_day'] );
		$this->assertSame( '2026-07-10', $event['display_context']['display_date'] );
	}

	/**
	 * Ends at or after the cutoff keep genuine multi-day expansion: the
	 * event spans its start date plus a continuation day. (show_past=true
	 * reverses group order, so assert by key lookup, not key sequence.)
	 */
	public function test_end_after_cutoff_still_expands_multi_day() {
		$groups = DateGrouper::group_events_by_date(
			array( $this->make_item( '2026-07-10', '21:00:00', '2026-07-11', '06:00:00' ) ),
			true
		);

		$this->assertCount( 2, $groups );
		$this->assertArrayHasKey( '2026-07-10', $groups );
		$this->assertArrayHasKey( '2026-07-11', $groups );

		$start = $groups['2026-07-10']['events'][0];
		$this->assertTrue( $start['display_context']['is_multi_day'] );
		$this->assertFalse( $start['display_context']['is_continuation'] );

		$continuation = $groups['2026-07-11']['events'][0];
		$this->assertTrue( $continuation['display_context']['is_multi_day'] );
		$this->assertTrue( $continuation['display_context']['is_continuation'] );
	}

	/**
	 * A week-long span (the 19:30 → +7 days outlier shape) expands across
	 * every calendar day it covers.
	 */
	public function test_week_long_span_expands_across_all_days() {
		$groups = DateGrouper::group_events_by_date(
			array( $this->make_item( '2026-07-10', '19:30:00', '2026-07-17', '11:30:00' ) ),
			true
		);

		$this->assertCount( 8, $groups, 'Jul 10 → Jul 17 inclusive spans 8 calendar days.' );

		$start = $groups['2026-07-10']['events'][0];
		$this->assertTrue( $start['display_context']['is_multi_day'] );
		$this->assertFalse( $start['display_context']['is_continuation'] );

		$last = $groups['2026-07-17']['events'][0];
		$this->assertTrue( $last['display_context']['is_continuation'] );
		$this->assertSame( 8, $last['display_context']['day_number'] );
	}

	/**
	 * A same-day 10:00 → 22:00 event is a single plain day group.
	 */
	public function test_same_day_evening_is_single_group() {
		$groups = DateGrouper::group_events_by_date(
			array( $this->make_item( '2026-07-10', '10:00:00', '2026-07-10', '22:00:00' ) ),
			true
		);

		$this->assertSame( array( '2026-07-10' ), array_keys( $groups ) );

		$event = $groups['2026-07-10']['events'][0];
		$this->assertFalse( $event['display_context']['is_multi_day'] );
		$this->assertFalse( $event['display_context']['is_continuation'] );
	}

	/**
	 * A show starting after midnight (1 AM → 2 AM on Jul 11) belongs to the
	 * night of Jul 10 — one group, shifted display date, no continuation.
	 */
	public function test_after_midnight_start_buckets_to_previous_night_without_continuation() {
		$groups = DateGrouper::group_events_by_date(
			array( $this->make_item( '2026-07-11', '01:00:00', '2026-07-11', '02:00:00' ) ),
			true
		);

		$this->assertSame( array( '2026-07-10' ), array_keys( $groups ), 'A 1 AM start displays under the previous calendar day.' );

		$event = $groups['2026-07-10']['events'][0];
		$this->assertFalse( $event['display_context']['is_multi_day'] );
		$this->assertFalse( $event['display_context']['is_continuation'] );
		$this->assertTrue( $event['display_context']['is_start_day'] );
	}
}
