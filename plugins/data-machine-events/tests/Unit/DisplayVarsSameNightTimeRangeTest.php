<?php
/**
 * DisplayVars same-night time-range tests (issue #833).
 *
 * A single-night event whose end lands on the following calendar day before
 * the cutoff renders as a full time range ("9:00 PM - 2:00 AM"); genuinely
 * multi-day events keep the start time only and carry the "through <date>"
 * badge.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.62.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Blocks\Calendar\Display\DisplayVars;
use WP_UnitTestCase;

class DisplayVarsSameNightTimeRangeTest extends WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_next_day_cutoff' );
		parent::tearDown();
	}

	/**
	 * Issue #833 core case: 21:00 → 02:00 next day prints the full range
	 * instead of suppressing everything after midnight.
	 */
	public function test_after_midnight_end_renders_full_range() {
		$vars = DisplayVars::build(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '21:00:00',
				'endDate'   => '2026-07-11',
				'endTime'   => '02:00:00',
			)
		);

		$this->assertSame( '9:00 PM - 2:00 AM', $vars['formatted_time_display'] );
		$this->assertSame( '', $vars['multi_day_label'], 'A same-night event must not carry a "through" badge.' );
		$this->assertFalse( $vars['is_multi_day'] );
	}

	/**
	 * Genuinely multi-day events keep the start-time-only display plus the
	 * "through <date>" badge.
	 */
	public function test_multi_day_event_keeps_start_only_and_through_label() {
		$vars = DisplayVars::build(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '19:30:00',
				'endDate'   => '2026-07-13',
				'endTime'   => '11:30:00',
			),
			array(
				'is_multi_day'   => true,
				'is_continuation'=> false,
			)
		);

		$this->assertSame( '7:30 PM', $vars['formatted_time_display'] );
		$this->assertSame( 'through Jul 13', $vars['multi_day_label'] );
		$this->assertTrue( $vars['is_multi_day'] );
	}

	/**
	 * Continuation occurrences read "Ongoing · ends <date>".
	 */
	public function test_continuation_renders_ongoing_label() {
		$vars = DisplayVars::build(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '19:30:00',
				'endDate'   => '2026-07-13',
				'endTime'   => '11:30:00',
			),
			array(
				'is_multi_day'   => true,
				'is_continuation'=> true,
			)
		);

		$this->assertSame( 'Ongoing · ends Jul 13', $vars['formatted_time_display'] );
		$this->assertSame( '', $vars['multi_day_label'] );
	}

	/**
	 * An end on the next day after the cutoff (11:00 AM) is a real multi-day
	 * event: start time only, no cross-midnight range.
	 */
	public function test_end_after_cutoff_next_day_renders_start_only() {
		$vars = DisplayVars::build(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '21:00:00',
				'endDate'   => '2026-07-11',
				'endTime'   => '11:00:00',
			)
		);

		$this->assertSame( '9:00 PM', $vars['formatted_time_display'] );
	}

	/**
	 * The filterable cutoff also drives the time-string: filtering the
	 * cutoff to 06:00 turns a 21:00 → 05:30 end into a same-night range.
	 */
	public function test_cutoff_filter_extends_same_night_range() {
		add_filter(
			'data_machine_events_next_day_cutoff',
			static function () {
				return '06:00';
			}
		);

		$vars = DisplayVars::build(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '21:00:00',
				'endDate'   => '2026-07-11',
				'endTime'   => '05:30:00',
			)
		);

		$this->assertSame( '9:00 PM - 5:30 AM', $vars['formatted_time_display'] );
	}

	/**
	 * Multi-day-classified occurrences keep the start-time-only shape even
	 * when their end is same-night — this pins the portable
	 * calendar-occurrence contract bytes (the seeded fixture is exactly
	 * this shape) and mirrors the multi-day branch's start-only rule.
	 */
	public function test_multi_day_context_with_same_night_end_keeps_start_only() {
		$vars = DisplayVars::build(
			array(
				'startDate'      => '2099-07-21',
				'startTime'      => '19:30:00',
				'endDate'        => '2099-07-22',
				'endTime'        => '00:30:00',
				'venueTimezone'  => 'America/New_York',
			),
			array(
				'is_multi_day'   => true,
				'is_continuation'=> false,
			)
		);

		$this->assertSame( '7:30 PM', $vars['formatted_time_display'] );
	}

	/**
	 * A same-day range still renders (regression guard for the pre-#833
	 * same-day behavior).
	 */
	public function test_same_day_range_unchanged() {
		$vars = DisplayVars::build(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '22:00:00',
				'endDate'   => '2026-07-10',
				'endTime'   => '23:30:00',
			)
		);

		$this->assertSame( '10:00 - 11:30 PM', $vars['formatted_time_display'] );
	}
}
