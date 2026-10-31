<?php
/**
 * Turntable Tickets extractor tests.
 *
 * Covers detection of Turntable Tickets venue sites (window.__pinia SSR
 * state) and extraction of performances via the public JSON API, with the
 * embedded SSR performances as a fallback. Fixtures are trimmed from the
 * live Forte Jazz Lounge site (forte-jazz-lounge.turntabletickets.com).
 *
 * Regression coverage for data-machine-events#941 (Forte Jazz Lounge,
 * flow 52 — scraper extracted 0 events, imports silently stopped).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.68.1
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\TurntableTicketsExtractor;
use WP_UnitTestCase;

class TurntableTicketsExtractorTest extends WP_UnitTestCase {

	private TurntableTicketsExtractor $extractor;
	private string $fixtures_dir;
	private array $requests = array();

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new TurntableTicketsExtractor();
		$this->fixtures_dir = __DIR__ . '/../Fixtures/turntable-tickets';
		$this->requests     = array();
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function fixtureHtml(): string {
		return file_get_contents( $this->fixtures_dir . '/forte-jazz-lounge-home.html' );
	}

	// ────────────────────────────────────────────────────────────────────
	// Detection
	// ────────────────────────────────────────────────────────────────────

	public function test_canExtract_detects_turntable_pinia_state(): void {
		$this->assertTrue( $this->extractor->canExtract( $this->fixtureHtml() ) );
	}

	public function test_canExtract_rejects_plain_pages(): void {
		$this->assertFalse( $this->extractor->canExtract( '<html><body>Plain page</body></html>' ) );
		$this->assertFalse( $this->extractor->canExtract( '' ) );
	}

	public function test_canExtract_rejects_pages_that_merely_link_turntable(): void {
		$html = '<html><body><a href="https://forte-jazz-lounge.turntabletickets.com/">Buy tickets</a></body></html>';
		$this->assertFalse( $this->extractor->canExtract( $html ) );
	}

	public function test_getMethod_identifier(): void {
		$this->assertSame( 'turntable_tickets', $this->extractor->getMethod() );
	}

	// ────────────────────────────────────────────────────────────────────
	// Extraction via the performance API
	// ────────────────────────────────────────────────────────────────────

	public function test_extract_paginates_api_and_normalizes_performances(): void {
		$this->mockPerformanceApi(
			array(
				1 => 'api-performance-page-1.json',
				2 => 'api-performance-page-2.json',
			)
		);

		$events = $this->extractor->extract( $this->fixtureHtml(), 'https://forte-jazz-lounge.turntabletickets.com/' );

		$this->assertCount( 4, $events );
		$this->assertCount( 2, $this->requests, 'Should fetch both API pages.' );
		$this->assertStringContainsString(
			'https://forte-jazz-lounge.turntabletickets.com/api/performance/?start_date=',
			$this->requests[0]['url']
		);
		$this->assertStringContainsString( 'page=1', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'page=2', $this->requests[1]['url'] );

		$first = $events[0];
		// Stable show name, not the "Vocal Jazz Series - 2026" residency label.
		$this->assertSame( 'Sinatra: Nothing But The Best | Joe Clarke & The Forte Show Band', $first['title'] );
		// 2026-10-16T23:00:00Z → 7:00 PM Eastern on the same day.
		$this->assertSame( '2026-10-16', $first['startDate'] );
		$this->assertSame( '19:00', $first['startTime'] );
		$this->assertSame( 'Forte Jazz Lounge', $first['venue'] );
		// "US/Eastern" from the settings store is normalized to the canonical zone.
		$this->assertSame( 'America/New_York', $first['venueTimezone'] );
		$this->assertSame( 'https://forte-jazz-lounge.turntabletickets.com/shows/12360/2026-10-16', $first['ticketUrl'] );
		$this->assertSame( '$25.00 - $90.00', $first['price'] );
		$this->assertStringContainsString( 'assets-prod.turntabletickets.com', $first['imageUrl'] );

		// 2026-10-17T01:30:00Z is the 9:30 PM Eastern set on Oct 16 — the UTC
		// date must not leak into startDate.
		$late = $events[1];
		$this->assertSame( '2026-10-16', $late['startDate'] );
		$this->assertSame( '21:30', $late['startTime'] );

		$this->assertSame(
			"Legacy: Greatest R&B, Soul & Motown Hits of the 60's, 70's & 80's",
			$events[2]['title']
		);
		$this->assertSame( 'NIGHT & DAY - The Music of Cole Porter', $events[3]['title'] );
		$this->assertSame( '2026-10-23', $events[3]['startDate'] );
	}

	// ────────────────────────────────────────────────────────────────────
	// Fallback to embedded SSR performances
	// ────────────────────────────────────────────────────────────────────

	public function test_extract_falls_back_to_embedded_state_when_api_fails(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'boom' );
			},
			10
		);

		$events = $this->extractor->extract( $this->fixtureHtml(), 'https://forte-jazz-lounge.turntabletickets.com/' );

		$this->assertCount( 3, $events );

		// Pre-localized SSR date/time is used directly.
		$this->assertSame( 'The Great American Songbook featuring Joe Clarke', $events[0]['title'] );
		$this->assertSame( '2026-10-15', $events[0]['startDate'] );
		$this->assertSame( '19:00', $events[0]['startTime'] );
		$this->assertSame( 'Forte Jazz Lounge', $events[0]['venue'] );
		$this->assertSame( 'https://forte-jazz-lounge.turntabletickets.com/shows/1977/2026-10-15', $events[0]['ticketUrl'] );

		$this->assertSame( '2026-10-16', $events[2]['startDate'] );
		$this->assertSame( '21:30', $events[2]['startTime'] );
	}

	public function test_extract_returns_empty_for_html_without_pinia_state(): void {
		$this->assertSame(
			array(),
			$this->extractor->extract( '<html><body>No state here</body></html>', 'https://venue.test/' )
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Mock the Turntable Tickets performance API with per-page fixtures.
	 *
	 * The start_date query argument is the venue-local "today", so URLs are
	 * matched on the endpoint path + page number rather than exact strings.
	 *
	 * @param array $pages Page-number-keyed fixture filenames.
	 */
	private function mockPerformanceApi( array $pages ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $pages ) {
				$this->requests[] = array( 'url' => $url );

				if ( false !== strpos( $url, '/api/performance/?start_date=' )
					&& preg_match( '/[?&]page=(\d+)/', $url, $matches ) ) {
					$page = (int) $matches[1];
					if ( isset( $pages[ $page ] ) ) {
						return array(
							'headers'  => array(),
							'body'     => file_get_contents( $this->fixtures_dir . '/' . $pages[ $page ] ),
							'response' => array( 'code' => 200, 'message' => 'OK' ),
							'cookies'  => array(),
							'filename' => null,
						);
					}
				}

				return new \WP_Error( 'http_request_failed', 'Unmocked URL: ' . $url );
			},
			10,
			3
		);
	}
}
