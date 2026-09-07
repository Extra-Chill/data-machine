<?php
/**
 * Bandzoogle Extractor Tests
 *
 * Covers detection of Bandzoogle CMS pages and extraction of events from the
 * modern `event-detail` list view, using a real production snapshot of the
 * Elephant Room (Austin, TX) calendar as the fixture.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.15.x
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\BandzoogleExtractor;

class BandzoogleExtractorTest extends WP_UnitTestCase {

	private BandzoogleExtractor $extractor;
	private string $fixture_path;

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new BandzoogleExtractor();
		$this->fixture_path = __DIR__ . '/../Fixtures/bandzoogle-elephant-room.html';
	}

	/**
	 * Detect Bandzoogle from the Elephant Room production snapshot.
	 *
	 * The fixture contains `bndzgl.com` / `zoogletools.com` asset URLs and
	 * `data-event-id` attributes on `.event-detail` blocks — any one of
	 * those is sufficient.
	 */
	public function test_canExtract_detects_bandzoogle_fixture() {
		if ( ! file_exists( $this->fixture_path ) ) {
			$this->markTestSkipped( 'Bandzoogle fixture not present.' );
		}

		$html = file_get_contents( $this->fixture_path );
		$this->assertTrue(
			$this->extractor->canExtract( $html ),
			'Should detect Bandzoogle from real Elephant Room calendar markup.'
		);
	}

	/**
	 * Reject non-Bandzoogle HTML.
	 */
	public function test_canExtract_rejects_non_bandzoogle() {
		$html = '<html><head><title>Not a venue</title></head><body><p>Plain HTML, no platform fingerprint.</p></body></html>';
		$this->assertFalse(
			$this->extractor->canExtract( $html ),
			'Plain HTML should not match Bandzoogle detection markers.'
		);
	}

	/**
	 * Empty input is rejected.
	 */
	public function test_canExtract_rejects_empty_string() {
		$this->assertFalse( $this->extractor->canExtract( '' ) );
	}

	/**
	 * Pull at least 5 events from the Elephant Room snapshot.
	 *
	 * The captured page shows ~20 events for the current month, so this
	 * threshold is well below the real yield while leaving headroom for
	 * future snapshot refreshes that may have a slower month.
	 */
	public function test_extract_returns_events_from_elephant_room_fixture() {
		if ( ! file_exists( $this->fixture_path ) ) {
			$this->markTestSkipped( 'Bandzoogle fixture not present.' );
		}

		$html   = file_get_contents( $this->fixture_path );
		$events = $this->extractor->extract( $html, 'https://elephantroom.com/calendar' );

		$this->assertGreaterThanOrEqual(
			5,
			count( $events ),
			'Real Bandzoogle calendars should yield at least 5 extracted events.'
		);
	}

	/**
	 * Each extracted event has the fields downstream pipelines require.
	 */
	public function test_extract_event_has_required_fields() {
		if ( ! file_exists( $this->fixture_path ) ) {
			$this->markTestSkipped( 'Bandzoogle fixture not present.' );
		}

		$html   = file_get_contents( $this->fixture_path );
		$events = $this->extractor->extract( $html, 'https://elephantroom.com/calendar' );

		$this->assertNotEmpty( $events, 'Need at least one event to inspect.' );

		$event = $events[0];

		$this->assertNotEmpty( $event['title'], 'Event must have a non-empty title.' );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}$/',
			$event['startDate'],
			'startDate must be a YYYY-MM-DD string.'
		);
		// Bandzoogle's event-detail page always exists — fallback ensures ticketUrl is set.
		$this->assertNotEmpty(
			$event['ticketUrl'],
			'ticketUrl must be populated (external vendor or event detail page fallback).'
		);
		// imageUrl populated for nearly every Bandzoogle event card.
		$this->assertNotEmpty( $event['imageUrl'], 'imageUrl should be populated from event-image block.' );
		$this->assertSame( 'bandzoogle', $this->extractor->getMethod() );
	}

	/**
	 * Synthetic legacy `.gig-info` block without a `<time datetime>` attr.
	 *
	 * Confirms the legacy parser either yields a useful date (from time text)
	 * or skips the event without throwing.
	 */
	public function test_extract_handles_missing_datetime_attr_gracefully() {
		// Note the `bandzoogle.com` footer credit so detection passes.
		$html = '<html><body>'
			. '<div class="gig-info">'
			. '<time>Wednesday, May 13 at 8:00 PM</time>'
			. '<span class="gig-artist">Test Artist</span>'
			. '</div>'
			. '<p>powered by bandzoogle.com</p>'
			. '</body></html>';

		// Should not throw.
		$events = $this->extractor->extract( $html, 'https://example.com/calendar' );
		$this->assertIsArray( $events, 'extract() must always return an array, even with malformed markup.' );

		// Either the legacy parser produces a usable event, or it skips it —
		// both are acceptable. We just need NO fatal errors and a stable shape.
		foreach ( $events as $event ) {
			$this->assertArrayHasKey( 'title', $event );
			$this->assertArrayHasKey( 'startDate', $event );
		}
	}

	/**
	 * Direct unit test against the legacy `.gig-info` parser with an
	 * `<time datetime="...">` attribute (ISO 8601).
	 *
	 * Even though our production sample uses the newer markup, the issue
	 * (#261) explicitly called out the legacy shape, so it gets a regression
	 * test here.
	 */
	public function test_extract_parses_legacy_gig_info_with_iso_datetime() {
		$html = '<html><body>'
			. '<section class="gigs">'
			. '<div class="gig-info">'
			. '<time datetime="2026-05-20T20:00:00-05:00">Wed May 20</time>'
			. '<span class="gig-artist">Brownout</span>'
			. '<div class="gig-tickets"><a href="https://example.com/tickets/brownout">Tickets</a></div>'
			. '</div>'
			. '</section>'
			. '<p>powered by bandzoogle.com</p>'
			. '</body></html>';

		$this->assertTrue( $this->extractor->canExtract( $html ) );

		$events = $this->extractor->extract( $html, 'https://example.com/calendar' );
		$this->assertNotEmpty( $events, 'Legacy gig-info block with ISO datetime should yield an event.' );

		$event = $events[0];
		$this->assertSame( 'Brownout', $event['title'] );
		$this->assertSame( '2026-05-20', $event['startDate'] );
		$this->assertSame( '20:00', $event['startTime'] );
		$this->assertSame( 'https://example.com/tickets/brownout', $event['ticketUrl'] );
	}

	/**
	 * Artist tour page (Johnny Delaware): per-event locations are the real
	 * venues and must not be dropped in favor of the title-derived page
	 * venue ("Tour"). The fixture has no calendar year header or ?year=
	 * pagination, so dates fall back to inference against an injected
	 * "now" of 2026-09-07 (#770).
	 */
	public function test_extract_artist_tour_page_uses_per_event_venue_names() {
		$html = $this->loadFixture( 'bandzoogle-johnny-delaware-tour.html' );
		$this->extractor->setNow( new \DateTimeImmutable( '2026-09-07 12:00:00' ) );

		$events = $this->extractor->extract( $html, 'https://johnnydelaware.com/tour' );

		$this->assertCount( 5, $events, 'The tour fixture contains 5 event-detail blocks.' );

		$first = $events[0];
		$this->assertSame( 'Visby, SWE', $first['title'] );
		$this->assertSame( '2026-09-09', $first['startDate'] );
		$this->assertSame( 'Rootsy Visby', $first['venue'] );

		$this->assertSame( 'Gripsholms värdshus', $events[1]['venue'] );
		$this->assertSame( 'MS Blidösund', $events[2]['venue'] );

		foreach ( $events as $event ) {
			$this->assertNotSame(
				'US',
				$event['venueCountry'],
				'Tour stops in Sweden must not inherit the US country default.'
			);
		}
	}

	/**
	 * Artist show list (Mel Washington): a comma-separated per-event
	 * location splits into venue name + street + city/state/zip, and a
	 * date only one day in the past (Sep 6 vs injected now Sep 7) keeps
	 * the current year instead of rolling into 2027 (#770).
	 */
	public function test_extract_artist_show_page_splits_location_address() {
		$html = $this->loadFixture( 'bandzoogle-mel-washington-shows.html' );
		$this->extractor->setNow( new \DateTimeImmutable( '2026-09-07 12:00:00' ) );

		$events = $this->extractor->extract( $html, 'https://melwashington.com/shows' );

		$this->assertCount( 10, $events, 'The shows fixture contains 10 event-detail blocks.' );

		$first = $events[0];
		$this->assertSame( 'Island Cabana Bar', $first['venue'] );
		$this->assertStringContainsString( '50 Immigration St', $first['venueAddress'] );
		$this->assertSame( 'Charleston', $first['venueCity'] );
		$this->assertSame( 'SC', $first['venueState'] );
		$this->assertSame( '29403', $first['venueZip'] );
		$this->assertSame( '2026-09-06', $first['startDate'], 'A date 1 day in the past must keep the current year.' );
		$this->assertSame( '13:00', $first['startTime'] );
		$this->assertSame( '16:00', $first['endTime'], 'End time must survive a to-block that carries time only.' );
	}

	/**
	 * Venue site (Elephant Room): event-location holds sub-room labels
	 * ("🐘6pm", "🐘7pm Show") that must never replace the page-level
	 * venue name (#770).
	 */
	public function test_extract_venue_site_keeps_page_venue_over_sub_room_labels() {
		$html   = $this->loadFixture( 'bandzoogle-elephant-room.html' );
		$events = $this->extractor->extract( $html, 'https://elephantroom.com/calendar' );

		$this->assertNotEmpty( $events );

		foreach ( $events as $event ) {
			$this->assertSame(
				'Elephant Room',
				$event['venue'],
				'Sub-room labels in event-location must not replace the venue name.'
			);
		}
	}

	/**
	 * Load a fixture relative to tests/Fixtures.
	 */
	private function loadFixture( string $filename ): string {
		$path = __DIR__ . '/../Fixtures/' . $filename;
		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'Fixture not present: ' . $filename );
		}

		$html = file_get_contents( $path );
		$this->assertNotFalse( $html, 'Fixture must be readable: ' . $filename );
		$this->assertTrue( $this->extractor->canExtract( $html ), 'Fixture should match Bandzoogle detection: ' . $filename );

		return $html;
	}
}
