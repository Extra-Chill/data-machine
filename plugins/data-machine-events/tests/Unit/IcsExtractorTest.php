<?php
/**
 * ICS Extractor Tests
 *
 * Tests floating time handling in ICS feeds.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\IcsExtractor;

class IcsExtractorTest extends WP_UnitTestCase {

	private IcsExtractor $extractor;

	public function setUp(): void {
		parent::setUp();
		$this->extractor = new IcsExtractor();
	}

	public function test_can_extract_detects_ics_content() {
		$ics_content = "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nDTSTART:20260119T180000\nSUMMARY:Test Event\nEND:VEVENT\nEND:VCALENDAR";

		$this->assertTrue( $this->extractor->canExtract( $ics_content ) );
	}

	public function test_floating_time_not_converted() {
		$ics_content = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
X-WR-TIMEZONE:America/Chicago
BEGIN:VTIMEZONE
TZID:America/Chicago
END:VTIMEZONE
BEGIN:VEVENT
DTSTART:20260119T180000
DTEND:20260119T200000
SUMMARY:Floating Time Test
LOCATION:Test Venue
END:VEVENT
END:VCALENDAR
ICS;

		$events = $this->extractor->extract( $ics_content, 'https://example.com/events.ics' );

		$this->assertNotEmpty( $events, 'Should extract at least one event' );

		$event = $events[0];

		// Floating time (no Z suffix) should NOT be converted
		// 18:00 should remain 18:00, not become 12:00
		$this->assertEquals( '18:00', $event['startTime'], 'Floating time should not be converted from UTC' );
		$this->assertEquals( '20:00', $event['endTime'], 'Floating end time should not be converted from UTC' );
	}

	public function test_explicit_utc_time_is_converted() {
		$ics_content = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
X-WR-TIMEZONE:America/Chicago
BEGIN:VTIMEZONE
TZID:America/Chicago
END:VTIMEZONE
BEGIN:VEVENT
DTSTART:20260119T180000Z
DTEND:20260119T200000Z
SUMMARY:UTC Time Test
LOCATION:Test Venue
END:VEVENT
END:VCALENDAR
ICS;

		$events = $this->extractor->extract( $ics_content, 'https://example.com/events.ics' );

		$this->assertNotEmpty( $events, 'Should extract at least one event' );

		$event = $events[0];

		// Explicit UTC (Z suffix) SHOULD be converted to local timezone
		// 18:00 UTC = 12:00 Central (CST is -6)
		$this->assertEquals( '12:00', $event['startTime'], 'Explicit UTC time should be converted to local timezone' );
		$this->assertEquals( '14:00', $event['endTime'], 'Explicit UTC end time should be converted to local timezone' );
	}

	public function test_explicit_tzid_time_preserved() {
		$ics_content = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VTIMEZONE
TZID:America/Chicago
END:VTIMEZONE
BEGIN:VEVENT
DTSTART;TZID=America/Chicago:20260119T180000
DTEND;TZID=America/Chicago:20260119T200000
SUMMARY:TZID Time Test
LOCATION:Test Venue
END:VEVENT
END:VCALENDAR
ICS;

		$events = $this->extractor->extract( $ics_content, 'https://example.com/events.ics' );

		$this->assertNotEmpty( $events, 'Should extract at least one event' );

		$event = $events[0];

		// Explicit TZID should be preserved as-is
		$this->assertEquals( '18:00', $event['startTime'], 'Time with explicit TZID should be preserved' );
		$this->assertEquals( '20:00', $event['endTime'], 'End time with explicit TZID should be preserved' );
		$this->assertEquals( 'America/Chicago', $event['venueTimezone'], 'Timezone should be preserved from TZID' );
	}

	public function test_extraction_method_is_ics_feed() {
		$this->assertEquals( 'ics_feed', $this->extractor->getMethod() );
	}
}
