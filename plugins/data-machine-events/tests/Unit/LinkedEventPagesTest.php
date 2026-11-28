<?php
namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\LinkedEventPages;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use WP_UnitTestCase;

class LinkedEventPagesTest extends WP_UnitTestCase {
	private const SOURCE = 'https://venue.example/events';
	private array $requests = array();

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function fixture( string $name ): string {
		return file_get_contents( __DIR__ . '/../Fixtures/linked-events/' . $name . '.html' );
	}

	private function mockHttp( string $listing ): void {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $listing ) {
			$this->requests[] = $url;
			if ( self::SOURCE === $url ) {
				$body = $listing;
			} elseif ( str_contains( $url, 'show-one' ) ) {
				$body = $this->fixture( 'detail' );
			} elseif ( str_contains( $url, 'show-two' ) ) {
				$body = str_replace( 'Kulture Klash', 'Bingo Loco', $this->fixture( 'detail' ) );
			} else {
				$body = '<script type="application/ld+json">{"@type":"Organization","name":"Not an event"}</script>';
			}
			return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200 ) );
		}, 10, 3 );
	}

	private function scrape( array $config = array() ): array {
		$method = new \ReflectionMethod( UniversalWebScraper::class, 'executeFetch' );
		return $method->invoke( new UniversalWebScraper(), $config + array( 'source_url' => self::SOURCE ), ExecutionContext::direct( 'universal_web_scraper' ) );
	}

	public function test_linked_events_are_normalized_and_processed_after_stale_structured_data(): void {
		$stale = str_replace( '2035-11-14', '2020-11-14', $this->fixture( 'detail' ) );
		$this->mockHttp( $stale . $this->fixture( 'listing' ) );
		$result = $this->scrape();
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( array( 'Kulture Klash', 'Bingo Loco' ), array_column( $result['items'], 'title' ) );
		$first = $result['items'][0];
		$event = json_decode( $first['content'], true )['raw_source'];
		$this->assertSame( '18:00', $event['startTime'] );
		$this->assertSame( '-05:00', $event['startOffset'] );
		$this->assertSame( 'Charleston', $event['venueCity'] );
		$this->assertSame( 'Test Band', $event['performer'] );
		$this->assertSame( 'https://tickets.example/show-one?eid=1', $event['ticketUrl'] );
		$this->assertSame( 'linked_event_pages+jsonld', $first['metadata']['extraction_method'] );
		$this->assertNotEmpty( $first['metadata']['item_identifier'] );
		$this->assertNotSame( $first['metadata']['item_identifier'], $result['items'][1]['metadata']['item_identifier'] );
		$this->assertCount( 4, $this->requests );
	}

	public function test_filtered_structured_events_do_not_block_link_discovery(): void {
		$this->mockHttp( $this->fixture( 'detail' ) . $this->fixture( 'listing' ) );
		$result = $this->scrape( array( 'search' => 'Bingo' ) );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Bingo Loco', $result['items'][0]['title'] );
	}

	public function test_usable_structured_events_do_not_fetch_links(): void {
		$this->mockHttp( $this->fixture( 'detail' ) . $this->fixture( 'listing' ) );
		$this->assertCount( 1, $this->scrape()['items'] );
		$this->assertSame( array( self::SOURCE ), $this->requests );
	}

	public function test_cap_spans_calls_and_reset_starts_new_cycle(): void {
		$this->mockHttp( '' );
		$html = '';
		for ( $i = 0; $i < 30; ++$i ) {
			$html .= '<a href="https://tickets.example/detail-' . $i . '">Details</a>';
		}
		$stage = new LinkedEventPages();
		$this->assertSame( array(), $stage->extract( $html, self::SOURCE ) );
		$stage->extract( $html, self::SOURCE );
		$this->assertCount( 25, $this->requests );
		$stage->resetBudget();
		$stage->extract( $html, self::SOURCE );
		$this->assertCount( 50, $this->requests );
	}

	public function test_no_candidates_preserves_html_section_fallback(): void {
		$this->mockHttp( '<html><body><div class="event"><h2>Community Night</h2><p>Join our community for an evening of entertainment and refreshments. Details coming soon.</p></div></body></html>' );
		$result = $this->scrape();
		$this->assertSame( 'Raw HTML Event Section', $result['title'] );
		$this->assertSame( array( self::SOURCE ), $this->requests );
	}

	public function test_no_candidates_preserves_empty_fallback_result(): void {
		$this->mockHttp( '<html><body>No events available.</body></html>' );
		$this->assertSame( array(), $this->scrape() );
		$this->assertSame( array( self::SOURCE ), $this->requests );
	}
}
