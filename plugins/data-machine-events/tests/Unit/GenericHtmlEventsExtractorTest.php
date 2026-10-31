<?php
/**
 * Generic HTML Events Extractor Tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\GenericHtmlEventsExtractor;
use ReflectionClass;
use WP_UnitTestCase;

class GenericHtmlEventsExtractorTest extends WP_UnitTestCase {

	private GenericHtmlEventsExtractor $extractor;
	private string $fixture_dir;

	public function setUp(): void {
		parent::setUp();
		$this->extractor   = new GenericHtmlEventsExtractor();
		$this->fixture_dir = __DIR__ . '/../Fixtures/wp-generic';
	}

	public function test_extracts_repeated_semantic_event_cards(): void {
		$html = file_get_contents( $this->fixture_dir . '/semantic-event-cards.html' );

		$this->assertTrue( $this->extractor->canExtract( $html ) );

		$events = $this->extractor->extract( $html, 'https://venue.example.com/' );

		$this->assertCount( 3, $events );
		$this->assertSame( 'Show One', $events[0]['title'] );
		$this->assertSame( gmdate( 'Y' ) . '-07-28', $events[0]['startDate'] );
		$this->assertSame( '20:00', $events[0]['startTime'] );
		$this->assertSame( 'https://tickets.example.net/show-one', $events[0]['source_url'] );
		$this->assertSame( 'Show Three', $events[2]['title'] );
		$this->assertSame( '2099-07-30', $events[2]['startDate'] );
		$this->assertSame( 'https://venue.example.com/tickets/show-three', $events[2]['source_url'] );
	}

	public function test_extracts_repeated_event_link_and_when_rows(): void {
		$html = file_get_contents( $this->fixture_dir . '/events-manager-list.html' );

		$this->assertTrue( $this->extractor->canExtract( $html ) );

		$events = $this->extractor->extract( $html, 'https://venue.example.com/calendar/' );

		$this->assertCount( 3, $events );
		$this->assertSame( 'First Event', $events[0]['title'] );
		$this->assertSame( '2099-07-28', $events[0]['startDate'] );
		$this->assertSame( 'https://venue.example.com/events/first-event/', $events[0]['source_url'] );
	}

	public function test_extracts_shopify_image_with_text_event_cards(): void {
		$html = file_get_contents( $this->fixture_dir . '/shopify-image-with-text-events.html' );

		$this->assertTrue( $this->extractor->canExtract( $html ) );
		$events = $this->extractor->extract( $html, 'https://cactus.example.com/pages/calendar' );

		$this->assertCount( 3, $events );
		$this->assertSame( 'Nitra Nicole 08/14', $events[0]['title'] );
		$this->assertSame( gmdate( 'Y' ) . '-08-14', $events[0]['startDate'] );
		$this->assertSame( '20:30', $events[0]['startTime'] );
		$this->assertSame( '$10.00', $events[0]['ticketPrice'] );
		$this->assertSame( 'https://cdn.example.com/nitra.jpg', $events[0]['imageUrl'] );
	}

	public function test_extracts_events_manager_resentitem_rows(): void {
		$html = file_get_contents( $this->fixture_dir . '/events-manager-resentitem-list.html' );

		$this->assertTrue( $this->extractor->canExtract( $html ) );
		$events = $this->extractor->extract( $html, 'https://venue.example.com/live-music/' );

		$this->assertCount( 3, $events );
		$this->assertSame( "The Illuman 80's", $events[0]['title'] );
		$this->assertSame( '2099-08-14', $events[0]['startDate'] );
		$this->assertSame( '20:30', $events[0]['startTime'] );
		$this->assertSame( 'https://venue.example.com/events/illuman-80s/', $events[0]['source_url'] );
	}

	public function test_omitted_year_rolls_january_into_next_year_from_december(): void {
		$method = ( new ReflectionClass( $this->extractor ) )->getMethod( 'inferYearForMonth' );
		$method->setAccessible( true );

		$this->assertSame( 2027, $method->invoke( $this->extractor, 1, 12, 2026 ) );
		$this->assertSame( 2026, $method->invoke( $this->extractor, 12, 1, 2026 ) );
		$this->assertSame( 2026, $method->invoke( $this->extractor, 8, 8, 2026 ) );
	}

	public function test_rejects_image_only_flyer_page(): void {
		$html = file_get_contents( $this->fixture_dir . '/image-only-flyers.html' );

		$this->assertFalse( $this->extractor->canExtract( $html ) );
		$this->assertSame( array(), $this->extractor->extract( $html, 'https://venue.example.com/flyers/' ) );
	}

	public function test_rejects_insufficient_semantic_event_evidence(): void {
		$html = '<div><p class="event-title">Only One</p><p class="event-date">Jul 28</p></div>';

		$this->assertFalse( $this->extractor->canExtract( $html ) );
		$this->assertSame( array(), $this->extractor->extract( $html, 'https://venue.example.com/' ) );
	}
}
