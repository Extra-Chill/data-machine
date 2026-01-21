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

	public function test_parse_utc_returns_empty_for_invalid_timezone() {
		$result = DateTimeParser::parseUtc( '2026-01-15T18:00:00Z', 'Invalid/Timezone' );

		$this->assertEquals( '', $result['date'] );
		$this->assertEquals( '', $result['time'] );
		$this->assertEquals( '', $result['timezone'] );
	}

	public function test_parse_utc_returns_empty_for_empty_datetime() {
		$result = DateTimeParser::parseUtc( '', 'America/Chicago' );

		$this->assertEquals( '', $result['date'] );
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

		$this->assertEquals( 'America/Chicago', $result['timezone'] );
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
}
