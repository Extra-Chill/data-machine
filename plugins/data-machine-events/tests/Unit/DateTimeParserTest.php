<?php
/**
 * DateTimeParser Tests
 *
 * Tests centralized datetime parsing with timezone awareness.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DateTime;
use DateTimeZone;
use DataMachineEvents\Core\DateTimeParser;

class DateTimeParserTest extends WP_UnitTestCase {

	public function test_parse_utc_converts_to_target_timezone() {
		$result = DateTimeParser::parseUtc( '2026-01-15T18:00:00Z', 'America/Chicago' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '12:00', $result['time'] );
		$this->assertEquals( 'America/Chicago', $result['timezone'] );
	}

	public function test_parse_utc_handles_different_timezones() {
		$utc_datetime = '2026-06-15T20:00:00Z';

		$chicago = DateTimeParser::parseUtc( $utc_datetime, 'America/Chicago' );
		$this->assertEquals( '15:00', $chicago['time'] );

		$denver = DateTimeParser::parseUtc( $utc_datetime, 'America/Denver' );
		$this->assertEquals( '14:00', $denver['time'] );

		$la = DateTimeParser::parseUtc( $utc_datetime, 'America/Los_Angeles' );
		$this->assertEquals( '13:00', $la['time'] );
	}

	public function test_parse_utc_falls_back_to_site_timezone_for_invalid_timezone() {
		// Defense-in-depth fix from #254: invalid timezone must NOT silently destroy
		// the date — it must fall back to the site timezone so a missing venue
		// timezone cannot cascade into off-by-one duplicates.
		$result = DateTimeParser::parseUtc( '2026-01-15T18:00:00Z', 'Invalid/Timezone' );

		$this->assertNotEmpty( $result['date'], 'parseUtc must not return an empty date when timezone is invalid' );
		$this->assertNotEmpty( $result['time'] );
		$this->assertNotEmpty( $result['timezone'], 'parseUtc must report the fallback timezone it used' );
		$this->assertTrue( DateTimeParser::isValidTimezone( $result['timezone'] ) );
	}

	public function test_parse_utc_falls_back_to_site_timezone_for_empty_timezone() {
		// Royal American repro: Squarespace shows at 9pm Eastern land on the next
		// calendar day in UTC. With an empty timezone, the old code returned empty
		// and the caller picked a fragile fallback path. With the fix, parseUtc
		// falls back to the WP site timezone and still produces a stable date.
		$result = DateTimeParser::parseUtc( '2026-05-16T01:00:00Z', '' );

		$this->assertNotEmpty( $result['date'], 'parseUtc must not silently drop the date when timezone is empty' );
		$this->assertNotEmpty( $result['timezone'] );
		$this->assertTrue( DateTimeParser::isValidTimezone( $result['timezone'] ) );
	}

	public function test_parse_utc_returns_empty_for_empty_datetime() {
		$result = DateTimeParser::parseUtc( '', 'America/Chicago' );

		$this->assertEquals( '', $result['date'] );
	}

	public function test_parse_utc_valid_timezone_path_unchanged() {
		// Regression guard: explicit valid timezone must continue to produce the
		// exact same output it did before the fallback was introduced.
		$result = DateTimeParser::parseUtc( '2026-05-16T01:00:00Z', 'America/New_York' );

		$this->assertEquals( '2026-05-15', $result['date'] );
		$this->assertEquals( '21:00', $result['time'] );
		$this->assertEquals( 'America/New_York', $result['timezone'] );
	}

	public function test_parse_local_preserves_datetime() {
		$result = DateTimeParser::parseLocal( '2026-01-15', '19:30', 'America/Denver' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '19:30', $result['time'] );
		$this->assertEquals( 'America/Denver', $result['timezone'] );
	}

	public function test_parse_local_handles_date_only() {
		$result = DateTimeParser::parseLocal( '2026-01-15', '', 'America/Denver' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '', $result['time'] );
	}

	public function test_parse_local_handles_invalid_timezone() {
		$result = DateTimeParser::parseLocal( '2026-01-15', '19:30', 'Invalid/TZ' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '19:30', $result['time'] );
		$this->assertEquals( '', $result['timezone'] );
	}

	public function test_parse_iso_extracts_timezone_offset() {
		$result = DateTimeParser::parseIso( '2026-01-15T19:30:00-06:00' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '19:30', $result['time'] );
		$this->assertNotEmpty( $result['timezone'] );
	}

	public function test_parse_iso_handles_utc_suffix() {
		$result = DateTimeParser::parseIso( '2026-01-15T19:30:00Z' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '19:30', $result['time'] );
	}

	public function test_parse_ics_floating_time_uses_calendar_timezone() {
		$result = DateTimeParser::parseIcs( '20260115T183000', 'America/Chicago' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '18:30', $result['time'] );
		$this->assertEquals( 'America/Chicago', $result['timezone'] );
	}

	public function test_parse_ics_utc_converts_to_calendar_timezone() {
		$result = DateTimeParser::parseIcs( '20260115T183000Z', 'America/Chicago' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '12:30', $result['time'] );
		$this->assertEquals( 'America/Chicago', $result['timezone'] );
	}

	public function test_parse_auto_detects_format() {
		$result = DateTimeParser::parse( '2026-01-15 19:30:00', 'America/Denver' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '19:30', $result['time'] );
	}

	public function test_parse_uses_fallback_timezone() {
		$result = DateTimeParser::parse( '2026-01-15 19:30', 'America/Chicago' );

		$this->assertEquals( '2026-01-15', $result['date'] );
		$this->assertEquals( '19:30', $result['time'] );
		$this->assertEquals( 'America/Chicago', $result['timezone'] );
	}

	public function test_parse_date_only_uses_fallback_timezone_without_shifting_day() {
		$result = DateTimeParser::parse( '2026-04-06', 'America/Chicago' );

		$this->assertEquals( '2026-04-06', $result['date'] );
		$this->assertEquals( '00:00', $result['time'] );
		$this->assertEquals( 'America/Chicago', $result['timezone'] );
	}

	public function test_parse_preserves_wall_clock_time_across_dst_transition() {
		$before = DateTimeParser::parse( '2026-03-07 00:30', 'America/New_York' );
		$after  = DateTimeParser::parse( '2026-03-09 00:30', 'America/New_York' );

		$this->assertEquals( '2026-03-07 00:30', $before['date'] . ' ' . $before['time'] );
		$this->assertEquals( '2026-03-09 00:30', $after['date'] . ' ' . $after['time'] );
		$this->assertEquals( 'America/New_York', $before['timezone'] );
		$this->assertEquals( 'America/New_York', $after['timezone'] );
	}

	public function test_parse_preserves_explicit_utc() {
		$result = DateTimeParser::parse( '2026-03-08T04:30:00Z', 'America/New_York' );

		$this->assertEquals( '2026-03-08', $result['date'] );
		$this->assertEquals( '04:30', $result['time'] );
		$this->assertEquals( 'Z', $result['timezone'] );
	}

	public function test_parse_preserves_literal_utc_identifier() {
		$result = DateTimeParser::parse( '2026-03-08 04:30 UTC', 'America/New_York' );

		$this->assertEquals( '2026-03-08', $result['date'] );
		$this->assertEquals( '04:30', $result['time'] );
		$this->assertEquals( 'UTC', $result['timezone'] );
	}

	public function test_parse_preserves_explicit_offset() {
		$result = DateTimeParser::parse( '2026-03-08T01:30:00-08:00', 'America/New_York' );

		$this->assertEquals( '2026-03-08', $result['date'] );
		$this->assertEquals( '01:30', $result['time'] );
		$this->assertEquals( '-08:00', $result['timezone'] );
	}

	public function test_parse_preserves_embedded_iana_timezone() {
		$result = DateTimeParser::parse( '2026-03-08 01:30 America/Los_Angeles', 'America/New_York' );

		$this->assertEquals( '2026-03-08', $result['date'] );
		$this->assertEquals( '01:30', $result['time'] );
		$this->assertEquals( 'America/Los_Angeles', $result['timezone'] );
	}

	public function test_parse_rejects_timezone_abbreviation() {
		$result = DateTimeParser::parse( '2026-03-08 01:30 CST', 'America/New_York' );

		$this->assertEquals( '', $result['date'] );
		$this->assertEquals( '', $result['time'] );
		$this->assertEquals( '', $result['timezone'] );
	}

	public function test_is_valid_timezone_returns_true_for_valid() {
		$this->assertTrue( DateTimeParser::isValidTimezone( 'America/Chicago' ) );
		$this->assertTrue( DateTimeParser::isValidTimezone( 'America/Denver' ) );
		$this->assertTrue( DateTimeParser::isValidTimezone( 'UTC' ) );
		$this->assertTrue( DateTimeParser::isValidTimezone( 'Europe/London' ) );
	}

	public function test_is_valid_timezone_returns_false_for_invalid() {
		$this->assertFalse( DateTimeParser::isValidTimezone( 'Invalid/Timezone' ) );
		$this->assertFalse( DateTimeParser::isValidTimezone( '' ) );
		$this->assertFalse( DateTimeParser::isValidTimezone( 'CST' ) );
	}

	/**
	 * isValidYmd is the single source of truth for "is this a real
	 * canonical Y-m-d date?". It must reject the placeholder / TBD strings
	 * (e.g. "2026-07-??") that caused the original fatal (#394), out-of-range
	 * months/days, impossible calendar dates, and empty input — while
	 * accepting genuine dates.
	 */
	public function test_is_valid_ymd_rejects_placeholder_day() {
		$this->assertFalse( DateTimeParser::isValidYmd( '2026-07-??' ) );
	}

	public function test_is_valid_ymd_rejects_placeholder_month_and_day() {
		$this->assertFalse( DateTimeParser::isValidYmd( '2026-??-??' ) );
	}

	public function test_is_valid_ymd_rejects_out_of_range_month() {
		$this->assertFalse( DateTimeParser::isValidYmd( '2026-13-01' ) );
	}

	public function test_is_valid_ymd_rejects_impossible_calendar_date() {
		$this->assertFalse( DateTimeParser::isValidYmd( '2026-02-30' ) );
	}

	public function test_is_valid_ymd_rejects_empty_string() {
		$this->assertFalse( DateTimeParser::isValidYmd( '' ) );
	}

	public function test_is_valid_ymd_rejects_non_ymd_shapes() {
		$this->assertFalse( DateTimeParser::isValidYmd( '07/15/2026' ) );
		$this->assertFalse( DateTimeParser::isValidYmd( '2026-7-5' ) );
		$this->assertFalse( DateTimeParser::isValidYmd( '2026-07-15 19:30' ) );
	}

	public function test_is_valid_ymd_accepts_real_date() {
		$this->assertTrue( DateTimeParser::isValidYmd( '2026-07-15' ) );
		$this->assertTrue( DateTimeParser::isValidYmd( '2024-02-29' ) );
	}

	/**
	 * safeCreate is the non-throwing factory used on the render path. It must
	 * return null (never throw) for any input isValidYmd rejects, and a real
	 * DateTime for valid input — including an optional time-of-day suffix.
	 */
	public function test_safe_create_returns_null_for_placeholder_day() {
		$this->assertNull( DateTimeParser::safeCreate( '2026-07-??' ) );
	}

	public function test_safe_create_returns_null_for_placeholder_month_and_day() {
		$this->assertNull( DateTimeParser::safeCreate( '2026-??-??' ) );
	}

	public function test_safe_create_returns_null_for_out_of_range_month() {
		$this->assertNull( DateTimeParser::safeCreate( '2026-13-01' ) );
	}

	public function test_safe_create_returns_null_for_impossible_calendar_date() {
		$this->assertNull( DateTimeParser::safeCreate( '2026-02-30' ) );
	}

	public function test_safe_create_returns_null_for_empty_string() {
		$this->assertNull( DateTimeParser::safeCreate( '' ) );
	}

	public function test_safe_create_returns_datetime_for_valid_date() {
		$dt = DateTimeParser::safeCreate( '2026-07-15' );

		$this->assertInstanceOf( DateTime::class, $dt );
		$this->assertEquals( '2026-07-15', $dt->format( 'Y-m-d' ) );
	}

	public function test_safe_create_accepts_time_suffix() {
		$dt = DateTimeParser::safeCreate( '2026-07-15 19:30:00' );

		$this->assertInstanceOf( DateTime::class, $dt );
		$this->assertEquals( '2026-07-15 19:30:00', $dt->format( 'Y-m-d H:i:s' ) );
	}

	public function test_safe_create_honors_timezone() {
		$tz = new DateTimeZone( 'America/New_York' );
		$dt = DateTimeParser::safeCreate( '2026-07-15 00:00:00', $tz );

		$this->assertInstanceOf( DateTime::class, $dt );
		$this->assertEquals( 'America/New_York', $dt->getTimezone()->getName() );
	}

	public function test_safe_create_rejects_malformed_date_even_with_time_suffix() {
		$this->assertNull( DateTimeParser::safeCreate( '2026-07-?? 00:00:00' ) );
	}
}
