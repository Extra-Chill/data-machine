<?php
/**
 * Weebly Extractor Tests
 *
 * Tests both Pattern A (per-block events) and Pattern B (multi-artist showcase).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.29.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WeeblyExtractor;

class WeeblyExtractorTest extends WP_UnitTestCase {

	private WeeblyExtractor $extractor;

	public function setUp(): void {
		parent::setUp();
		$this->extractor = new WeeblyExtractor();
	}

	public function test_getMethod_returns_weebly() {
		$this->assertEquals( 'weebly', $this->extractor->getMethod() );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Pattern A: Per-Block Events
	// ────────────────────────────────────────────────────────────────────────────

	public function test_canExtract_detects_pattern_a_weebly_site() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">Friday April 10th<br/>Band A<br/>$15 Cover<br/>Doors at 8pm</div>
			<div class="paragraph">Saturday April 11th<br/>Band B<br/>$10 Cover<br/>Doors at 9pm</div>
			<div class="paragraph">Sunday April 12th<br/>Band C<br/>$20 Cover<br/>Doors at 7pm</div>
		' );

		$this->assertTrue( $this->extractor->canExtract( $html ) );
	}

	public function test_extract_pattern_a_parses_per_block_events() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">Friday April 10th<br/>The Headliners<br/>DJ Smooth<br/>$15 Cover<br/>Doors at 8pm</div>
			<div class="paragraph">Saturday April 11th<br/>Acoustic Night<br/>$10 Cover<br/>Doors at 7pm</div>
			<div class="paragraph">Sunday April 12th<br/>Jazz Brunch<br/>Doors at 11am</div>
		' );

		$events = $this->extractor->extract( $html, 'https://example.com/events' );

		$this->assertCount( 3, $events );

		// First event.
		$this->assertEquals( 'The Headliners', $events[0]['title'] );
		$this->assertNotEmpty( $events[0]['startDate'] );
		$this->assertEquals( '20:00', $events[0]['startTime'] );

		// Second event.
		$this->assertEquals( 'Acoustic Night', $events[1]['title'] );
		$this->assertEquals( '$10', $events[1]['ticketPrice'] );

		// Third event.
		$this->assertEquals( 'Jazz Brunch', $events[2]['title'] );
		$this->assertEquals( '11:00', $events[2]['startTime'] );
	}

	public function test_extract_pattern_a_skips_non_date_blocks() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">Welcome to our venue!</div>
			<div class="paragraph">Friday April 10th<br/>Band A<br/>Doors at 8pm</div>
			<div class="paragraph">Saturday April 11th<br/>Band B<br/>Doors at 9pm</div>
			<div class="paragraph">Sunday April 12th<br/>Band C<br/>Doors at 7pm</div>
		' );

		$events = $this->extractor->extract( $html, 'https://example.com/events' );
		$this->assertCount( 3, $events );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Pattern B: Multi-Artist Showcase
	// ────────────────────────────────────────────────────────────────────────────

	public function test_canExtract_detects_pattern_b_showcase() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">
				Barn Jam April 22<br/>
				5:50 Eliza Grace<br/>
				6:40 Jarret Forrester<br/>
				7:30 The Band<br/>
				8:20 Run River Run<br/>
				9:10 Muddy Ruckus<br/>
				Barn Jam April 29<br/>
				5:50 LB Beistad<br/>
				6:40 Mutual Love Club<br/>
				7:30 The Crazy John String Band<br/>
				8:20 The Saint Cecilia<br/>
				9:10 Kick Snare Crash
			</div>
		' );

		$this->assertTrue( $this->extractor->canExtract( $html ) );
	}

	public function test_extract_pattern_b_produces_one_event_per_night() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">
				Barn Jam April 22<br/>
				5:50 Eliza Grace<br/>
				6:40 Jarret Forrester<br/>
				7:30 The Band<br/>
				8:20 Run River Run<br/>
				9:10 Muddy Ruckus<br/>
				Barn Jam April 29<br/>
				5:50 LB Beistad<br/>
				6:40 Mutual Love Club<br/>
				7:30 The Crazy John String Band<br/>
				8:20 The Saint Cecilia<br/>
				9:10 Kick Snare Crash
			</div>
		' );

		$events = $this->extractor->extract( $html, 'https://example.com/showcase' );

		$this->assertCount( 2, $events );

		// First night — April 22.
		$first = $events[0];
		$this->assertEquals( 'Barn Jam', $first['title'] );
		$this->assertStringContainsString( 'Eliza Grace', $first['description'] );
		$this->assertStringContainsString( 'Jarret Forrester', $first['description'] );
		$this->assertStringContainsString( 'Muddy Ruckus', $first['description'] );
		$this->assertEquals( '17:50', $first['startTime'] );

		// Second night — April 29.
		$second = $events[1];
		$this->assertEquals( 'Barn Jam', $second['title'] );
		$this->assertStringContainsString( 'LB Beistad', $second['description'] );
		$this->assertStringContainsString( 'Kick Snare Crash', $second['description'] );
		$this->assertEquals( '17:50', $second['startTime'] );
	}

	public function test_extract_pattern_b_handles_reversed_date_format() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">
				May 6 Barn Jam<br/>
				5:50 Kyle Erickson<br/>
				6:40 The Whipporwills<br/>
				7:30 Dr T and the Side Effects<br/>
				May 13 Barn Jam<br/>
				5:50 Ashley Virginia<br/>
				6:40 Brian Ashley Jones
			</div>
		' );

		$events = $this->extractor->extract( $html, 'https://example.com/showcase' );

		$this->assertCount( 2, $events );
		$this->assertEquals( 'Barn Jam', $events[0]['title'] );
		$this->assertEquals( 'Barn Jam', $events[1]['title'] );
	}

	public function test_extract_pattern_b_skips_url_lines() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">
				Barn Jam April 22<br/>
				5:50 Eliza Grace<br/>
				https://www.instagram.com/eliza_grace_music<br/>
				6:40 Jarret Forrester<br/>
				https://www.youtube.com/channel/UC7l1t8SQIJf21xhO4LMZePw<br/>
				Barn Jam April 29<br/>
				5:50 LB Beistad<br/>
				https://www.lbbeistad.com/
			</div>
		' );

		$events = $this->extractor->extract( $html, 'https://example.com/showcase' );

		$this->assertCount( 2, $events );

		// URLs should not appear in artist names.
		$this->assertStringNotContainsString( 'instagram.com', $events[0]['description'] );
		$this->assertStringNotContainsString( 'youtube.com', $events[0]['description'] );
		$this->assertStringContainsString( 'Eliza Grace', $events[0]['description'] );
		$this->assertStringContainsString( 'Jarret Forrester', $events[0]['description'] );
	}

	public function test_extract_pattern_b_handles_year_header_lines() {
		// The Awendaw Green page has "2026" as a standalone line before events.
		// This should not crash or produce bad events.
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">
				2026<br/>
				Barn Jam April 22<br/>
				5:50 Eliza Grace<br/>
				6:40 Jarret Forrester<br/>
				Barn Jam April 29<br/>
				5:50 LB Beistad<br/>
				6:40 Mutual Love Club
			</div>
		' );

		$events = $this->extractor->extract( $html, 'https://example.com/showcase' );

		$this->assertCount( 2, $events );
		$this->assertEquals( 'Barn Jam', $events[0]['title'] );
		$this->assertEquals( 'Barn Jam', $events[1]['title'] );
	}

	public function test_extract_pattern_b_lineup_preserves_times() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">
				Barn Jam May 13<br/>
				5:00 The New Blue of Yale<br/>
				5:50 Ashley Virginia<br/>
				6:40 Brian Ashley Jones<br/>
				7:30 Mount Pom<br/>
				8:20 Anders Thomsen<br/>
				9:10 Tanner Dane
			</div>
		' );

		$events = $this->extractor->extract( $html, 'https://example.com/showcase' );

		$this->assertCount( 1, $events );
		$event = $events[0];

		// First artist at 5:00 PM = 17:00.
		$this->assertEquals( '17:00', $event['startTime'] );

		// Description should list all 6 artists with their times.
		$this->assertStringContainsString( '5:00 The New Blue of Yale', $event['description'] );
		$this->assertStringContainsString( '5:50 Ashley Virginia', $event['description'] );
		$this->assertStringContainsString( '9:10 Tanner Dane', $event['description'] );
	}

	public function test_extract_pattern_b_uses_fallback_when_no_series_name() {
		$html = $this->makeWeeblyHtml( '
			<div class="paragraph">
				April 22<br/>
				5:50 Eliza Grace<br/>
				6:40 Jarret Forrester<br/>
				May 6<br/>
				5:50 Kyle Erickson<br/>
				6:40 The Whipporwills
			</div>
		' );

		$events = $this->extractor->extract( $html, 'https://example.com/showcase' );

		$this->assertCount( 2, $events );

		// No series name → use first artist as title.
		$this->assertEquals( 'Eliza Grace', $events[0]['title'] );
		$this->assertEquals( 'Kyle Erickson', $events[1]['title'] );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Negative Tests
	// ────────────────────────────────────────────────────────────────────────────

	public function test_canExtract_rejects_non_weebly_html() {
		$html = '<html><body><div class="paragraph">Friday April 10th Band A</div></body></html>';
		$this->assertFalse( $this->extractor->canExtract( $html ) );
	}

	public function test_extract_returns_empty_for_no_blocks() {
		$html = $this->makeWeeblyHtml( '<p>No events here</p>' );
		$this->assertEmpty( $this->extractor->extract( $html, 'https://example.com' ) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Wrap content in a minimal Weebly page shell.
	 *
	 * @param string $body_html HTML to inject into the body.
	 * @return string Full page HTML with Weebly fingerprint.
	 */
	private function makeWeeblyHtml( string $body_html ): string {
		return '<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" type="text/css" href="//cdn11.editmysite.com/css/sites.css?buildtime=1234" />
</head>
<body class="wsite-page-events">
	<div id="wsite-content">
		' . $body_html . '
	</div>
</body>
</html>';
	}
}
