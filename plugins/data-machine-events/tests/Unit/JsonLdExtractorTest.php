<?php
/**
 * JsonLdExtractor Tests
 *
 * Exercises every Schema.org Event shape catalogued in
 * Extra-Chill/data-machine-events#262, plus two real-world production
 * fixtures (Charleston Pour House, Royal American) and a malformed-block
 * resilience check.
 *
 * The six shapes:
 *   1. Single Event object
 *   2. Top-level array of Events
 *   3. `@graph` wrapper
 *   4. Event subtypes (`MusicEvent`, etc.) including `@type` as array
 *   5. Parent Festival with `subEvent[]`
 *   6. Multiple `<script type="application/ld+json">` blocks per page
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.29.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\JsonLdExtractor;

class JsonLdExtractorTest extends WP_UnitTestCase {

	private JsonLdExtractor $extractor;
	private string $fixtures_dir;

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new JsonLdExtractor();
		$this->fixtures_dir = dirname( __DIR__ ) . '/Fixtures/ld+json';
	}

	public function test_getMethod_returns_jsonld() {
		$this->assertEquals( 'jsonld', $this->extractor->getMethod() );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// canExtract
	// ────────────────────────────────────────────────────────────────────────────

	public function test_canExtract_detects_json_ld_block() {
		$html = '<html><head><script type="application/ld+json">{}</script></head></html>';
		$this->assertTrue( $this->extractor->canExtract( $html ) );
	}

	public function test_canExtract_detects_single_quoted_attribute() {
		$html = "<html><head><script type='application/ld+json'>{}</script></head></html>";
		$this->assertTrue( $this->extractor->canExtract( $html ) );
	}

	public function test_canExtract_rejects_html_without_json_ld() {
		$html = '<html><head><title>No JSON-LD here</title></head><body><p>Hi</p></body></html>';
		$this->assertFalse( $this->extractor->canExtract( $html ) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Real-world production fixtures
	// ────────────────────────────────────────────────────────────────────────────

	public function test_extract_charleston_pour_house_fixture() {
		$html   = file_get_contents( $this->fixtures_dir . '/charleston-pour-house.html' );
		$events = $this->extractor->extract( $html, 'https://charlestonpourhouse.com' );

		// The fixture lists 12 events; allow some natural attrition for events
		// missing required fields (title / startDate). The threshold is the
		// floor at which we still call this a successful extraction.
		$this->assertGreaterThanOrEqual( 10, count( $events ), 'Pour House fixture should yield ≥10 events' );

		// Every yielded event should have the required fields.
		foreach ( $events as $event ) {
			$this->assertNotEmpty( $event['title'] ?? '', 'Event missing title' );
			$this->assertNotEmpty( $event['startDate'] ?? '', 'Event missing startDate' );
		}

		// First event sanity-check.
		$this->assertNotEmpty( $events[0]['venue'] ?? '' );
	}

	public function test_extract_royal_american_single_event_fixture() {
		$html   = file_get_contents( $this->fixtures_dir . '/royal-american-single-event.html' );
		$events = $this->extractor->extract(
			$html,
			'https://www.theroyalamerican.com/schedule/emma-grace-burton-5-15-26'
		);

		// The fixture contains one WebSite block + one Event block.
		// We expect exactly the one Event.
		$this->assertCount( 1, $events, 'Royal American fixture should yield exactly 1 event' );

		$event = $events[0];

		$this->assertStringContainsString( 'Emma Grace Burton', $event['title'] );
		$this->assertEquals( '2026-05-15', $event['startDate'] );

		// The fixture uses the `-0400` offset variant (no colon). Verifies
		// that DateTimeParser::parseIso() accepts both `-0400` and `-04:00`.
		$this->assertEquals( '21:00', $event['startTime'] );
		$this->assertEquals( '2026-05-16', $event['endDate'] );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Shape 1: Single Event object — implicitly covered by Royal American fixture.
	// Shape 2: Top-level array of Events (synthetic + Pour House)
	// ────────────────────────────────────────────────────────────────────────────

	public function test_extract_top_level_event_array() {
		$html   = file_get_contents( $this->fixtures_dir . '/synthetic-top-level-array.html' );
		$events = $this->extractor->extract( $html, 'https://example.com' );

		$this->assertCount( 3, $events );
		$this->assertEquals( 'Synthetic Array Event 1', $events[0]['title'] );
		$this->assertEquals( 'Synthetic Array Event 2', $events[1]['title'] );
		$this->assertEquals( 'Synthetic Array Event 3', $events[2]['title'] );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Shape 3: `@graph` wrapper
	// ────────────────────────────────────────────────────────────────────────────

	public function test_extract_graph_wrapper() {
		$html   = file_get_contents( $this->fixtures_dir . '/synthetic-graph-wrapper.html' );
		$events = $this->extractor->extract( $html, 'https://example.com' );

		// Two Events in the @graph (WebSite + BreadcrumbList are skipped).
		$this->assertCount( 2, $events );
		$titles = array_column( $events, 'title' );
		$this->assertContains( 'Synthetic Graph Event A', $titles );
		$this->assertContains( 'Synthetic Graph Event B (subtype)', $titles );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Shape 4: Event subtypes
	// ────────────────────────────────────────────────────────────────────────────

	public function test_extract_music_event_subtype() {
		$html   = file_get_contents( $this->fixtures_dir . '/synthetic-music-event-subtype.html' );
		$events = $this->extractor->extract( $html, 'https://example.com' );

		$this->assertCount( 1, $events );
		$this->assertEquals( 'Synthetic MusicEvent Subtype', $events[0]['title'] );
		$this->assertEquals( '2026-05-20', $events[0]['startDate'] );
		$this->assertEquals( '20:00', $events[0]['startTime'] );
		$this->assertEquals( 'The Test Hall', $events[0]['venue'] );
		$this->assertEquals( 'Test Band', $events[0]['performer'] );
	}

	public function test_extract_type_array() {
		$html   = file_get_contents( $this->fixtures_dir . '/synthetic-type-array.html' );
		$events = $this->extractor->extract( $html, 'https://example.com' );

		$this->assertCount( 1, $events );
		$this->assertEquals( 'Synthetic Multi-Typed Event', $events[0]['title'] );
		$this->assertEquals( 'Multi-Type Arena', $events[0]['venue'] );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Shape 5: Parent Festival with subEvent[]
	// ────────────────────────────────────────────────────────────────────────────

	public function test_extract_festival_with_subevents() {
		$html   = file_get_contents( $this->fixtures_dir . '/synthetic-festival-with-subevents.html' );
		$events = $this->extractor->extract( $html, 'https://example.com' );

		// Parent Festival has its own startDate so it IS extracted.
		// 3 subEvents are also extracted.
		// Total: 4 events.
		$this->assertCount( 4, $events );

		$titles = array_column( $events, 'title' );
		$this->assertContains( 'Synthetic Awesome Fest', $titles, 'Parent Festival should be extracted (has startDate)' );
		$this->assertContains( 'Festival Day One Headliner', $titles );
		$this->assertContains( 'Festival Day Two Headliner', $titles );
		$this->assertContains( 'Festival Day Three Closer', $titles );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Shape 6: Multiple <script> blocks per page
	// ────────────────────────────────────────────────────────────────────────────

	public function test_extract_multiple_script_blocks() {
		$html   = file_get_contents( $this->fixtures_dir . '/synthetic-multiple-script-blocks.html' );
		$events = $this->extractor->extract( $html, 'https://example.com' );

		// Organization + BreadcrumbList blocks are skipped (no Event types).
		// Two Event-bearing blocks each yield one event.
		$this->assertCount( 2, $events );

		$titles = array_column( $events, 'title' );
		$this->assertContains( 'Synthetic Multi-Block Event A', $titles );
		$this->assertContains( 'Synthetic Multi-Block Event B', $titles );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Resilience
	// ────────────────────────────────────────────────────────────────────────────

	public function test_extract_malformed_json_skips_block_gracefully() {
		$html = file_get_contents( $this->fixtures_dir . '/synthetic-malformed-block.html' );

		// Must not throw. Must still extract from the valid block.
		$events = $this->extractor->extract( $html, 'https://example.com' );

		$this->assertCount( 1, $events );
		$this->assertEquals( 'Synthetic Valid After Malformed', $events[0]['title'] );
	}
}
