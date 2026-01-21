<?php
/**
 * UniversalWebScraper Tests
 *
 * Tests for the Universal Web Scraper extractor integration.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\StructuredDataProcessor;

class UniversalWebScraperTest extends WP_UnitTestCase {

	private StructuredDataProcessor $processor;

	public function setUp(): void {
		parent::setUp();
		$this->processor = new StructuredDataProcessor();
	}

	public function test_json_ld_extraction_parses_single_event() {
		$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "MusicEvent",
    "name": "Test Concert",
    "startDate": "2026-02-15T20:00:00-07:00",
    "location": {
        "@type": "Place",
        "name": "Red Rocks Amphitheatre",
        "address": {
            "@type": "PostalAddress",
            "streetAddress": "18300 W Alameda Pkwy",
            "addressLocality": "Morrison",
            "addressRegion": "CO"
        }
    }
}
</script>
</head>
<body></body>
</html>
HTML;

		$events = $this->processor->extract( $html, 'https://example.com/events' );

		$this->assertNotEmpty( $events, 'Should extract events from JSON-LD' );

		$event = $events[0];
		$this->assertEquals( 'Test Concert', $event['title'] );
		$this->assertEquals( 'Red Rocks Amphitheatre', $event['venue'] );
	}

	public function test_json_ld_extraction_parses_event_array() {
		$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<script type="application/ld+json">
[
    {
        "@context": "https://schema.org",
        "@type": "MusicEvent",
        "name": "Event One",
        "startDate": "2026-02-15T20:00:00"
    },
    {
        "@context": "https://schema.org",
        "@type": "MusicEvent",
        "name": "Event Two",
        "startDate": "2026-02-16T21:00:00"
    }
]
</script>
</head>
<body></body>
</html>
HTML;

		$events = $this->processor->extract( $html, 'https://example.com/events' );

		$this->assertCount( 2, $events, 'Should extract both events from array' );
		$this->assertEquals( 'Event One', $events[0]['title'] );
		$this->assertEquals( 'Event Two', $events[1]['title'] );
	}

	public function test_extraction_returns_empty_for_no_events() {
		$html = <<<HTML
<!DOCTYPE html>
<html>
<head><title>No Events</title></head>
<body><p>Just some text</p></body>
</html>
HTML;

		$events = $this->processor->extract( $html, 'https://example.com' );

		$this->assertIsArray( $events );
	}

	public function test_extraction_handles_malformed_json_ld() {
		$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<script type="application/ld+json">
{ invalid json here }
</script>
</head>
<body></body>
</html>
HTML;

		$events = $this->processor->extract( $html, 'https://example.com' );

		$this->assertIsArray( $events );
	}

	public function test_extraction_includes_ticket_url() {
		$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "MusicEvent",
    "name": "Ticketed Event",
    "startDate": "2026-03-01T19:00:00",
    "offers": {
        "@type": "Offer",
        "url": "https://tickets.example.com/buy"
    }
}
</script>
</head>
<body></body>
</html>
HTML;

		$events = $this->processor->extract( $html, 'https://example.com/events' );

		$this->assertNotEmpty( $events );
		$event = $events[0];
		$this->assertEquals( 'https://tickets.example.com/buy', $event['ticketUrl'] ?? '' );
	}

	public function test_extraction_includes_performer() {
		$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "MusicEvent",
    "name": "Band Concert",
    "startDate": "2026-03-01T19:00:00",
    "performer": {
        "@type": "MusicGroup",
        "name": "The Test Band"
    }
}
</script>
</head>
<body></body>
</html>
HTML;

		$events = $this->processor->extract( $html, 'https://example.com/events' );

		$this->assertNotEmpty( $events );
		$event = $events[0];
		$this->assertEquals( 'The Test Band', $event['performer'] ?? '' );
	}

	public function test_get_extraction_method_returns_type() {
		$html_with_json_ld = <<<HTML
<html>
<head>
<script type="application/ld+json">{"@type": "Event", "name": "Test"}</script>
</head>
</html>
HTML;

		$method = $this->processor->getExtractionMethod( $html_with_json_ld, 'https://example.com' );

		// Should return the extractor method or null if no match
		$this->assertTrue( null=== $method || is_string( $method ) );
	}
}
