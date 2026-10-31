<?php
/**
 * Webflow Extractor Tests
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WebflowExtractor;
use WP_UnitTestCase;

class WebflowExtractorTest extends WP_UnitTestCase {

	private WebflowExtractor $extractor;
	private string $fixtures_dir;

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new WebflowExtractor();
		$this->fixtures_dir = dirname( __DIR__ ) . '/Fixtures/webflow';
	}

	public function test_extracts_localized_day_first_dates_from_event_collection() {
		$html   = file_get_contents( $this->fixtures_dir . '/localized-listing.html' );
		$events = $this->extractor->extract( $html, 'https://venue.example.test/' );

		$this->assertCount( 2, $events );
		$this->assertSame( 'Show One', $events[0]['title'] );
		$this->assertSame( $this->expectedInferredDate( '08-07' ), $events[0]['startDate'] );
		$this->assertSame( 'https://tickets.example.test/show-one', $events[0]['ticketUrl'] );
		$this->assertSame( 'Show Two', $events[1]['title'] );
		$this->assertSame( $this->expectedInferredDate( '09-11' ), $events[1]['startDate'] );
	}

	public function test_extracts_numeric_dates_without_promoting_nested_collection_items() {
		$html   = file_get_contents( $this->fixtures_dir . '/numeric-nested-listing.html' );
		$events = $this->extractor->extract( $html, 'https://venue.example.test/event' );

		$this->assertCount( 2, $events );
		$this->assertSame( 'First Show', $events[0]['title'] );
		$this->assertSame( '2026-07-30', $events[0]['startDate'] );
		$this->assertSame( 'https://cdn.example.test/first.jpg', $events[0]['imageUrl'] );
		$this->assertSame( 'Second Show', $events[1]['title'] );
		$this->assertSame( '2026-08-01', $events[1]['startDate'] );
		$this->assertSame( 'https://venue.example.test/images/second.jpg', $events[1]['imageUrl'] );
	}

	public function test_availability_embed_without_dated_events_remains_unsupported() {
		$html = file_get_contents( $this->fixtures_dir . '/unsupported-availability.html' );

		$this->assertTrue( $this->extractor->canExtract( $html ) );
		$this->assertSame( array(), $this->extractor->extract( $html, 'https://venue.example.test/rooftop' ) );
	}

	public function test_rejects_invalid_numeric_dates() {
		$html = '<html data-wf-site="fixture"><body><div class="w-dyn-item"><h3>Not An Event</h3><p>13/40/26</p></div></body></html>';

		$this->assertSame( array(), $this->extractor->extract( $html, 'https://venue.example.test/' ) );
	}

	private function expectedInferredDate( string $month_day ): string {
		$year = (int) gmdate( 'Y' );
		if ( strtotime( $year . '-' . $month_day ) < strtotime( '-7 days' ) ) {
			++$year;
		}

		return $year . '-' . $month_day;
	}
}
