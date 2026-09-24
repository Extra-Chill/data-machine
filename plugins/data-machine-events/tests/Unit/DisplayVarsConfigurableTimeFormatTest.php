<?php
/**
 * DisplayVars::format_time_range() configurable $time_format tests (issue #860).
 *
 * The single-event page needed the same range-formatting logic the
 * calendar card already uses (same-night cutoff, sentinel-end-time, and
 * "identical start/end means no real end" handling), but rendered with the
 * site's configured `time_format` option instead of the calendar's fixed
 * 'g:i A'. These tests pin the new optional `$time_format` parameter and
 * confirm the calendar's own default behavior (covered by
 * DisplayVarsSameNightTimeRangeTest) is unchanged when the parameter is
 * omitted.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Blocks\Calendar\Display\DisplayVars;
use DataMachineEvents\Core\DateTimeParser;
use DateTimeZone;
use WP_UnitTestCase;

class DisplayVarsConfigurableTimeFormatTest extends WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_next_day_cutoff' );
		parent::tearDown();
	}

	/**
	 * A same-day range in a custom lowercase 12-hour format still collapses
	 * the shared meridiem, exactly like the calendar's default 'g:i A' case
	 * ("10:00 - 11:30 PM"), but respecting the caller's format and case.
	 */
	public function test_same_day_range_with_custom_lowercase_format(): void {
		$start = DateTimeParser::safeCreate( '2026-10-21 18:30:00', new DateTimeZone( 'America/New_York' ) );

		$result = DisplayVars::format_time_range( $start, '2026-10-21', '21:00:00', new DateTimeZone( 'America/New_York' ), true, 'g:i a' );

		$this->assertSame( '6:30 - 9:00 pm', $result );
	}

	/**
	 * A 24-hour format has no separate meridiem token, so nothing is
	 * collapsed and both sides print in full — the collapsing logic keys
	 * off the *formatted output* sharing a trailing token, not off an
	 * assumption that the format is 12-hour.
	 */
	public function test_24_hour_format_prints_both_sides_in_full(): void {
		$start = DateTimeParser::safeCreate( '2026-10-21 18:30:00', new DateTimeZone( 'America/New_York' ) );

		$result = DisplayVars::format_time_range( $start, '2026-10-21', '21:00:00', new DateTimeZone( 'America/New_York' ), true, 'H:i' );

		$this->assertSame( '18:30 - 21:00', $result );
	}

	/**
	 * No end date/time stored — the default and overwhelmingly common
	 * shape for scraped events — renders exactly the start time alone, in
	 * the caller's format. Must never regress into inventing an end.
	 */
	public function test_no_end_stored_renders_start_time_only_in_custom_format(): void {
		$start = DateTimeParser::safeCreate( '2026-10-21 18:30:00', new DateTimeZone( 'America/New_York' ) );

		$result = DisplayVars::format_time_range( $start, '', '', new DateTimeZone( 'America/New_York' ), true, 'g:i a' );

		$this->assertSame( '6:30 pm', $result );
	}

	/**
	 * An end time identical to the start time is not a real duration —
	 * render as start-only, not "6:30 - 6:30 pm".
	 */
	public function test_end_equal_to_start_renders_start_only(): void {
		$start = DateTimeParser::safeCreate( '2026-10-21 18:30:00', new DateTimeZone( 'America/New_York' ) );

		$result = DisplayVars::format_time_range( $start, '2026-10-21', '18:30:00', new DateTimeZone( 'America/New_York' ), true, 'g:i a' );

		$this->assertSame( '6:30 pm', $result );
	}

	/**
	 * The 23:59:59 sentinel (event-dates-sync.php's stand-in for "endDate
	 * given, no real endTime") must never leak into the custom-format
	 * rendering either.
	 */
	public function test_sentinel_end_time_ignored_in_custom_format(): void {
		$start = DateTimeParser::safeCreate( '2026-10-21 18:30:00', new DateTimeZone( 'America/New_York' ) );

		$result = DisplayVars::format_time_range( $start, '2026-10-21', '23:59:59', new DateTimeZone( 'America/New_York' ), true, 'g:i a' );

		$this->assertSame( '6:30 pm', $result );
	}

	/**
	 * A genuinely multi-day span (not absorbed into a same-night range)
	 * keeps the start-time-only return regardless of format — the caller
	 * is responsible for surfacing the end date separately (render.php's
	 * $multi_day_ends, driven by MultiDayResolver::is_multi_day()).
	 */
	public function test_genuine_multi_day_span_renders_start_only_in_custom_format(): void {
		$start = DateTimeParser::safeCreate( '2026-07-10 19:30:00', new DateTimeZone( 'America/New_York' ) );

		$result = DisplayVars::format_time_range( $start, '2026-07-13', '23:00:00', new DateTimeZone( 'America/New_York' ), true, 'g:i a' );

		$this->assertSame( '7:30 pm', $result );
	}

	/**
	 * A same-night cross-midnight end (9 PM -> 2 AM, #833) still renders a
	 * full range in a custom format when the two sides land in different
	 * meridiems, matching the calendar's own default-format behavior.
	 */
	public function test_same_night_cross_midnight_range_in_custom_format(): void {
		$start = DateTimeParser::safeCreate( '2026-07-10 21:00:00', new DateTimeZone( 'America/New_York' ) );

		$result = DisplayVars::format_time_range( $start, '2026-07-11', '02:00:00', new DateTimeZone( 'America/New_York' ), true, 'g:i a' );

		$this->assertSame( '9:00 pm - 2:00 am', $result );
	}

	/**
	 * Omitting $time_format keeps the calendar card's existing fixed
	 * 'g:i A' output — the new parameter is purely additive.
	 */
	public function test_default_format_unchanged_when_parameter_omitted(): void {
		$start = DateTimeParser::safeCreate( '2026-10-21 22:00:00', new DateTimeZone( 'America/New_York' ) );

		$result = DisplayVars::format_time_range( $start, '2026-10-21', '23:30:00', new DateTimeZone( 'America/New_York' ) );

		$this->assertSame( '10:00 - 11:30 PM', $result );
	}
}
