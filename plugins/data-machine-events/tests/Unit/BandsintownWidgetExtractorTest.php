<?php
/**
 * Bandsintown widget detection, requests and normalized packets.
 *
 * @package DataMachineEvents\Tests
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\BandsintownWidgetExtractor;
use WP_UnitTestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Local HTML fixtures, not rendered resources.

/** Widget regression coverage. */
class BandsintownWidgetExtractorTest extends WP_UnitTestCase {

	/**
	 * Read a trimmed live fixture.
	 *
	 * @param string $name Fixture filename.
	 * @return string Fixture content.
	 */
	private function fixture( string $name ): string {
		return file_get_contents( __DIR__ . '/../Fixtures/bandsintown/' . $name );
	}

	/**
	 * Extract against a mocked API, verifying the widget request.
	 *
	 * @param string $html         Page HTML.
	 * @param string $body         API response.
	 * @param string $expected_url Expected endpoint.
	 * @param int    $status       HTTP status.
	 * @return array Events.
	 */
	private function extract( string $html, string $body, string $expected_url, int $status = 200 ): array {
		$mock = function ( $preempt, $args, $url ) use ( $body, $expected_url, $status ) {
			$this->assertSame( $expected_url, $url );
			$this->assertLessThanOrEqual( 20, $args['timeout'] );
			return array(
				'response' => array(
					'code'    => $status,
					'message' => 'Test',
				),
				'body'     => $body,
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			return ( new BandsintownWidgetExtractor() )->extract( $html, 'https://www.lightwatchband.com/tour/' );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}

	/** Test raw and escaped detection without unrelated attribute matches. */
	public function test_detection_requires_initializer_and_artist_on_same_tag(): void {
		$extractor = new BandsintownWidgetExtractor();
		$this->assertTrue( $extractor->canExtract( $this->fixture( 'tounds.html' ) ) );
		$this->assertTrue( $extractor->canExtract( $this->fixture( 'lightwatch.html' ) ) );
		$this->assertTrue( $extractor->canExtract( $this->fixture( 'lightwatch-srcdoc.html' ) ) );
		foreach ( array( '', '<script src="https://widgetv3.bandsintown.com/main.min.js"></script>', '<a class="bit-widget-initializer"></a><div data-artist-name="Other"></div>' ) as $html ) {
			$this->assertFalse( $extractor->canExtract( $html ) );
			$this->assertSame( array(), $extractor->extract( $html, 'https://example.com' ) );
		}
	}

	/** Test local dates, stable identity, full bill and venue fields. */
	public function test_escaped_widget_falls_back_to_page_hostname_and_normalizes_festival(): void {
		$events = $this->extract(
			$this->fixture( 'lightwatch.html' ),
			$this->fixture( 'lightwatch.json' ),
			'https://rest.bandsintown.com/V4/artists/id_15528341/events/?app_id=js_www.lightwatchband.com'
		);
		$this->assertCount( 2, $events );
		$this->assertSame( 'Lightwatch', $events[0]['title'] );
		$event = $events[1];
		foreach ( array(
			'title'            => 'Boogieman Halloween Festival 2026',
			'startDate'        => '2026-10-31',
			'startTime'        => '19:00',
			'endTime'          => '23:30',
			'venue'            => 'The Royal American',
			'venueAddress'     => '970 Morrison Dr',
			'venueCity'        => 'Charleston',
			'venueState'       => 'SC',
			'venueZip'         => '29403',
			'venueCountry'     => 'United States',
			'venueCoordinates' => '32.806726,-79.94198279999999',
			'sourceId'         => '108764928',
			'source_url'       => 'https://www.bandsintown.com/e/108764928',
			'ticketUrl'        => 'https://www.bandsintown.com/e/108764928',
		) as $field => $expected ) {
			$this->assertSame( $expected, $event[ $field ], $field );
		}
		$this->assertCount( 8, $event['artists'] );
		$this->assertSame( array( 'title' => 'The Ocho' ), $event['artists'][7] );
	}

	/** Test the GoDaddy iframe srcdoc embed resolves the same widget request. */
	public function test_iframe_srcdoc_widget_falls_back_to_page_hostname(): void {
		$events = $this->extract(
			$this->fixture( 'lightwatch-srcdoc.html' ),
			$this->fixture( 'lightwatch.json' ),
			'https://rest.bandsintown.com/V4/artists/id_15528341/events/?app_id=js_www.lightwatchband.com'
		);
		$this->assertCount( 2, $events );
		$this->assertSame( '108764928', $events[1]['sourceId'] );
	}

	/** Test that explicit widget configuration overrides the page host. */
	public function test_explicit_app_id_and_first_ticket_offer(): void {
		$events = $this->extract(
			$this->fixture( 'tounds.html' ),
			$this->fixture( 'tounds.json' ),
			'https://rest.bandsintown.com/V4/artists/id_15606145/events/?app_id=422521dfda711e8d4b0f20b98fc0e6a6'
		);
		$this->assertCount( 2, $events );
		$this->assertSame( 'St. Petersburg', $events[1]['venueCity'] );
		$this->assertSame( '108920567', $events[0]['sourceId'] );
		$this->assertStringContainsString( '/t/108920567?', $events[0]['ticketUrl'] );
	}

	/** Test names, empty app ids, empty responses and failures. */
	public function test_plain_artist_name_is_encoded_and_bad_responses_are_empty(): void {
		$html = '<a data-artist-name="A &amp; B" class="extra bit-widget-initializer" data-app-id=""></a>';
		$url  = 'https://rest.bandsintown.com/V4/artists/A%20%26%20B/events/?app_id=js_www.lightwatchband.com';
		foreach ( array( '[]', '{}', 'invalid', '{"error":"not found"}' ) as $body ) {
			$this->assertSame( array(), $this->extract( $html, $body, $url ) );
		}
		$this->assertSame( array(), $this->extract( $html, '[]', $url, 500 ) );
	}
}
