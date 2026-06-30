<?php
/**
 * Seated Extractor Tests
 *
 * Covers detection of the client-side Seated tour widget and extraction of
 * tour dates from the Seated CDN JSON:API. The artist's tour page ships an
 * empty placeholder div plus widget.seated.com loader; the events are fetched
 * from https://cdn.seated.com/api/tour/{artistId}?include=tour-events. We mock
 * `pre_http_request` to return a JSON:API payload shaped like the real API.
 *
 * Regression coverage for extrachill-events#403 (artist tour URL on
 * easyhoneymusic.com — a Seated-backed page — failed with "no events found").
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.41.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\SeatedExtractor;

class SeatedExtractorTest extends WP_UnitTestCase {

	private SeatedExtractor $extractor;

	private const ARTIST_ID = 'a4121ec3-4318-4372-9889-098c7cdf5f41';

	public function setUp(): void {
		parent::setUp();
		$this->extractor = new SeatedExtractor();
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────────────────
	// Detection
	// ────────────────────────────────────────────────────────────────────

	public function test_canExtract_detects_widget_script(): void {
		$html = '<html><body><div id="seated-55fdf2c0" data-artist-id="' . self::ARTIST_ID . '"></div>'
			. '<script src="https://widget.seated.com/app.js"></script></body></html>';
		$this->assertTrue( $this->extractor->canExtract( $html ) );
	}

	public function test_canExtract_detects_placeholder_div_only(): void {
		$html = '<html><body><div id="seated-deadbeef" data-artist-id="' . self::ARTIST_ID . '"></div></body></html>';
		$this->assertTrue( $this->extractor->canExtract( $html ) );
	}

	public function test_canExtract_rejects_non_seated(): void {
		$this->assertFalse( $this->extractor->canExtract( '<html><body>Plain page</body></html>' ) );
		$this->assertFalse( $this->extractor->canExtract( '' ) );
	}

	public function test_getMethod_identifier(): void {
		$this->assertSame( 'seated', $this->extractor->getMethod() );
	}

	// ────────────────────────────────────────────────────────────────────
	// Extraction
	// ────────────────────────────────────────────────────────────────────

	public function test_extract_returns_empty_when_no_artist_id(): void {
		// Widget loader present but no data-artist-id — nothing to query.
		$html = '<html><body><script src="https://widget.seated.com/app.js"></script></body></html>';
		$this->assertSame( array(), $this->extractor->extract( $html, 'https://artist.example/tour/' ) );
	}

	public function test_extract_parses_tour_events_from_api(): void {
		$this->mockSeatedApi( $this->sampleTourPayload() );

		$html   = '<html><body><div id="seated-55fdf2c0" data-artist-id="' . self::ARTIST_ID . '"></div></body></html>';
		$events = $this->extractor->extract( $html, 'https://easyhoneymusic.test/tour/' );

		$this->assertCount( 2, $events );

		$first = $events[0];
		// Tour name becomes the event title (Seated tour-events have no title).
		$this->assertSame( 'Easy Honey', $first['title'] );
		$this->assertSame( '2026-08-11', $first['startDate'] );
		$this->assertSame( 'Nikki Lopez', $first['venue'] );
		$this->assertSame( 'Philadelphia', $first['venueCity'] );
		$this->assertSame( 'PA', $first['venueState'] );
		$this->assertStringContainsString( 'link.seated.com', $first['ticketUrl'] );
		// We deliberately do not assert a local start time — Seated only gives
		// a UTC instant with no venue timezone, so startTime is left empty.
		$this->assertSame( '', $first['startTime'] );
	}

	public function test_extract_uses_local_date_not_utc_drift(): void {
		// Event whose UTC instant rolls to the next calendar day must keep the
		// pre-localized `starts-at-date-local`.
		$payload = array(
			'data'     => array(
				'type'       => 'tours',
				'id'         => self::ARTIST_ID,
				'attributes' => array( 'name' => 'Night Owl' ),
			),
			'included' => array(
				array(
					'type'       => 'tour-events',
					'id'         => 'evt-late',
					'attributes' => array(
						'starts-at'            => '2026-09-02T03:00:00Z', // 11pm Sep 1 local
						'starts-at-date-local' => '2026-09-01',
						'is-starts-at-known'   => true,
						'venue-name'           => 'The Basement',
						'formatted-address'    => 'Nashville, TN',
					),
				),
			),
		);
		$this->mockSeatedApi( $payload );

		$html   = '<html><body><div id="seated-x" data-artist-id="' . self::ARTIST_ID . '"></div></body></html>';
		$events = $this->extractor->extract( $html, 'https://nightowl.test/tour/' );

		$this->assertCount( 1, $events );
		$this->assertSame( '2026-09-01', $events[0]['startDate'], 'Must use local date, not the next-day UTC date.' );
	}

	public function test_extract_returns_empty_on_api_failure(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'boom' );
			},
			10
		);

		$html   = '<html><body><div id="seated-x" data-artist-id="' . self::ARTIST_ID . '"></div></body></html>';
		$this->assertSame( array(), $this->extractor->extract( $html, 'https://artist.test/tour/' ) );
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Mock the Seated CDN tour API to return the given JSON:API payload.
	 *
	 * @param array $payload Decoded JSON:API tour payload.
	 */
	private function mockSeatedApi( array $payload ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $payload ) {
				if ( false !== strpos( $url, 'cdn.seated.com/api/tour/' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( $payload ),
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

	/**
	 * A representative two-event Seated tour payload (shape mirrors the real
	 * cdn.seated.com response for easyhoneymusic.com).
	 *
	 * @return array
	 */
	private function sampleTourPayload(): array {
		return array(
			'data'     => array(
				'type'       => 'tours',
				'id'         => self::ARTIST_ID,
				'attributes' => array(
					'name'      => 'Easy Honey',
					'image-url' => 'https://example.com/easyhoney.jpg',
				),
			),
			'included' => array(
				array(
					'type'       => 'tour-events',
					'id'         => '01af6ae8-6c62-4ffc-ae7d-82c126e5ccfa',
					'attributes' => array(
						'starts-at'            => '2026-08-11T23:00:00Z',
						'starts-at-date-local' => '2026-08-11',
						'is-starts-at-known'   => true,
						'venue-name'           => 'Nikki Lopez',
						'formatted-address'    => 'Philadelphia, PA',
					),
				),
				array(
					'type'       => 'tour-events',
					'id'         => '2ab8b28c-e829-4d33-b8a3-2a875b53f05d',
					'attributes' => array(
						'starts-at'            => '2026-08-28T23:00:00Z',
						'starts-at-date-local' => '2026-08-28',
						'is-starts-at-known'   => true,
						'venue-name'           => 'New World Music Hall',
						'formatted-address'    => 'Tampa, FL',
					),
				),
			),
		);
	}
}
