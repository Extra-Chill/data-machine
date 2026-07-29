<?php
/**
 * Eventbrite extractor regression tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\EventbriteExtractor;
use WP_UnitTestCase;

class EventbriteExtractorTest extends WP_UnitTestCase {

	private EventbriteExtractor $extractor;
	private string $fixtures_dir;

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new EventbriteExtractor();
		$this->fixtures_dir = __DIR__ . '/../Fixtures/eventbrite';
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	public function test_extracts_public_organizer_next_data(): void {
		$html   = $this->fixture( 'organizer.html' );
		$events = $this->extractor->extract( $html, 'https://www.eventbrite.com/o/example-room-123456789' );

		$this->assertTrue( $this->extractor->canExtract( $html ) );
		$this->assertCount( 1, $events, 'Protected organizer events must remain unresolved.' );
		$this->assertSame( 'Example Room Presents', $events[0]['title'] );
		$this->assertSame( '2099-08-02', $events[0]['startDate'] );
		$this->assertSame( '19:30', $events[0]['startTime'] );
		$this->assertSame( 'America/New_York', $events[0]['venueTimezone'] );
		$this->assertSame( 'https://www.eventbrite.com/e/example-room-presents-tickets-987654321', $events[0]['ticketUrl'] );
		$this->assertSame( '$15.00 - $25.00', $events[0]['price'] );
		$this->assertSame( '33.1,-83.2', $events[0]['venueCoordinates'] );
	}

	public function test_omits_equal_organizer_listing_end(): void {
		$events = $this->extractor->extract(
			$this->fixture( 'lofi-missing-end.html' ),
			'https://www.eventbrite.com/o/lo-fi-brewing-14959647606'
		);

		$this->assertCount( 1, $events );
		$this->assertSame( 'CHARLESTON MINI FOLK FEST', $events[0]['title'] );
		$this->assertSame( '2099-08-08', $events[0]['startDate'] );
		$this->assertSame( '19:00', $events[0]['startTime'] );
		$this->assertArrayNotHasKey( 'endDate', $events[0] );
		$this->assertArrayNotHasKey( 'endTime', $events[0] );
	}

	public function test_omits_missing_empty_invalid_and_equal_json_ld_ends(): void {
		$ends = array( null, '', 'not-a-date', '2099-08-08T19:00:00-04:00' );

		foreach ( $ends as $index => $end ) {
			$event = array(
				'@context'  => 'https://schema.org',
				'@type'     => 'Event',
				'name'      => 'Unknown Duration ' . $index,
				'startDate' => '2099-08-08T19:00:00-04:00',
				'url'       => 'https://www.eventbrite.com/e/unknown-duration-' . $index,
			);
			if ( null !== $end ) {
				$event['endDate'] = $end;
			}

			$html      = '<script type="application/ld+json">' . wp_json_encode( $event ) . '</script>';
			$extracted = $this->extractor->extract( $html, $event['url'] );

			$this->assertCount( 1, $extracted );
			$this->assertArrayNotHasKey( 'endDate', $extracted[0] );
			$this->assertArrayNotHasKey( 'endTime', $extracted[0] );
		}
	}

	public function test_preserves_positive_and_overnight_json_ld_ends(): void {
		$events = array(
			array(
				'@context'  => 'https://schema.org',
				'@type'     => 'Event',
				'name'      => 'Positive Duration',
				'startDate' => '2099-08-08T19:00:00-04:00',
				'endDate'   => '2099-08-08T22:00:00-04:00',
				'url'       => 'https://www.eventbrite.com/e/positive-duration',
			),
			array(
				'@context'  => 'https://schema.org',
				'@type'     => 'Event',
				'name'      => 'Overnight Duration',
				'startDate' => '2099-08-08T23:00:00-04:00',
				'endDate'   => '2099-08-09T02:00:00-04:00',
				'url'       => 'https://www.eventbrite.com/e/overnight-duration',
			),
		);
		$html   = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@type'           => 'ItemList',
				'itemListElement' => $events,
			)
		) . '</script>';

		$extracted = $this->extractor->extract( $html, 'https://www.eventbrite.com/o/example-123' );

		$this->assertSame( '2099-08-08', $extracted[0]['endDate'] );
		$this->assertSame( '22:00', $extracted[0]['endTime'] );
		$this->assertSame( '2099-08-09', $extracted[1]['endDate'] );
		$this->assertSame( '02:00', $extracted[1]['endTime'] );
	}

	public function test_discovers_canonical_organizer_and_event_links(): void {
		$routes = array(
			'https://www.eventbrite.com/o/example-room-123456789'             => $this->fixture( 'organizer.html' ),
			'https://www.eventbrite.com/e/direct-listing-tickets-123456789' => $this->fixture( 'event.html' ),
		);
		$requested = array();
		$this->mockHttpRoutes( $routes, $requested );

		$html   = $this->fixture( 'venue-links.html' );
		$events = $this->extractor->extract( $html, 'https://venue.example.test/events' );

		$this->assertCount( 2, $events );
		$this->assertSame( array_keys( $routes ), $requested );
		$this->assertSame( array( 'Example Room Presents', 'Direct Listing' ), array_column( $events, 'title' ) );
	}

	public function test_documented_checkout_widget_event_id_is_actionable(): void {
		$html = '<script src="https://www.eventbrite.com/static/widgets/eb_widgets.js"></script>'
			. '<script>EBWidgets.createWidget({widgetType: "checkout", eventId: "123456789"});</script>';
		$requested = array();
		$this->mockHttpRoutes(
			array( 'https://www.eventbrite.com/e/123456789' => $this->fixture( 'event.html' ) ),
			$requested
		);

		$this->assertTrue( $this->extractor->canExtract( $html ) );
		$this->assertCount( 1, $this->extractor->extract( $html, 'https://venue.example.test/events' ) );
		$this->assertSame( array( 'https://www.eventbrite.com/e/123456789' ), $requested );
	}

	public function test_incidental_eventbrite_references_are_not_actionable(): void {
		$html = '<a href="https://www.eventbrite.com/about/">About Eventbrite</a>'
			. '<script src="https://www.eventbrite.com/static/widgets/eb_widgets.js"></script>';

		$this->assertFalse( $this->extractor->canExtract( $html ) );
		$this->assertSame( array(), $this->extractor->extract( $html, 'https://venue.example.test/events' ) );
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( $this->fixtures_dir . '/' . $name );
	}

	private function mockHttpRoutes( array $routes, array &$requested ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $routes, &$requested ) {
				$requested[] = $url;
				if ( ! isset( $routes[ $url ] ) ) {
					return new \WP_Error( 'http_request_failed', 'Unexpected URL: ' . $url );
				}

				return array(
					'headers'  => array(),
					'body'     => $routes[ $url ],
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}
}
