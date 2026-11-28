<?php
/**
 * PatronManager Extractor Tests
 *
 * Covers detection of PatronManager (Salesforce PatronTicket) ticket sites,
 * the Visualforce remoting fetchEvents call, and mapping of performance
 * instances to events. Fixtures are trimmed from live captures of
 * theparlorroom.my.salesforce-sites.com (Iron Horse Music Hall + The Parlor
 * Room) with the csrf/authorization tokens replaced.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\PatronManagerExtractor;

class PatronManagerExtractorTest extends WP_UnitTestCase {

	private const SITE        = 'https://theparlorroom.my.salesforce-sites.com/ticket/';
	private const ENDPOINT    = 'https://theparlorroom.my.salesforce-sites.com/ticket/apexremote';
	private const IRON_HORSE  = 'a0THn00000T4zAdMAJ';
	private const PARLOR_ROOM = 'a0THn00000T3ib8MAB';

	private string $fixtures_dir;

	/**
	 * Captured outbound requests: list of [method, url, args].
	 *
	 * @var array<int,array{0:string,1:string,2:array}>
	 */
	private array $requests = array();

	public function setUp(): void {
		parent::setUp();
		$this->fixtures_dir = __DIR__ . '/../Fixtures/patronmanager';
		$this->requests     = array();
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function extractor( array $options = array() ): PatronManagerExtractor {
		$options['now'] = new \DateTimeImmutable( '2026-11-14 12:00:00', new \DateTimeZone( 'America/New_York' ) );
		return new PatronManagerExtractor( $options );
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( $this->fixtures_dir . '/' . $name );
	}

	private function eventsResponse(): array {
		return json_decode( $this->fixture( 'fetch-events-response.json' ), true );
	}

	/**
	 * Mock the ticket page GET and the apexremote POST.
	 *
	 * @param array|string|null $events_body Decoded response (encoded here) or raw body.
	 * @param int               $status      HTTP status for the POST.
	 * @param string|null       $ticket_page Ticket page HTML (null = fixture).
	 */
	private function mockHttp( $events_body = null, int $status = 200, ?string $ticket_page = null ): void {
		$events_body = $events_body ?? $this->eventsResponse();
		$ticket_page = $ticket_page ?? $this->fixture( 'ticket-page.html' );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $events_body, $status, $ticket_page ) {
				$method           = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
				$this->requests[] = array( $method, $url, $args );

				if ( 'POST' === $method && self::ENDPOINT === $url ) {
					return array(
						'headers'  => array( 'content-type' => 'application/json' ),
						'body'     => is_string( $events_body ) ? $events_body : wp_json_encode( $events_body ),
						'response' => array(
							'code'    => $status,
							'message' => 200 === $status ? 'OK' : 'Error',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				if ( 'GET' === $method && self::SITE === $url ) {
					return array(
						'headers'  => array( 'content-type' => 'text/html' ),
						'body'     => $ticket_page,
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return new \WP_Error( 'unexpected_request', 'Unexpected request: ' . $method . ' ' . $url );
			},
			10,
			3
		);
	}

	private function postRequests(): array {
		return array_values( array_filter( $this->requests, static fn ( $r ) => 'POST' === $r[0] ) );
	}

	// ────────────────────────────────────────────────────────────────────
	// Detection
	// ────────────────────────────────────────────────────────────────────

	public function test_getMethod_identifier(): void {
		$this->assertSame( 'patronmanager', $this->extractor()->getMethod() );
	}

	public function test_canExtract_detects_direct_ticket_page(): void {
		$this->assertTrue( $this->extractor()->canExtract( $this->fixture( 'ticket-page.html' ) ) );
	}

	public function test_canExtract_detects_linking_venue_page(): void {
		$this->assertTrue( $this->extractor()->canExtract( $this->fixture( 'venue-linking-page.html' ) ) );
	}

	public function test_canExtract_detects_secure_force_ticket_link(): void {
		$html = '<a href="https://ironhorse.secure.force.com/ticket/#/events/1">Tickets</a>';
		$this->assertTrue( $this->extractor()->canExtract( $html ) );
	}

	public function test_canExtract_rejects_unrelated_and_ambiguous_pages(): void {
		$extractor = $this->extractor();
		$this->assertFalse( $extractor->canExtract( '' ) );
		$this->assertFalse( $extractor->canExtract( '<html><body><a href="https://example.com/ticket/">x</a></body></html>' ) );
		// Non-ticket Salesforce site paths are not PatronManager.
		$this->assertFalse( $extractor->canExtract( '<a href="https://foo.my.salesforce-sites.com/donate/">Give</a>' ) );
		// Links to two different orgs: cannot tell which belongs to the venue.
		$this->assertFalse(
			$extractor->canExtract(
				'<a href="https://one.my.salesforce-sites.com/ticket/">A</a><a href="https://two.my.salesforce-sites.com/ticket/">B</a>'
			)
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Extraction + mapping
	// ────────────────────────────────────────────────────────────────────

	public function test_extract_direct_ticket_page_maps_instances(): void {
		$this->mockHttp();

		$events = $this->extractor()->extract( $this->fixture( 'ticket-page.html' ), self::SITE );

		// Direct page: config read from the given HTML, only the POST is made.
		$this->assertCount( 1, $this->requests );

		$titles = array_column( $events, 'title' );
		$this->assertSame(
			array(
				'The Little Horse Family Series at The Iron Horse',
				'Aaron Lee Tasjan w/ Madeleine Kelson at The Parlor Room November 15th',
				'AJ Lee & Blue Summit w/ The Cody Sisters at The Iron Horse November 20th',
				'Beginner Ukulele w/ Glenn Weiser at The Parlor Room',
				'The Little Horse Family Series at The Iron Horse',
				'The Little Horse Family Series at The Iron Horse',
				'Livingston Taylor at The Iron Horse February 28th - 2pm',
			),
			$titles
		);
		$this->assertNotContains( 'Memberships', $titles );

		$aj = $events[2];
		$this->assertSame( '2026-11-20', $aj['startDate'] );
		$this->assertSame( '19:00', $aj['startTime'] );
		$this->assertSame( self::SITE . '#/instances/a0FV500000Lk2txMAB', $aj['ticketUrl'] );
		$this->assertSame( 'SoldOut', $aj['offerAvailability'] );
		$this->assertStringContainsString( 'SHOW: 7:00pm', $aj['description'] );
		$this->assertStringNotContainsString( '<', $aj['description'] );

		$livingston = $events[6];
		$this->assertSame( '2027-02-28', $livingston['startDate'] );
		$this->assertSame( '14:00', $livingston['startTime'] );
		$this->assertSame( 'InStock', $livingston['offerAvailability'] );

		$this->assertSame( '11:00', $events[0]['startTime'] );
		$this->assertSame( '2026-11-15', $events[0]['startDate'] );
	}

	public function test_extract_sends_visualforce_remoting_request(): void {
		$this->mockHttp();

		$this->extractor()->extract( $this->fixture( 'ticket-page.html' ), self::SITE );

		$posts = $this->postRequests();
		$this->assertCount( 1, $posts );

		$args = $posts[0][2];
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( 'Visualforce-Remoting', $args['headers']['X-User-Agent'] );
		$this->assertSame( self::SITE, $args['headers']['Referer'] );

		$body = json_decode( $args['body'], true );
		$this->assertSame( 'PatronTicket.Controller_PublicTicketApp', $body['action'] );
		$this->assertSame( 'fetchEvents', $body['method'] );
		$this->assertSame( array( self::SITE, '', '', '' ), $body['data'] );
		$this->assertSame( 'rpc', $body['type'] );
		$this->assertSame( 'TEST-CSRF-fetchEvents', $body['ctx']['csrf'] );
		$this->assertSame( 'TEST-AUTH-fetchEvents', $body['ctx']['authorization'] );
		$this->assertSame( '066Hn00000242ES', $body['ctx']['vid'] );
		$this->assertSame( 'PatronTicket', $body['ctx']['ns'] );
		$this->assertEquals( 51, $body['ctx']['ver'] );
	}

	public function test_extract_from_linking_page_fetches_ticket_site(): void {
		$this->mockHttp();

		$events = $this->extractor()->extract( $this->fixture( 'venue-linking-page.html' ), 'https://www.ironhorse.org/shows' );

		$this->assertCount( 7, $events );
		$this->assertSame( array( 'GET', self::SITE ), array_slice( $this->requests[0], 0, 2 ) );
		$this->assertSame( array( 'POST', self::ENDPOINT ), array_slice( $this->requests[1], 0, 2 ) );

		$body = json_decode( $this->requests[1][2]['body'], true );
		$this->assertSame( self::SITE, $body['data'][0] );
	}

	public function test_extract_skips_non_timed_past_and_far_future_instances(): void {
		$response = $this->eventsResponse();
		$template = $response[0]['result'][1]; // AJ Lee — a regular "Tickets" event.

		$make = static function ( string $id, string $ymd, bool $non_timed = false ) use ( $template ): array {
			$instance              = $template['instances'][0];
			$instance['id']        = 'inst-' . $id;
			$instance['eventId']   = 'evt-' . $id;
			$instance['eventName'] = 'Junk ' . $id;
			$instance['nonTimed']  = $non_timed;

			$instance['formattedDates']['YYYYMMDD'] = $ymd;

			$event              = $template;
			$event['id']        = 'evt-' . $id;
			$event['name']      = 'Junk ' . $id;
			$event['instances'] = array( $instance );
			return $event;
		};

		$response[0]['result'][] = $make( 'past', '20261101' );
		$response[0]['result'][] = $make( 'far', '20290101' );
		$response[0]['result'][] = $make( 'untimed', '20261201', true );
		$response[0]['result'][] = $make( 'baddate', 'TBD' );

		// A membership mislabelled as "Tickets" is still dropped by its 2199 date.
		$response[0]['result'][0]['type'] = 'Tickets';

		$this->mockHttp( $response );

		$titles = array_column( $this->extractor()->extract( $this->fixture( 'ticket-page.html' ), self::SITE ), 'title' );

		$this->assertCount( 7, $titles );
		foreach ( array( 'Junk past', 'Junk far', 'Junk untimed', 'Junk baddate', 'Memberships' ) as $junk ) {
			$this->assertNotContains( $junk, $titles );
		}
	}

	public function test_extract_collapses_seating_sections_and_prefers_on_sale_link(): void {
		$response = $this->eventsResponse();
		// AJ Lee: make the second seating section available.
		$response[0]['result'][1]['instances'][1]['soldOut'] = false;
		$this->mockHttp( $response );

		$events = $this->extractor()->extract( $this->fixture( 'ticket-page.html' ), self::SITE );
		$aj     = array_values( array_filter( $events, static fn ( $e ) => str_starts_with( $e['title'], 'AJ Lee' ) ) );

		$this->assertCount( 1, $aj );
		$this->assertSame( 'InStock', $aj[0]['offerAvailability'] );
		$this->assertSame( self::SITE . '#/instances/a0FV500000Lk2vZMAR', $aj[0]['ticketUrl'] );
	}

	// ────────────────────────────────────────────────────────────────────
	// Venue filter
	// ────────────────────────────────────────────────────────────────────

	public function test_venue_filter_from_constructor_option(): void {
		$this->mockHttp();

		$events = $this->extractor( array( 'venue_ids' => array( self::PARLOR_ROOM ) ) )
			->extract( $this->fixture( 'ticket-page.html' ), self::SITE );

		$this->assertCount( 1, $events );
		$this->assertSame( 'Aaron Lee Tasjan w/ Madeleine Kelson at The Parlor Room November 15th', $events[0]['title'] );
	}

	public function test_venue_filter_from_source_url_query(): void {
		$this->mockHttp();

		$events = $this->extractor()->extract(
			$this->fixture( 'venue-linking-page.html' ),
			'https://www.ironhorse.org/shows?' . PatronManagerExtractor::VENUE_QUERY_PARAM . '=' . self::IRON_HORSE
		);

		$this->assertCount( 5, $events );
		foreach ( $events as $event ) {
			$this->assertStringContainsString( 'Iron Horse', $event['title'] );
		}
	}

	// ────────────────────────────────────────────────────────────────────
	// Graceful failure
	// ────────────────────────────────────────────────────────────────────

	public function test_extract_returns_empty_when_remoting_config_missing(): void {
		$this->mockHttp( null, 200, '<html><body>Maintenance</body></html>' );

		$events = $this->extractor()->extract( $this->fixture( 'venue-linking-page.html' ), 'https://www.ironhorse.org/shows' );

		$this->assertSame( array(), $events );
		$this->assertCount( 0, $this->postRequests() );
	}

	public function test_extract_returns_empty_when_fetch_events_method_absent(): void {
		$this->mockHttp();

		$html = str_replace( '"name":"fetchEvents"', '"name":"fetchSomethingElse"', $this->fixture( 'ticket-page.html' ) );

		$this->assertSame( array(), $this->extractor()->extract( $html, self::SITE ) );
		$this->assertCount( 0, $this->postRequests() );
	}

	public function test_extract_returns_empty_on_http_error(): void {
		$this->mockHttp( '{"error":"boom"}', 500 );

		$this->assertSame( array(), $this->extractor()->extract( $this->fixture( 'ticket-page.html' ), self::SITE ) );
	}

	public function test_extract_returns_empty_on_remoting_exception(): void {
		$this->mockHttp(
			array(
				array(
					'statusCode' => 400,
					'type'       => 'exception',
					'message'    => 'Attempt to de-reference a null object',
				),
			)
		);

		$this->assertSame( array(), $this->extractor()->extract( $this->fixture( 'ticket-page.html' ), self::SITE ) );
	}
}
