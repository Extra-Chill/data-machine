<?php
/** Canva Sites must decode into HTML the universal extractor chain can read. */
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\CanvaSiteResolver;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use DataMachine\Core\ExecutionContext;
use WP_UnitTestCase;

class CanvaSiteResolverTest extends WP_UnitTestCase {
	private const SOURCE = 'https://venue.my.canva.site/calendar';

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function fixture( string $name ): string {
		return file_get_contents( __DIR__ . '/../Fixtures/canva/' . $name );
	}

	/** Wrap a JSON document the way Canva ships it: inside a single-quoted JS string. */
	private function canvaPage( string $json ): string {
		$literal = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $json );
		return "<html><head><title>Calendar</title></head><body><div id=\"root\"></div><script>(function() {window['__canva_public_path__'] = '_assets\\/'; window['bootstrap'] = JSON.parse('" . $literal . "');})();</script></body></html>";
	}

	private function scrape( string $page, array $others = array() ): array {
		$responses = array( self::SOURCE => $page ) + $others;
		add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $responses ) {
			if ( ! isset( $responses[ $url ] ) ) {
				return new \WP_Error( 'unexpected_url', $url );
			}
			return array( 'headers' => array(), 'body' => $responses[ $url ], 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		}, 10, 3 );
		$method = new \ReflectionMethod( UniversalWebScraper::class, 'executeFetch' );
		return $method->invoke( new UniversalWebScraper(), array( 'source_url' => self::SOURCE ), ExecutionContext::direct( 'universal_web_scraper' ) );
	}

	public function test_detects_canva_by_host_or_runtime_markers(): void {
		$resolver = new CanvaSiteResolver();
		$this->assertTrue( $resolver->isCanva( '<html></html>', 'https://artlocker.my.canva.site/calendar' ) );
		$this->assertTrue( $resolver->isCanva( $this->fixture( 'hedhi-calendar-trimmed.html' ), 'https://custom-domain.example/calendar' ) );
		$this->assertFalse( $resolver->isCanva( '<html><body>No builder here</body></html>', 'https://example.com/' ) );
		$this->assertFalse( $resolver->isCanva( '<html></html>', 'https://my.canva.site.evil.example/' ) );
	}

	public function test_decodes_text_runs_in_document_order_with_links(): void {
		$html = ( new CanvaSiteResolver() )->resolve( $this->fixture( 'hedhi-calendar-trimmed.html' ), 'https://custom-domain.example/calendar' );
		$this->assertIsString( $html );

		$ordered = array( 'our public events', 'TICKETS', '12/11/26', 'Sound Archive Sessions', 'Saturday 12/5', 'ETERNAL JEST', 'DOORS 7PM', 'BUY TICKETS', '11/21 - 11/22', '11/14/26', 'Minor Threat 2' );
		$last    = -1;
		foreach ( $ordered as $text ) {
			$position = strpos( $html, $text );
			$this->assertNotFalse( $position, $text );
			$this->assertGreaterThan( $last, $position, $text );
			$last = $position;
		}

		$this->assertStringContainsString( '<p><a href="https://soundarchivebooks.org/tour/sound-archive-sessions-charleston/">TICKETS</a></p>', $html );
		$this->assertStringContainsString( '<p><a href="https://hedhistudio.com/products/jumping">BUY TICKETS</a></p>', $html );
		$this->assertStringContainsString( '<p>$10/ ticket<br>DOORS 7PM<br>SHOW 8PM</p>', $html );
		$this->assertStringContainsString( 'ART PALS ‘26', $html );
		// One section per Canva page section, so the HTML-section fallback sees one event at a time.
		$this->assertSame( 5, substr_count( $html, '<section ' . CanvaSiteResolver::SECTION_ATTRIBUTE . '=' ) );
		$this->assertMatchesRegularExpression( '#<section data-canva-section="2">\s*<p><a [^>]+>TICKETS</a></p>\s*<p>12/11/26</p>\s*<p>Sound Archive Sessions</p>\s*</section>#', $html );
	}

	public function test_decodes_js_and_json_string_escapes(): void {
		// JSON-level escapes: \u00e9, a surrogate pair, \n, \" and \/; JS-level: \' and \x41 are added below.
		$json = '{"page":{"A":[{"C":{"A":["Caf\u00e9 \ud83c\udfb8 \"Live\"\n","Doors 8PM \/ 21+\n"],"B":[20]}},'
			. '{"C":{"A":["It\'s on\n"],"B":[6]}},{"A?":"A","A":"https:\/\/tickets.example.com\/show?a=1&b=2"}]}}';
		$page = $this->canvaPage( $json );
		$page = str_replace( 'Doors 8PM', '\\x44oors 8PM', $page );

		$html = ( new CanvaSiteResolver() )->resolve( $page );
		$this->assertIsString( $html );
		$this->assertStringContainsString( '<p>Café 🎸 &quot;Live&quot;<br>Doors 8PM / 21+</p>', $html );
		$this->assertStringContainsString( '<p><a href="https://tickets.example.com/show?a=1&#038;b=2">It&#039;s on</a></p>', $html );
		$this->assertStringNotContainsString( '\\n', $html );
		$this->assertStringNotContainsString( '\\u', $html );
	}

	public function test_link_without_preceding_text_is_emitted_standalone(): void {
		$json = '{"page":{"A":[{"y":{"B":{"A?":"A","A":"https://example.com/tickets"}}},{"a":{"C":{"A":["Show Title\n"],"B":[11]}}},{"A?":"A","A":"javascript:alert(1)"}]}}';
		$html = ( new CanvaSiteResolver() )->resolve( $this->canvaPage( $json ) );
		$this->assertStringContainsString( '<p><a href="https://example.com/tickets">https://example.com/tickets</a></p>', $html );
		$this->assertStringContainsString( '<p>Show Title</p>', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertLessThan( strpos( $html, 'Show Title' ), strpos( $html, 'example.com/tickets' ) );
	}

	public function test_non_canva_page_is_left_untouched(): void {
		$page = '<html><body><script>var data = JSON.parse(\'{"C":{"A":["Text\\\\n"],"B":[4]}}\');</script></body></html>';
		$this->assertNull( ( new CanvaSiteResolver() )->resolve( $page, 'https://example.com/events' ) );
	}

	public function test_empty_decode_returns_null(): void {
		$resolver = new CanvaSiteResolver();
		$this->assertNull( $resolver->resolve( $this->canvaPage( '{"page":{"A":[{"A?":"A","A":"not a url"}]}}' ) ) );
		$this->assertNull( $resolver->resolve( '<script>window[\'__canva_public_path__\'] = \'_assets\';</script>', self::SOURCE ) );
		$this->assertNull( $resolver->resolve( $this->canvaPage( '{invalid' ), self::SOURCE ) );
		// Unterminated literal must not loop or throw.
		$this->assertNull( $resolver->resolve( "<script>window['__canva_public_path__']='x'; JSON.parse('{\"C\":", self::SOURCE ) );
	}

	public function test_empty_decode_falls_through_to_existing_extraction(): void {
		$page   = '<html><head><script type="application/ld+json">' . wp_json_encode( array(
			'@context'  => 'https://schema.org',
			'@type'     => 'MusicEvent',
			'name'      => 'Structured Show',
			'startDate' => '2099-08-11T20:00:00-04:00',
			'location'  => array( '@type' => 'Place', 'name' => 'Test Venue' ),
		) ) . '</script></head><body><script>window[\'__canva_public_path__\'] = \'_assets\';</script></body></html>';
		$result = $this->scrape( $page );
		$this->assertSame( 'Structured Show', $result['items'][0]['title'] ?? null );
	}

	public function test_decoded_page_reaches_html_section_fallback_with_original_url(): void {
		// A linked ticket page with Event JSON-LD must not short-circuit the per-section fallback.
		$linked  = '<html><head><script type="application/ld+json">' . wp_json_encode( array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Event',
			'name'      => 'Linked Ticket Page Event',
			'startDate' => '2099-12-11T20:00:00-05:00',
			'location'  => array( '@type' => 'Place', 'name' => 'Test Venue' ),
		) ) . '</script></head><body></body></html>';
		$result  = $this->scrape( $this->fixture( 'hedhi-calendar-trimmed.html' ), array( 'https://soundarchivebooks.org/tour/sound-archive-sessions-charleston/' => $linked ) );
		$this->assertArrayNotHasKey( 'items', $result );
		$payload = json_decode( $result['content'] ?? '', true );
		$this->assertSame( self::SOURCE, $payload['source_url'] ?? null );
		$this->assertStringContainsString( 'data-canva-section', $payload['raw_html'] ?? '' );
	}

	public function test_full_page_fixture_yields_event_lines(): void {
		$html = ( new CanvaSiteResolver() )->resolve( $this->fixture( 'hedhi-calendar-full.html' ), 'https://artlocker.my.canva.site/hed-hi-studio-calendar' );
		foreach ( array( 'Sound Archive Sessions', 'ETERNAL JEST', 'Minor Threat 2', 'Jay Howell', 'Ticketed PREVIEW Event', 'Steve Hawk Book Tour', 'Bitchin Bajas', 'CLUB PARADISE EAST' ) as $line ) {
			$this->assertStringContainsString( $line, $html );
		}
		$this->assertSame( 17, substr_count( $html, '<section ' ) );
		$this->assertStringContainsString( '<a href="https://www.eventbrite.com/e/stylepublic-presents-the-den-tickets-1999124648639">LEARN MORE &amp; RSVP</a>', $html );
	}
}
