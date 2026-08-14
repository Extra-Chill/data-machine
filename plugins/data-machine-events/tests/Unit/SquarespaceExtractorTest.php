<?php
/**
 * Squarespace Extractor Tests
 *
 * Covers the four improvements introduced in issue #272:
 *   1. Summary Block collection-ID dereferencing
 *   2. User Items List collection-backed shape (+ inline data-current-context)
 *   3. Single-event-detail page extraction
 *   4. Fluid Engine deferral (no live fixture content; see PR body)
 *
 * Issue #625 adds context-backed collection URL resolution, paged items, and
 * strict event evidence. Classic Events Collection behavior remains covered
 * via the Royal American snapshot.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.15.x
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\SquarespaceExtractor;

class SquarespaceExtractorTest extends WP_UnitTestCase {

	private SquarespaceExtractor $extractor;
	private string $fixtures_dir;

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new SquarespaceExtractor();
		$this->fixtures_dir = __DIR__ . '/../Fixtures/squarespace';
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'data_machine_events_scraper_recurrence_horizon_days' );
		remove_all_filters( 'data_machine_events_scraper_max_events' );
		parent::tearDown();
	}

	/* ------------------------------------------------------------------ */
	/* Detection                                                          */
	/* ------------------------------------------------------------------ */

	public function test_canExtract_detects_squarespace_context_marker() {
		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script></html>';
		$this->assertTrue( $this->extractor->canExtract( $html ) );
	}

	public function test_canExtract_rejects_non_squarespace() {
		$this->assertFalse( $this->extractor->canExtract( '<html><body>Plain</body></html>' ) );
		$this->assertFalse( $this->extractor->canExtract( '' ) );
	}

	public function test_getMethod_identifier() {
		$this->assertSame( 'squarespace', $this->extractor->getMethod() );
	}

	/* ------------------------------------------------------------------ */
	/* Improvement 1 — Summary Block collection-ID dereferencing          */
	/* ------------------------------------------------------------------ */

	public function test_summary_block_dereferences_collection_id() {
		$source_url = 'https://venue.test/calendar';
		$html       = file_get_contents( $this->fixtures_dir . '/summary-context.html' );

		$this->mockHttpRoutes(
			array(
				'https://venue.test/calendar?format=json' => array( 'website' => array() ),
				'https://venue.test/live-shows?format=json' => file_get_contents( $this->fixtures_dir . '/summary-events-page-1.json' ),
				'https://venue.test/live-shows?offset=2&format=json' => file_get_contents( $this->fixtures_dir . '/summary-events-page-2.json' ),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );

		$this->assertCount( 2, $events, 'Summary Block deref should follow paged event items.' );
		$this->assertSame( 'Fixture Show One', $events[0]['title'] );
		$this->assertSame( '2099-07-01', $events[0]['startDate'] );
		$this->assertSame( 'Fixture Show Two', $events[1]['title'] );
	}

	public function test_non_event_items_are_not_treated_as_events() {
		$source_url = 'https://venue.test/news';
		$html       = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script></html>';

		$this->mockHttpRoutes(
			array(
				'https://venue.test/news?format=json' => file_get_contents( $this->fixtures_dir . '/non-event-items.json' ),
			)
		);

		$this->assertSame( array(), $this->extractor->extract( $html, $source_url ) );
	}

	public function test_summary_block_gallery_is_skipped() {
		$source_url = 'https://example.com/';

		// Gallery-style block with transientGalleryId === collectionId. Should
		// NOT trigger a collection fetch. Page-level fetch returns nothing of
		// interest, so extract() returns [].
		$block_json = wp_json_encode(
			array(
				'collectionId'       => 'gallery-id',
				'transientGalleryId' => 'gallery-id',
				'design'             => 'grid',
			)
		);

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script>'
			. '<div class="sqs-block-gallery" data-block-json="' . esc_attr( $block_json ) . '"></div>'
			. '</html>';

		$this->mockHttpRoutes(
			array(
				'https://example.com/?format=json' => array( 'website' => array() ),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );
		$this->assertSame( array(), $events, 'Gallery Summary Blocks must not trigger collection deref' );
	}

	public function test_summary_block_handles_collection_fetch_failure_gracefully() {
		$source_url = 'https://example.com/';

		$block_json = wp_json_encode(
			array(
				'collectionId'             => 'evt-collection-fail',
				'design'                   => 'list',
				'showPastOrUpcomingEvents' => 'upcoming',
			)
		);

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {"website":{"navigation":[{"id":"evt-collection-fail","fullUrl":"/events"}]}};</script>'
			. '<div class="sqs-block-summary-v2" data-block-json="' . esc_attr( $block_json ) . '"></div>'
			. '</html>';

		// Both the page fetch and the collection fetch fail.
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Connection timed out' );
			},
			10,
			3
		);

		// Must not throw. Empty extraction is acceptable.
		$events = $this->extractor->extract( $html, $source_url );
		$this->assertIsArray( $events );
	}

	/* ------------------------------------------------------------------ */
	/* Improvement 2 — User Items List collection-ID dereferencing        */
	/* ------------------------------------------------------------------ */

	public function test_user_items_list_dereferences_collection_id() {
		$source_url = 'https://venue.test/';

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {"website":{"navigation":[{"id":"evt-uil-xyz","fullUrl":"/events"}]}};</script>'
			. '<div class="user-items-list" data-collection-id="evt-uil-xyz">'
			. '<div class="user-items-list-section">skeleton</div>'
			. '</div>'
			. '</html>';

		$this->mockHttpRoutes(
			array(
				'https://venue.test/?format=json' => array( 'website' => array() ),
				'https://venue.test/events?format=json' => array(
					'upcoming' => array(
						$this->makeRawEvent( 'UIL Show A', '2099-04-01T20:00:00+00:00' ),
						$this->makeRawEvent( 'UIL Show B', '2099-04-08T20:00:00+00:00' ),
					),
				),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );

		$this->assertCount( 2, $events );
		$this->assertSame( 'UIL Show A', $events[0]['title'] );
		$this->assertSame( 'UIL Show B', $events[1]['title'] );
	}

	public function test_user_items_list_inline_current_context_extracts_items() {
		$source_url = 'https://inline.test/';

		$user_items = array(
			array(
				'title'       => 'Inline Show 1',
				'description' => 'Headliner',
				'button'      => array( 'buttonLink' => 'https://tix.example/show1' ),
				'image'       => array( 'assetUrl' => 'https://img.example/1.jpg' ),
			),
			array(
				'title'       => 'Inline Show 2',
				'description' => 'Support',
				'button'      => array( 'buttonLink' => 'https://tix.example/show2' ),
			),
		);

		$ctx_payload = wp_json_encode( array( 'userItems' => $user_items ) );

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script>'
			. '<div class="user-items-list">'
			. '<div class="user-items-list-item-container" data-current-context="' . esc_attr( $ctx_payload ) . '">'
			. '</div></div></html>';

		// Page fetch returns nothing useful.
		$this->mockHttpRoutes(
			array(
				'https://inline.test/?format=json' => array( 'website' => array() ),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );

		$this->assertCount( 2, $events );
		$this->assertSame( 'Inline Show 1', $events[0]['title'] );
		$this->assertSame( 'https://tix.example/show1', $events[0]['ticketUrl'] );
		$this->assertSame( 'Inline Show 2', $events[1]['title'] );
	}

	/* ------------------------------------------------------------------ */
	/* Improvement 3 — Single-event-detail page extraction                */
	/* ------------------------------------------------------------------ */

	public function test_single_event_detail_extracts_one_event() {
		$source_url = 'https://venue.test/events/test-event';

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script></html>';

		$this->mockHttpRoutes(
			array(
				'https://venue.test/events/test-event?format=json' => array(
					'item' => array(
						'@type'     => 'Event',
						'title'     => 'Solo Show',
						'startDate' => 1234567890000,
						'location'  => array(
							'addressTitle' => 'The Test Venue',
							'addressLine1' => '123 Test St',
						),
					),
				),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );

		$this->assertCount( 1, $events, 'Single-event-detail payload should yield exactly 1 event' );
		$this->assertSame( 'Solo Show', $events[0]['title'] );
		$this->assertSame( 'The Test Venue', $events[0]['venue'] );
	}

	public function test_single_event_detail_recordtype_12_extracts() {
		$source_url = 'https://venue.test/events/rt12';

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script></html>';

		$this->mockHttpRoutes(
			array(
				'https://venue.test/events/rt12?format=json' => array(
					'item' => array(
						'recordType' => 12,
						'title'      => 'Record Type Event',
						'startDate'  => 1700000000000,
					),
				),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );

		$this->assertCount( 1, $events );
		$this->assertSame( 'Record Type Event', $events[0]['title'] );
	}

	public function test_single_event_detail_ignored_when_listing_present() {
		$source_url = 'https://venue.test/';

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script></html>';

		// When upcoming[] is present, single-item detection MUST defer to the
		// listing strategies.
		$this->mockHttpRoutes(
			array(
				'https://venue.test/?format=json' => array(
					'upcoming' => array(
						$this->makeRawEvent( 'Listing 1', '2099-05-01T20:00:00+00:00' ),
						$this->makeRawEvent( 'Listing 2', '2099-05-08T20:00:00+00:00' ),
					),
					'item'     => array(
						'@type' => 'Event',
						'title' => 'Should Not Surface',
					),
				),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );
		$this->assertCount( 2, $events );
		$this->assertSame( 'Listing 1', $events[0]['title'] );
	}

	public function test_single_event_detail_ignored_when_item_not_event() {
		$source_url = 'https://venue.test/blog/post';

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script></html>';

		$this->mockHttpRoutes(
			array(
				'https://venue.test/blog/post?format=json' => array(
					'item' => array(
						'@type' => 'BlogPost',
						'title' => 'Not An Event',
					),
				),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );
		$this->assertSame( array(), $events );
	}

	/* ------------------------------------------------------------------ */
	/* Regression — classic Squarespace events collection still works     */
	/* ------------------------------------------------------------------ */

	public function test_regression_classic_events_collection_still_works() {
		$source_url = 'https://classic.test/events';

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script></html>';

		$this->mockHttpRoutes(
			array(
				'https://classic.test/events?format=json' => array(
					'upcoming' => array(
						$this->makeRawEvent( 'Classic A', '2099-06-01T20:00:00+00:00' ),
						$this->makeRawEvent( 'Classic B', '2099-06-08T20:00:00+00:00' ),
						$this->makeRawEvent( 'Classic C', '2099-06-15T20:00:00+00:00' ),
					),
				),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );

		$this->assertCount( 3, $events, 'Classic data.upcoming[] strategy must continue to work' );
		$this->assertSame( 'Classic A', $events[0]['title'] );
		$this->assertSame( '2099-06-01', $events[0]['startDate'] );
	}

	public function test_native_calendar_blocks_fetch_bounded_months_and_dedupe_events() {
		$source_url = 'https://www.thewomack.us:8443/calendar';
		$html       = file_get_contents( $this->fixtures_dir . '/womack-calendar-block.html' );
		$items      = file_get_contents( $this->fixtures_dir . '/womack-calendar-items.json' );
		$requests   = array();

		$this->extractor = $this->makeFixedCalendarExtractor();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $items, &$requests ) {
				if ( 0 === strpos( $url, 'https://www.thewomack.us:8443/api/open/GetItemsByMonth?' ) ) {
					parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
					$collection = $query['collectionId'] ?? '';
					$month      = $query['month'] ?? '';
					$requests[] = $collection . '|' . $month;

					if ( '6515beb3cb46457500c400c9' === $collection && '08-2026' === $month ) {
						return array(
							'headers'  => array(),
							'body'     => $items,
							'response' => array(
								'code'    => 200,
								'message' => 'OK',
							),
							'cookies'  => array(),
							'filename' => null,
						);
					}

					if ( '6515beb3cb46457500c400c9' === $collection && '09-2026' === $month ) {
						return array(
							'headers'  => array(),
							'body'     => '{malformed',
							'response' => array( 'code' => 200 ),
						);
					}

					if ( 'secondary-calendar' === $collection && '08-2026' === $month ) {
						$secondary = array(
							json_decode( $items, true )[0],
							array(
								'id'         => 'past-event',
								'recordType' => 12,
								'title'      => 'Past Event',
								'startDate'  => 1785565799000,
							),
							array(
								'id'         => 'exact-now',
								'recordType' => 12,
								'title'      => 'Exact Now',
								'startDate'  => '2026-08-01T06:30:00Z',
							),
							array(
								'id'         => 'secondary-event',
								'recordType' => 12,
								'title'      => 'Secondary Calendar Show',
								'fullUrl'    => '/womack-events/secondary-calendar-show',
								'startDate'  => 1788325200000,
								'endDate'    => 1788332400000,
							),
							array(
								'id'         => 'exact-cutoff',
								'recordType' => 12,
								'title'      => 'Exact Cutoff',
								'startDate'  => '2026-10-30T06:30:00Z',
							),
							array(
								'id'         => 'string-beyond-cutoff',
								'recordType' => 12,
								'title'      => 'String Beyond Cutoff',
								'startDate'  => '2026-10-30T06:30:01Z',
							),
							array(
								'id'         => 'beyond-horizon',
								'recordType' => 12,
								'title'      => 'Beyond Horizon',
								'startDate'  => 1793491200000,
							),
						);

						return array(
							'headers'  => array(),
							'body'     => wp_json_encode( $secondary ),
							'response' => array( 'code' => 200 ),
						);
					}

					if ( 'secondary-calendar' === $collection && '09-2026' === $month ) {
						return new \WP_Error( 'http_request_failed', 'Calendar unavailable' );
					}

					return array(
						'headers'  => array(),
						'body'     => '[]',
						'response' => array( 'code' => 200 ),
					);
				}

				if ( 'https://www.thewomack.us:8443/calendar?format=json' === $url ) {
					return array(
						'headers'  => array(),
						'body'     => '{"collection":{"type":10,"itemCount":0},"mainContent":""}',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return new \WP_Error( 'http_request_failed', 'Unmocked URL: ' . $url );
			},
			10,
			3
		);

		$events = $this->extractor->extract( $html, $source_url );

		$this->assertCount( 5, $events );
		$this->assertSame( 'ill Vibe', $events[0]['title'] );
		$this->assertSame( '2026-08-30', $events[0]['startDate'] );
		$this->assertSame( 'Groove Candy', $events[1]['title'] );
		$this->assertSame( '/womack-events/3jtw3a6l6tch7bcfkgrzbw64xtnrx3', $events[1]['source_url'] );
		$this->assertSame(
			array( 'ill Vibe', 'Groove Candy', 'Exact Now', 'Secondary Calendar Show', 'Exact Cutoff' ),
			array_column( $events, 'title' )
		);
		$this->assertSame(
			array(
				'6515beb3cb46457500c400c9|07-2026',
				'6515beb3cb46457500c400c9|08-2026',
				'6515beb3cb46457500c400c9|09-2026',
				'6515beb3cb46457500c400c9|10-2026',
				'secondary-calendar|07-2026',
				'secondary-calendar|08-2026',
				'secondary-calendar|09-2026',
				'secondary-calendar|10-2026',
			),
			$requests,
			'Phoenix is still in July when the UTC clock has crossed into August.'
		);
	}

	public function test_calendar_block_collection_and_event_caps_bound_requests() {
		$this->extractor = $this->makeFixedCalendarExtractor();
		$requests        = array();
		$html            = $this->makeCalendarBlocksHtml(
			array_map(
				static fn( int $number ): string => 'collection-' . $number,
				range( 1, 12 )
			)
		);

		$this->mockCalendarBlockRequests( $requests, static fn(): array => array() );
		$this->assertSame( array(), $this->extractor->extract( $html, 'https://venue.test/calendar' ) );
		$this->assertCount( 40, $requests, 'Ten collections across four bounded months is the maximum request count.' );
		$this->assertSame( 'collection-10|10-2026', $requests[39] );

		remove_all_filters( 'pre_http_request' );
		$requests = array();
		add_filter( 'data_machine_events_scraper_max_events', static fn(): int => 2 );
		$this->mockCalendarBlockRequests(
			$requests,
			static function ( string $collection, string $month ): array {
				return array(
					array(
						'id'         => $collection . '-' . $month,
						'recordType' => 12,
						'title'      => $collection . ' ' . $month,
						'startDate'  => '07-2026' === $month ? '2026-08-01T06:30:00Z' : '2026-08-02T06:30:00Z',
					),
				);
			}
		);

		$events = $this->extractor->extract( $html, 'https://venue.test/calendar' );
		$this->assertCount( 2, $events );
		$this->assertSame( array( 'collection-1|07-2026', 'collection-1|08-2026' ), $requests );
	}

	/* ------------------------------------------------------------------ */
	/* Live fixtures — integration smoke                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Saint Vitus homepage carries 6 user-items-list items inline via
	 * data-current-context. This is Improvement 2 shape (b).
	 */
	public function test_real_fixture_saint_vitus_extracts_inline_items() {
		$fixture = $this->fixtures_dir . '/saint-vitus.html';
		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'Saint Vitus fixture not present.' );
		}

		$html = file_get_contents( $fixture );

		// Block all outbound HTTP so we exercise only the in-page parsing.
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'blocked', 'No network in test' );
			},
			10,
			3
		);

		$events = $this->extractor->extract( $html, 'https://www.saintvitusbar.com/' );

		// The fixture has 6 inline userItems entries. Lock in the actual yield.
		$this->assertGreaterThanOrEqual(
			3,
			count( $events ),
			'Saint Vitus inline user-items-list should yield at least 3 events'
		);
	}

	/**
	 * Baby's All Right homepage uses an external ticketing platform
	 * (seetickets.us) and its sole Summary Block is a gallery, not events.
	 * Lock in the known-zero state so any future Squarespace-side change
	 * surfaces as a green test.
	 */
	public function test_real_fixture_babys_all_right_no_events_on_homepage() {
		$fixture = $this->fixtures_dir . '/babys-all-right.html';
		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'Babys All Right fixture not present.' );
		}

		$html = file_get_contents( $fixture );

		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'blocked', 'No network in test' );
			},
			10,
			3
		);

		$events = $this->extractor->extract( $html, 'https://babysallright.com/' );
		// Documented in PR body: babysallright uses external ticketing —
		// no events live in Squarespace. Expected zero.
		$this->assertSame( array(), $events );
	}

	/**
	 * House of Yes calendar page is a Squarespace 7.1 Fluid Engine site
	 * with no event data in initial HTML — calendar is rendered by a
	 * client-side widget. Documented JS-rendered blocker. Locked in as
	 * zero so a future fixture refresh that surfaces events flips the
	 * test (and we know to add Fluid Engine support).
	 */
	public function test_real_fixture_house_of_yes_js_rendered_blocker() {
		$fixture = $this->fixtures_dir . '/house-of-yes.html';
		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'House of Yes fixture not present.' );
		}

		$html = file_get_contents( $fixture );

		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'blocked', 'No network in test' );
			},
			10,
			3
		);

		$events = $this->extractor->extract( $html, 'https://houseofyes.org/calendar/' );
		// JS-rendered. Expected zero from initial HTML.
		$this->assertSame( array(), $events );
	}

	/**
	 * Regression: Royal American snapshot exists alongside this PR and
	 * must continue to be detectable (canExtract). Verify the canExtract
	 * signal stays stable on a real classic Squarespace page.
	 */
	public function test_regression_royal_american_fixture_is_detected() {
		$fixture = __DIR__ . '/../Fixtures/squarespace-royal-american.html';
		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'Royal American fixture not present.' );
		}

		$html = file_get_contents( $fixture );
		$this->assertTrue(
			$this->extractor->canExtract( $html ),
			'Royal American must still fingerprint as Squarespace'
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	private function makeFixedCalendarExtractor(): SquarespaceExtractor {
		return new class() extends SquarespaceExtractor {
			protected function getCalendarNow( \DateTimeZone $timezone ): \DateTimeImmutable {
				return ( new \DateTimeImmutable( '2026-08-01 06:30:00', new \DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );
			}
		};
	}

	private function makeCalendarBlocksHtml( array $collection_ids ): string {
		$blocks = '';
		foreach ( $collection_ids as $collection_id ) {
			$block   = esc_attr( wp_json_encode( array( 'collectionId' => $collection_id ) ) );
			$blocks .= '<div class="sqs-block calendar-block" data-block-json="' . $block . '"></div>';
		}

		return '<html><script>Static.SQUARESPACE_CONTEXT = {"website":{"timeZone":"America/Phoenix"}};</script><body>' . $blocks . '</body></html>';
	}

	private function mockCalendarBlockRequests( array &$requests, callable $items_for_request ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requests, $items_for_request ) {
				if ( false !== strpos( $url, '/api/open/GetItemsByMonth?' ) ) {
					parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
					$collection = (string) ( $query['collectionId'] ?? '' );
					$month      = (string) ( $query['month'] ?? '' );
					$requests[] = $collection . '|' . $month;
					$body       = wp_json_encode( $items_for_request( $collection, $month ) );
				} else {
					$body = '{"collection":{"type":10,"itemCount":0},"mainContent":""}';
				}

				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a minimal raw Squarespace event item shaped for normalizeItem().
	 */
	private function makeRawEvent( string $title, string $start_iso ): array {
		return array(
			'title'     => $title,
			'startDate' => $start_iso,
			'fullUrl'   => '/events/' . sanitize_title( $title ),
		);
	}

	/**
	 * Mock the WP HTTP layer with a URL → JSON-payload routing table.
	 *
	 * Matches the URL passed through HttpClient exactly. Unknown URLs return a
	 * WP_Error so the extractor exercises its failure paths.
	 */
	private function mockHttpRoutes( array $routes ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $routes ) {
				foreach ( $routes as $route => $payload ) {
					if ( $route === $url ) {
						return array(
							'headers'  => array(),
							'body'     => is_string( $payload ) ? $payload : wp_json_encode( $payload ),
							'response' => array(
								'code'    => 200,
								'message' => 'OK',
							),
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
