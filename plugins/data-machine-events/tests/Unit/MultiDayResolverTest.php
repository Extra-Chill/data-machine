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
}
