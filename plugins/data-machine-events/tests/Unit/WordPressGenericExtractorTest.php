<?php
/**
 * WordPress Generic Extractor Tests
 *
 * Covers the 4-tier extraction cascade for non-Tribe WordPress venues
 * documented in issue #268.
 *
 * Tier 2 (REST CPT probe) is exercised via a test subclass that mocks the
 * outbound `fetchUrl` calls — the real `BaseExtractor::fetchUrl` depends on
 * Data Machine core's HttpClient which is not stubbed in WP_UnitTestCase.
 *
 * Tier 4 (theme-specific listing) is exercised against synthetic markup so
 * date / title / URL-slug fallbacks are all deterministically asserted.
 *
 * The real production fixture (`tests/Fixtures/wp-generic/le-poisson-rouge.html`)
 * is used for `canExtract` detection only — LPR's events listing page is an
 * SPA shell that does not emit machine-readable event data into the initial
 * HTML, so it does not produce events in this extractor. That's tracked as
 * a separate "needs headless-browser support" concern in the PR body.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.36.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WordPressGenericExtractor;

class WordPressGenericExtractorTest extends WP_UnitTestCase {

	private WordPressGenericExtractor $extractor;
	private string $fixture_dir;

	public function setUp(): void {
		parent::setUp();
		$this->extractor   = new WordPressGenericExtractor();
		$this->fixture_dir = __DIR__ . '/../Fixtures/wp-generic';
	}

	/**
	 * canExtract: WP site without Tribe should match.
	 */
	public function test_canExtract_detects_wp_without_tribe() {
		$html = '<html><head>'
			. '<link rel="https://api.w.org/" href="https://example.com/wp-json/" />'
			. '</head><body>'
			. '<link href="https://example.com/wp-content/themes/foo/style.css" />'
			. '<p>Some content.</p>'
			. '</body></html>';

		$this->assertTrue(
			$this->extractor->canExtract( $html ),
			'WP site with wp-content + wp-json but no Tribe should be claimed.'
		);
	}

	/**
	 * canExtract: WP site WITH Tribe Events Calendar should be rejected
	 * so WordPressExtractor handles it.
	 */
	public function test_canExtract_rejects_when_tribe_present() {
		$html = '<html><body>'
			. '<link href="/wp-content/themes/foo/style.css" />'
			. '<script>fetch("/wp-json/tribe/events/v1/events?per_page=10")</script>'
			. '</body></html>';

		$this->assertFalse(
			$this->extractor->canExtract( $html ),
			'Tribe-enabled WP sites must be left to WordPressExtractor.'
		);
	}

	/**
	 * canExtract: non-WordPress HTML should be rejected.
	 */
	public function test_canExtract_rejects_non_wordpress() {
		$html = '<html><head><title>Plain HTML</title></head>'
			. '<body><p>No WP fingerprint here.</p></body></html>';

		$this->assertFalse( $this->extractor->canExtract( $html ) );
		$this->assertFalse( $this->extractor->canExtract( '' ) );
	}

	/**
	 * canExtract: live LPR fixture is recognized.
	 */
	public function test_canExtract_detects_live_lpr_fixture() {
		$fixture = $this->fixture_dir . '/le-poisson-rouge.html';
		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'LPR fixture not present.' );
		}
		$html = file_get_contents( $fixture );
		$this->assertTrue(
			$this->extractor->canExtract( $html ),
			'Le Poisson Rouge is a non-Tribe WP install and should be claimed.'
		);
	}

	/**
	 * Tier 2: REST API exposes a custom events CPT.
	 *
	 * Uses a stub subclass to mock the /wp-json/wp/v2/types + collection
	 * responses so we can assert end-to-end mapping deterministically.
	 */
	public function test_extract_via_rest_custom_post_type_fixture() {
		$types = array(
			'post'   => array( 'rest_base' => 'posts' ),
			'page'   => array( 'rest_base' => 'pages' ),
			'events' => array( 'rest_base' => 'events' ),
		);

		$collection = array(
			array(
				'id'        => 101,
				'title'     => array( 'rendered' => 'Headline Act &amp; Friends' ),
				'link'      => 'https://example.com/events/headline-act/',
				'date'      => '2026-06-15T10:00:00',
				'acf'       => array(
					'event_date' => '2026-06-15T20:30:00',
					'venue'      => 'Main Stage',
					'ticket_url' => 'https://www.eventbrite.com/e/headline-act-tickets-123',
				),
				'_embedded' => array(
					'wp:featuredmedia' => array(
						array( 'source_url' => 'https://example.com/wp-content/uploads/2026/06/headline.jpg' ),
					),
				),
				'excerpt'   => array( 'rendered' => '<p>A great show.</p>' ),
			),
			array(
				'id'    => 102,
				'title' => array( 'rendered' => 'Second Show' ),
				'link'  => 'https://example.com/events/second-show/',
				'acf'   => array( 'event_date' => '2026-07-04' ),
			),
		);

		$extractor = new WordPressGenericExtractorMock( $types, $collection );

		$html = '<html><head>'
			. '<link rel="https://api.w.org/" href="https://example.com/wp-json/" />'
			. '<title>Events - Example Venue</title>'
			. '</head><body>'
			. '<link href="/wp-content/themes/example/style.css" />'
			. '</body></html>';

		$events = $extractor->extract( $html, 'https://example.com/events/' );

		$this->assertCount( 2, $events, 'Both REST items should map to events.' );

		$this->assertSame( 'Headline Act & Friends', $events[0]['title'] );
		$this->assertSame( '2026-06-15', $events[0]['startDate'] );
		$this->assertSame( '20:30', $events[0]['startTime'] );
		$this->assertSame( 'Main Stage', $events[0]['venue'] );
		$this->assertSame( 'https://www.eventbrite.com/e/headline-act-tickets-123', $events[0]['ticketUrl'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/06/headline.jpg', $events[0]['imageUrl'] );
		$this->assertSame( 'https://example.com/events/headline-act/', $events[0]['source_url'] );

		$this->assertSame( 'Second Show', $events[1]['title'] );
		$this->assertSame( '2026-07-04', $events[1]['startDate'] );

		$this->assertSame( 'wordpress_generic', $extractor->getMethod() );
	}

	/**
	 * Tier 2: when no CPT name matches the events pattern, return [] and
	 * let the cascade fall through to Tier 4 (or other extractors).
	 */
	public function test_extract_returns_empty_when_no_event_cpt_registered() {
		$types = array(
			'post'    => array( 'rest_base' => 'posts' ),
			'page'    => array( 'rest_base' => 'pages' ),
			'product' => array( 'rest_base' => 'products' ),
		);

		$extractor = new WordPressGenericExtractorMock( $types, array() );

		$html = '<html><head><title>Generic WP Blog</title></head>'
			. '<body><link href="/wp-content/themes/foo/style.css" /></body></html>';

		// No Tier 2 hit, no event-shaped page signal → empty.
		$this->assertSame(
			array(),
			$extractor->extract( $html, 'https://example.com/' )
		);
	}

	/**
	 * Tier 4: theme-listing fallback parses <article class="event"> blocks
	 * when the page itself looks event-listing-shaped.
	 */
	public function test_extract_falls_back_to_theme_listing_when_no_rest_cpt() {
		// Empty types response — no event CPT discoverable.
		$extractor = new WordPressGenericExtractorMock( array( 'post' => array( 'rest_base' => 'posts' ) ), array() );

		$html = '<html><head>'
			. '<title>Upcoming Events at Example Venue</title>'
			. '</head><body>'
			. '<link href="/wp-content/themes/example/style.css" />'
			. '<article class="post event-card">'
			. '  <h2><a href="/event/2026-05-20-show-one/">Show One</a></h2>'
			. '  <time datetime="2026-05-20T20:00:00-04:00">May 20</time>'
			. '  <p>Doors at 7 PM.</p>'
			. '  <img src="/wp-content/uploads/show-one.jpg" />'
			. '</article>'
			. '<article class="post show">'
			. '  <h3>Show Two</h3>'
			. '  <p>June 12, 2026 — special engagement.</p>'
			. '  <a href="https://dice.fm/event/show-two">Get tickets</a>'
			. '</article>'
			. '</body></html>';

		$events = $extractor->extract( $html, 'https://example.com/events/' );

		$this->assertCount( 2, $events, 'Two event-class articles should be extracted.' );

		$this->assertSame( 'Show One', $events[0]['title'] );
		$this->assertSame( '2026-05-20', $events[0]['startDate'] );
		$this->assertSame( '20:00', $events[0]['startTime'] );
		$this->assertSame( 'https://example.com/event/2026-05-20-show-one/', $events[0]['source_url'] );

		$this->assertSame( 'Show Two', $events[1]['title'] );
		$this->assertSame( '2026-06-12', $events[1]['startDate'] );
		$this->assertSame( 'https://dice.fm/event/show-two', $events[1]['ticketUrl'] );
	}

	/**
	 * Tier 4 false-positive guard: if the page doesn't look event-shaped
	 * (URL is /blog/, no "events" / "calendar" / "schedule" in title or
	 * meta description), we must NOT scrape <article class="event"> blocks
	 * from a blog homepage.
	 */
	public function test_extract_skips_tier4_when_page_not_event_shaped() {
		$extractor = new WordPressGenericExtractorMock( array( 'post' => array( 'rest_base' => 'posts' ) ), array() );

		$html = '<html><head>'
			. '<title>Latest Blog Posts</title>'
			. '<meta name="description" content="Recent musings from the team." />'
			. '</head><body>'
			. '<link href="/wp-content/themes/example/style.css" />'
			. '<article class="post event">'
			. '  <h2>An Eventful Day at the Office</h2>'
			. '  <time datetime="2026-05-20">May 20</time>'
			. '  <p>What a day.</p>'
			. '</article>'
			. '</body></html>';

		$events = $extractor->extract( $html, 'https://example.com/blog/' );

		$this->assertSame(
			array(),
			$events,
			'Blog page with stray "event"-classed articles must NOT be scraped.'
		);
	}

	/**
	 * The cascade order guarantees JsonLdExtractor wins before this extractor
	 * runs when valid Event JSON-LD is present on the page. This is a
	 * behavioral sanity check: WordPressGenericExtractor is opinionated
	 * about NOT duplicating that work — Tier 2 (REST probe) is allowed to
	 * fail silently when there's no event CPT, but Tier 4 should not
	 * accidentally scrape JSON-LD content from the page.
	 *
	 * In practice, "fixture with valid Event JSON-LD and nothing else"
	 * causes WordPressGenericExtractor to return [] because:
	 *   - Tier 2: no CPT match (REST is not invoked in the mock here),
	 *   - Tier 4: no <article class="event|gig|show|...">.
	 */
	public function test_extract_returns_empty_when_only_jsonld_present() {
		$extractor = new WordPressGenericExtractorMock( array( 'post' => array( 'rest_base' => 'posts' ) ), array() );

		$html = '<html><head>'
			. '<title>Events - Example Venue</title>'
			. '</head><body>'
			. '<link href="/wp-content/themes/example/style.css" />'
			. '<script type="application/ld+json">'
			. '{"@context":"https://schema.org","@type":"Event","name":"JSON-LD Show","startDate":"2026-05-20T20:00:00-04:00"}'
			. '</script>'
			. '<p>Nothing else here.</p>'
			. '</body></html>';

		$this->assertSame(
			array(),
			$extractor->extract( $html, 'https://example.com/events/' ),
			'WordPressGenericExtractor must not duplicate JsonLdExtractor work.'
		);
	}

	/**
	 * Integration smoke test against the real LPR snapshot.
	 *
	 * LPR's `/events/` page is an SPA shell, so the HTML alone yields no
	 * events. We assert the extractor handles the live fixture without
	 * fatal error and returns an array (possibly empty).
	 *
	 * The REST API path (`https://lpr.com/wp-json/wp/v2/lpr_events`) is NOT
	 * exercised here because it would require live network access; the
	 * recorded `le-poisson-rouge-events.json` fixture is preserved for
	 * future use when we wire a static-response mode.
	 */
	public function test_extract_real_wp_generic_fixture() {
		$fixture = $this->fixture_dir . '/le-poisson-rouge.html';
		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'LPR fixture not present.' );
		}

		$html = file_get_contents( $fixture );

		// Use the mock to avoid network access; pretend the WP REST `/types`
		// endpoint returned no event CPTs. This forces the Tier 4 path —
		// which should yield zero events from LPR's SPA shell.
		$extractor = new WordPressGenericExtractorMock( array( 'post' => array( 'rest_base' => 'posts' ) ), array() );

		$events = $extractor->extract( $html, 'https://lpr.com/events/' );

		$this->assertIsArray(
			$events,
			'Extractor must return an array even when the live page is SPA-shaped.'
		);
		// LPR's HTML emits no <article class="event"> markup; Tier 4 yields 0.
		// That's the expected outcome for SPAs and is tracked separately.
		$this->assertCount( 0, $events );
	}
}

/**
 * Test double that intercepts the two outbound fetches Tier 2 makes.
 *
 * - First call: /wp-json/wp/v2/types  -> returns the injected types map
 * - Second+ call: any /wp-json/wp/v2/<rest_base> -> returns the injected
 *   collection (or [] when none provided)
 *
 * Any other URL returns null (simulating a network failure).
 */
class WordPressGenericExtractorMock extends WordPressGenericExtractor {

	private array $types;
	private array $collection;

	public function __construct( array $types, array $collection ) {
		$this->types      = $types;
		$this->collection = $collection;
	}

	protected function fetchUrl( string $url, array $args = array(), string $context = '' ): ?string {
		if ( false !== strpos( $url, '/wp-json/wp/v2/types' ) ) {
			return wp_json_encode( $this->types );
		}
		if ( preg_match( '#/wp-json/wp/v2/[^/?]+#', $url ) ) {
			return wp_json_encode( $this->collection );
		}
		return null;
	}
}
