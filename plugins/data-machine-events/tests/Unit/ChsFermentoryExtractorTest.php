<?php
/**
 * Charles Towne Fermentory extractor tests.
 *
 * chsfermentory.com migrated from Wix to a custom React SPA whose static
 * HTML is an empty shell; the calendar lives at /api/events/upcoming.
 * Fixtures are trimmed from the live site (2026-10-16).
 *
 * Regression coverage for data-machine-events#941 (Charles Towne Fermentory,
 * flow 20 — daily completed_no_items while the calendar had upcoming events).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.68.1
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\ChsFermentoryExtractor;
use WP_UnitTestCase;

class ChsFermentoryExtractorTest extends WP_UnitTestCase {

	private ChsFermentoryExtractor $extractor;
	private string $fixtures_dir;

	private const SOURCE_URL = 'https://chsfermentory.com/charleston-charles-towne-fermentory-events';

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new ChsFermentoryExtractor();
		$this->fixtures_dir = __DIR__ . '/../Fixtures/chs-fermentory';
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function fixtureHtml(): string {
		return file_get_contents( $this->fixtures_dir . '/events-page.html' );
	}

	// ────────────────────────────────────────────────────────────────────
	// Detection
	// ────────────────────────────────────────────────────────────────────

	public function test_canExtract_detects_fermentory_spa_shell(): void {
		$this->assertTrue( $this->extractor->canExtract( $this->fixtureHtml() ) );
	}

	public function test_canExtract_rejects_other_pages(): void {
		$this->assertFalse( $this->extractor->canExtract( '<html><body>Plain page</body></html>' ) );
		$this->assertFalse( $this->extractor->canExtract( '<html><div id="root"></div></html>' ) );
		$this->assertFalse( $this->extractor->canExtract( '' ) );
	}

	public function test_getMethod_identifier(): void {
		$this->assertSame( 'chs_fermentory', $this->extractor->getMethod() );
	}

	// ────────────────────────────────────────────────────────────────────
	// Extraction
	// ────────────────────────────────────────────────────────────────────

	public function test_extract_parses_upcoming_events_api(): void {
		$this->mockEventsApi();

		$events = $this->extractor->extract( $this->fixtureHtml(), self::SOURCE_URL );

		$this->assertCount( 4, $events );

		$first = $events[0];
		$this->assertSame( 'King of Pops', $first['title'] );
		// 2026-10-16T16:30:00.000Z → 12:30 PM America/New_York.
		$this->assertSame( '2026-10-16', $first['startDate'] );
		$this->assertSame( '12:30', $first['startTime'] );
		$this->assertSame( '15:30', $first['endTime'] );
		$this->assertSame( 'Charles Towne Fermentory', $first['venue'] );
		$this->assertSame( 'America/New_York', $first['venueTimezone'] );
		$this->assertStringContainsString( 'Location: The Garden', $first['description'] );
		// imageUrl is null in the API; vendor photo is resolved against the origin.
		$this->assertStringStartsWith( 'https://chsfermentory.com/objects/uploads/', $first['imageUrl'] );

		$this->assertSame( 'Duo Fitness', $events[2]['title'] );
		$this->assertSame( '2026-10-17', $events[2]['startDate'] );
		$this->assertSame( '07:00', $events[2]['startTime'] );
	}

	public function test_extract_returns_empty_for_foreign_host(): void {
		$events = $this->extractor->extract( $this->fixtureHtml(), 'https://some-other-site.test/events' );
		$this->assertSame( array(), $events );
	}

	public function test_extract_returns_empty_on_api_failure(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'boom' );
			},
			10
		);

		$this->assertSame( array(), $this->extractor->extract( $this->fixtureHtml(), self::SOURCE_URL ) );
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

	private function mockEventsApi(): void {
		$fixtures_dir = $this->fixtures_dir;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $fixtures_dir ) {
				if ( 'https://chsfermentory.com/api/events/upcoming' === $url ) {
					return array(
						'headers'  => array(),
						'body'     => file_get_contents( $fixtures_dir . '/api-events-upcoming.json' ),
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return new \WP_Error( 'http_request_failed', 'Unmocked URL: ' . $url );
			},
			10,
			3
		);
	}
}
