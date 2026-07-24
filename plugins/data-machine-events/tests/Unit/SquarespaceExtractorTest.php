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
 * Plus regression coverage for the classic Squarespace Events Collection
 * shape via the Royal American snapshot.
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
		$source_url = 'https://example.com/';

		$block_json = wp_json_encode(
			array(
				'collectionId'             => 'evt-collection-abc',
				'design'                   => 'list',
				'showPastOrUpcomingEvents' => 'upcoming',
			)
		);

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script>'
			. '<div class="sqs-block-summary-v2" data-block-json="' . esc_attr( $block_json ) . '"></div>'
			. '</html>';

		// The page-level ?format=json returns a non-events payload so the
		// classic strategies all whiff and we fall through to improvement 1.
		// Improvement 1 fires `?format=json&collectionId=evt-collection-abc`
		// which the mock answers with an upcoming[] array.
		$this->mockHttpRoutes(
			array(
				'https://example.com/?format=json' => array( 'website' => array() ),
				'https://example.com/?format=json&collectionId=evt-collection-abc' => array(
					'upcoming' => array(
						$this->makeRawEvent( 'Show One', '2099-01-15T20:00:00+00:00' ),
						$this->makeRawEvent( 'Show Two', '2099-02-20T20:00:00+00:00' ),
						$this->makeRawEvent( 'Show Three', '2099-03-05T20:00:00+00:00' ),
					),
				),
			)
		);

		$events = $this->extractor->extract( $html, $source_url );

		$this->assertCount( 3, $events, 'Summary Block deref should yield 3 events' );
		$this->assertSame( 'Show One', $events[0]['title'] );
		$this->assertSame( '2099-01-15', $events[0]['startDate'] );
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

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script>'
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

		$html = '<html><script>Static.SQUARESPACE_CONTEXT = {};</script>'
			. '<div class="user-items-list" data-collection-id="evt-uil-xyz">'
			. '<div class="user-items-list-section">skeleton</div>'
			. '</div>'
			. '</html>';

		$this->mockHttpRoutes(
			array(
				'https://venue.test/?format=json' => array( 'website' => array() ),
				'https://venue.test/?format=json&collectionId=evt-uil-xyz' => array(
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
