<?php
/**
 * MultiDayResolver Tests
 *
 * Guards against malformed / placeholder dates (e.g. "2026-07-??") that
 * previously reached `new DateTime()` and threw a fatal
 * DateMalformedStringException.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.44.1
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DateTimeZone;
use DataMachineEvents\Blocks\Calendar\Grouping\MultiDayResolver;

class MultiDayResolverTest extends WP_UnitTestCase {

	/**
	 * Regression: a placeholder end date ("2026-07-??") must not throw.
	 *
	 * Previously `is_multi_day()` passed the raw string straight into
	 * `new DateTime()`, which throws DateMalformedStringException on PHP 8.3+.
	 */
	public function test_is_multi_day_handles_placeholder_end_date_without_throwing() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-01',
				'endDate'   => '2026-07-??',
			)
		);

		$this->assertFalse( $result, 'Placeholder dates cannot form a range and must be treated as single-day.' );
	}

	public function test_is_multi_day_handles_placeholder_start_date_without_throwing() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-??-??',
				'endDate'   => '2026-07-05',
			)
		);

		$this->assertFalse( $result );
	}

	public function test_is_multi_day_returns_false_for_invalid_calendar_dates() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-02-30',
				'endDate'   => '2026-03-05',
			)
		);

		$this->assertFalse( $result );
	}

	public function test_is_multi_day_true_for_genuine_multi_day_span() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-01',
				'endDate'   => '2026-07-05',
			)
		);

		$this->assertTrue( $result );
	}

	public function test_is_multi_day_false_for_same_day() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-01',
				'endDate'   => '2026-07-01',
			)
		);

		$this->assertFalse( $result );
	}

	public function test_get_date_range_returns_empty_for_malformed_dates() {
		$tz    = new DateTimeZone( 'America/New_York' );
		$range = MultiDayResolver::get_date_range( '2026-07-??', '2026-07-??', $tz );

		$this->assertSame( array(), $range );
	}

	public function test_get_date_range_returns_single_start_when_end_is_malformed() {
		$tz    = new DateTimeZone( 'America/New_York' );
		$range = MultiDayResolver::get_date_range( '2026-07-01', '2026-07-??', $tz );

		$this->assertSame( array( '2026-07-01' ), $range );
	}

	public function test_get_date_range_expands_valid_span() {
		$tz    = new DateTimeZone( 'America/New_York' );
		$range = MultiDayResolver::get_date_range( '2026-07-01', '2026-07-03', $tz );

		$this->assertSame(
			array( '2026-07-01', '2026-07-02', '2026-07-03' ),
			$range
		);
	}

	/**
	 * Issue #833: the Campbell & Sons shape — a 9 PM bar show ending 2 AM
	 * the next morning — must classify as a single-day event, not multi-day.
	 */
	public function test_is_multi_day_false_for_after_midnight_end_before_cutoff() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '21:00:00',
				'endDate'   => '2026-07-11',
				'endTime'   => '02:00:00',
			)
		);

		$this->assertFalse( $result, 'A 21:00 → 02:00 next-day end is one night, not a multi-day event.' );
	}

	/**
	 * Ends at or after the cutoff (default 05:00, strict comparison) are
	 * genuinely multi-day and keep the existing treatment.
	 */
	public function test_is_multi_day_true_when_end_at_or_after_cutoff() {
		$at_cutoff = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '21:00:00',
				'endDate'   => '2026-07-11',
				'endTime'   => '05:00:00',
			)
		);
		$this->assertTrue( $at_cutoff, 'An end exactly at the cutoff is not same-night (strict comparison).' );

		$after_cutoff = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '21:00:00',
				'endDate'   => '2026-07-11',
				'endTime'   => '06:00:00',
			)
		);
		$this->assertTrue( $after_cutoff, 'A 21:00 → 06:00 end is a multi-day event at the default cutoff.' );
	}

	public function test_is_multi_day_true_for_week_long_span() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '19:30:00',
				'endDate'   => '2026-07-17',
				'endTime'   => '11:30:00',
			)
		);

		$this->assertTrue( $result );
	}

	public function test_is_multi_day_false_for_same_day_evening() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '10:00:00',
				'endDate'   => '2026-07-10',
				'endTime'   => '22:00:00',
			)
		);

		$this->assertFalse( $result );
	}

	public function test_is_multi_day_true_when_end_time_unknown_on_next_day() {
		$result = MultiDayResolver::is_multi_day(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '21:00:00',
				'endDate'   => '2026-07-11',
			)
		);

		$this->assertTrue( $result, 'An unknown end time cannot prove same-night; keep multi-day.' );
	}

	/**
	 * The same-night cutoff is filterable — pushing it to 06:00 pulls a
	 * 05:30 end into single-day territory that the 05:00 default calls
	 * multi-day.
	 */
	public function test_next_day_cutoff_filter_is_respected() {
		$event = array(
			'startDate' => '2026-07-10',
			'startTime' => '21:00:00',
			'endDate'   => '2026-07-11',
			'endTime'   => '05:30:00',
		);

		$this->assertTrue( MultiDayResolver::is_multi_day( $event ), '05:30 end is multi-day at the 05:00 default.' );

		add_filter(
			'data_machine_events_next_day_cutoff',
			static function () {
				return '06:00';
			}
		);

		$this->assertFalse( MultiDayResolver::is_multi_day( $event ), '05:30 end is same-night with the cutoff filtered to 06:00.' );

		remove_all_filters( 'data_machine_events_next_day_cutoff' );

		$this->assertTrue( MultiDayResolver::is_multi_day( $event ), 'Filter removal restores the default classification.' );
	}

	public function test_is_same_night_end_accepts_late_night_end() {
		$this->assertTrue( MultiDayResolver::is_same_night_end( '2026-07-10', '2026-07-11', '02:00:00' ) );
		$this->assertTrue( MultiDayResolver::is_same_night_end( '2026-07-10', '2026-07-11', '04:59' ) );
	}

	public function test_is_same_night_end_rejects_other_shapes() {
		$this->assertFalse( MultiDayResolver::is_same_night_end( '2026-07-10', '2026-07-11', '05:00:00' ), 'End exactly at the cutoff is not same-night.' );
		$this->assertFalse( MultiDayResolver::is_same_night_end( '2026-07-10', '2026-07-11', '11:00:00' ) );
		$this->assertFalse( MultiDayResolver::is_same_night_end( '2026-07-10', '2026-07-11', '' ), 'Unknown end time is not same-night.' );
		$this->assertFalse( MultiDayResolver::is_same_night_end( '2026-07-10', '2026-07-12', '02:00:00' ), 'End two days later is not same-night.' );
		$this->assertFalse( MultiDayResolver::is_same_night_end( '2026-07-10', '2026-07-10', '23:00:00' ), 'Same-day ends are not this helper’s concern.' );
		$this->assertFalse( MultiDayResolver::is_same_night_end( '2026-07-??', '2026-07-11', '02:00:00' ), 'Placeholder dates are never same-night.' );
	}
}
