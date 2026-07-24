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
		$date        = gmdate( 'Ymd', strtotime( '+14 days' ) );
		$ics_content = "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nDTSTART:{$date}T180000\nSUMMARY:Test Event\nEND:VEVENT\nEND:VCALENDAR";

		$this->assertTrue( $this->extractor->canExtract( $ics_content ) );
	}

	public function test_floating_time_not_converted() {
		$date        = gmdate( 'Ymd', strtotime( '+14 days' ) );
		$ics_content = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
X-WR-TIMEZONE:America/Chicago
BEGIN:VTIMEZONE
TZID:America/Chicago
END:VTIMEZONE
BEGIN:VEVENT
DTSTART:{$date}T180000
DTEND:{$date}T200000
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
		$date        = gmdate( 'Ymd', strtotime( '+14 days' ) );
		$ics_content = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
X-WR-TIMEZONE:America/Chicago
BEGIN:VTIMEZONE
TZID:America/Chicago
END:VTIMEZONE
BEGIN:VEVENT
DTSTART:{$date}T180000Z
DTEND:{$date}T200000Z
SUMMARY:UTC Time Test
LOCATION:Test Venue
END:VEVENT
END:VCALENDAR
ICS;

		$events = $this->extractor->extract( $ics_content, 'https://example.com/events.ics' );

		$this->assertNotEmpty( $events, 'Should extract at least one event' );

		$event = $events[0];

		$start = new \DateTime( $date . ' 18:00:00', new \DateTimeZone( 'UTC' ) );
		$end   = new \DateTime( $date . ' 20:00:00', new \DateTimeZone( 'UTC' ) );
		$start->setTimezone( new \DateTimeZone( 'America/Chicago' ) );
		$end->setTimezone( new \DateTimeZone( 'America/Chicago' ) );

		$this->assertEquals( $start->format( 'H:i' ), $event['startTime'], 'Explicit UTC time should be converted to local timezone' );
		$this->assertEquals( $end->format( 'H:i' ), $event['endTime'], 'Explicit UTC end time should be converted to local timezone' );
	}

	public function test_explicit_tzid_time_preserved() {
		$date        = gmdate( 'Ymd', strtotime( '+14 days' ) );
		$ics_content = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VTIMEZONE
TZID:America/Chicago
END:VTIMEZONE
BEGIN:VEVENT
DTSTART;TZID=America/Chicago:{$date}T180000
DTEND;TZID=America/Chicago:{$date}T200000
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

	/**
	 * Build a minimal single-event ICS feed at the given DTSTART.
	 */
	private function build_ics( string $dtstart, string $summary = 'Test Event' ): string {
		return <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VTIMEZONE
TZID:America/New_York
END:VTIMEZONE
BEGIN:VEVENT
DTSTART;TZID=America/New_York:{$dtstart}
DTEND;TZID=America/New_York:{$dtstart}
SUMMARY:{$summary}
LOCATION:Test Venue
END:VEVENT
END:VCALENDAR
ICS;
	}

	public function test_recurrence_horizon_drops_far_future_occurrences() {
		// ~1 year out — well beyond the default 90-day horizon.
		$far_date = gmdate( 'Ymd\THis', strtotime( '+1 year' ) );
		$ics      = $this->build_ics( $far_date, 'Year Out' );

		$events = $this->extractor->extract( $ics, 'https://example.com/events.ics' );

		$this->assertEmpty( $events, 'Far-future occurrence beyond the horizon must be dropped' );
	}

	public function test_recurrence_horizon_filter_extends_window() {
		$far_date = gmdate( 'Ymd\THis', strtotime( '+1 year' ) );
		$ics      = $this->build_ics( $far_date, 'Year Out' );

		add_filter(
			'data_machine_events_scraper_recurrence_horizon_days',
			static function () {
				return 400;
			}
		);

		$events = $this->extractor->extract( $ics, 'https://example.com/events.ics' );

		$this->assertCount( 1, $events, 'Far-future occurrence must be kept when horizon is raised via filter' );
		$this->assertEquals( 'Year Out', $events[0]['title'] );
	}

	public function test_recurrence_cap_keeps_nearest_events() {
		// Three near-term events (all within the default 90-day horizon).
		$d1 = gmdate( 'Ymd\THis', strtotime( '+5 days' ) );
		$d2 = gmdate( 'Ymd\THis', strtotime( '+20 days' ) );
		$d3 = gmdate( 'Ymd\THis', strtotime( '+30 days' ) );

		$ics = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VTIMEZONE
TZID:America/New_York
END:VTIMEZONE
BEGIN:VEVENT
DTSTART;TZID=America/New_York:{$d3}
SUMMARY:Mid30
LOCATION:Test Venue
END:VEVENT
BEGIN:VEVENT
DTSTART;TZID=America/New_York:{$d1}
SUMMARY:Near5
LOCATION:Test Venue
END:VEVENT
BEGIN:VEVENT
DTSTART;TZID=America/New_York:{$d2}
SUMMARY:Mid20
LOCATION:Test Venue
END:VEVENT
END:VCALENDAR
ICS;

		// Lower the cap to 2 so the farthest (+30d) is dropped.
		add_filter(
			'data_machine_events_scraper_max_events',
			static function () {
				return 2;
			}
		);

		$events = $this->extractor->extract( $ics, 'https://example.com/events.ics' );

		$this->assertCount( 2, $events, 'Cap must keep only the nearest N events' );
		$titles = array_column( $events, 'title' );
		$this->assertEquals( array( 'Near5', 'Mid20' ), $titles, 'Cap must keep the nearest events, ascending' );
		$this->assertNotContains( 'Mid30', $titles, 'Farthest event within horizon must be dropped when cap bites' );
	}
}
