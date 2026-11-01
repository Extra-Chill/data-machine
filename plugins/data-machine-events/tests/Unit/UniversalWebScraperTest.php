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
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\JsonLdExtractor;

class UniversalWebScraperTest extends WP_UnitTestCase {

	private JsonLdExtractor $extractor;

	public function setUp(): void {
		parent::setUp();
		$this->extractor = new JsonLdExtractor();
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
    "startDate": "2099-02-15T20:00:00-07:00",
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

		$events = $this->extractor->extract( $html, 'https://example.com/events' );

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
        "startDate": "2099-02-15T20:00:00"
    },
    {
        "@context": "https://schema.org",
        "@type": "MusicEvent",
        "name": "Event Two",
        "startDate": "2099-02-16T21:00:00"
    }
]
</script>
</head>
<body></body>
</html>
HTML;

		$events = $this->extractor->extract( $html, 'https://example.com/events' );

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

		$events = $this->extractor->extract( $html, 'https://example.com' );

		$this->assertSame( array(), $events );
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

		$events = $this->extractor->extract( $html, 'https://example.com' );

		$this->assertSame( array(), $events );
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
    "startDate": "2099-03-01T19:00:00",
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

		$events = $this->extractor->extract( $html, 'https://example.com/events' );

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
    "startDate": "2099-03-01T19:00:00",
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

		$events = $this->extractor->extract( $html, 'https://example.com/events' );

		$this->assertNotEmpty( $events );
		$event = $events[0];
		$this->assertEquals( 'The Test Band', $event['performer'] ?? '' );
	}

	public function test_html_teaser_packet_includes_detail_page(): void {
		$class = \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper::class;
		$scraper = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
		$calls = array();
		$enricher = new \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\DetailPageEnricher(
			function ( $url ) use ( &$calls ) {
				$calls[] = $url;
				return '<main><h1>David Sedaris</h1><p>December 12, 2099 at 7:30pm</p></main>';
			}, array()
		);
		( new \ReflectionProperty( $class, 'detail_enricher' ) )->setValue( $scraper, $enricher );
		$method = new \ReflectionMethod( $class, 'tryHtmlSectionExtraction' );
		$html = '<div class="events"><ul><li class="glide__slide"><div><a href="/event/david-sedaris/"><img src="sedaris.jpg" alt="David Sedaris"></a></div></li></ul></div>';
		$packet = $method->invoke( $scraper, $html, 'https://www.kogercenterforthearts.com', array(), \DataMachine\Core\ExecutionContext::direct( 'universal_web_scraper' ), 1 );
		$data = json_decode( $packet['content'], true );
		$this->assertSame( array( 'https://www.kogercenterforthearts.com/event/david-sedaris/' ), $calls );
		$this->assertStringContainsString( '2099 at 7:30pm', $data['detail_html'] );
		$this->assertStringContainsString( 'glide__slide', $data['raw_html'] );
	}

	public function test_extraction_method_is_jsonld() {
		$this->assertSame( 'jsonld', $this->extractor->getMethod() );
	}
}
