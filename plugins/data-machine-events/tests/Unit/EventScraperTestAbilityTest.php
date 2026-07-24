<?php
/**
 * EventScraperTest Ability — qualify-path regression tests.
 *
 * Issue #265: the test-event-scraper ability was reading only the first
 * DataPacket returned by UniversalWebScraper::get_fetch_data() and
 * discarding the rest, so multi-event calendars (Bandzoogle, multi-event
 * JSON-LD pages, Tribe REST, etc.) appeared to qualify v2 as `events: 1`
 * regardless of how many events were actually extracted. The fix walks
 * every packet and surfaces the full count via event_data.items[],
 * event_data.event_count, and extraction_info.event_count.
 *
 * These tests cover:
 *   1. The internal summarizer (summarizeEventsFromPackets) — gives a
 *      deterministic unit-level check independent of the HTTP layer.
 *   2. End-to-end runs against committed Bandzoogle and JSON-LD fixtures,
 *      with the HTTP layer mocked via the `pre_http_request` filter so
 *      UniversalWebScraper::fetch_html() returns the fixture content.
 *      Each end-to-end test asserts the ability's count matches what the
 *      underlying extractor's direct `extract()` call returns on the same
 *      HTML, including the inverse case (single-event JSON-LD page should
 *      still report exactly 1).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.36.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use ReflectionClass;
use DataMachineEvents\Abilities\EventScraperTest;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\BandzoogleExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\JsonLdExtractor;

class EventScraperTestAbilityTest extends WP_UnitTestCase {

	private string $fixtures_dir;

	public function setUp(): void {
		parent::setUp();
		$this->fixtures_dir = dirname( __DIR__ ) . '/Fixtures';
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_ability_is_registered_with_complete_context_schema(): void {
		$ability = wp_get_ability( 'data-machine-events/test-event-scraper' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'data-machine-events/test-event-scraper', $ability->get_name() );

		$input = $ability->get_input_schema();
		$this->assertSame( array( 'target_url' ), $input['required'] );
		$this->assertArrayHasKey( 'handler_config', $input['properties'] );
		$this->assertArrayHasKey( 'exclude_keywords', $input['properties']['handler_config']['properties'] );
		$this->assertArrayNotHasKey( 'flow_step_id', $input['properties'] );

		$output = $ability->get_output_schema();
		$this->assertArrayHasKey( 'event_data', $output['properties'] );
		$this->assertArrayHasKey( 'items', $output['properties']['event_data']['properties'] );
		$this->assertArrayHasKey( 'extraction_info', $output['properties'] );
		$this->assertArrayHasKey( 'context_supplied', $output['properties']['extraction_info']['properties'] );
		$this->assertArrayHasKey( 'duplicate_packet_count', $output['properties']['extraction_info']['properties'] );
		$this->assertArrayHasKey( 'production_max_items', $output['properties']['extraction_info']['properties'] );
		$this->assertArrayHasKey( 'candidate_packet_count', $output['properties']['extraction_info']['properties'] );
		$this->assertArrayHasKey( 'raw_section_count', $output['properties']['extraction_info']['properties'] );
		$this->assertArrayHasKey( 'flyer_candidate_count', $output['properties']['extraction_info']['properties'] );
		$this->assertArrayNotHasKey( 'summary_truncated', $output['properties']['extraction_info']['properties'] );
		$this->assertArrayNotHasKey( 'processed_event_count', $output['properties']['extraction_info']['properties'] );
		$this->assertArrayNotHasKey( 'eligible_event_count', $output['properties']['extraction_info']['properties'] );
	}

	public function test_ability_permission_requires_manage_options(): void {
		$ability = wp_get_ability( 'data-machine-events/test-event-scraper' );
		$this->assertNotNull( $ability );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$this->assertFalse( $ability->check_permissions( array( 'target_url' => 'https://example.com/events' ) ) );
		$denied = $ability->execute( array( 'target_url' => 'https://example.com/events' ) );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'ability_invalid_permissions', $denied->get_error_code() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->assertTrue( $ability->check_permissions( array( 'target_url' => 'https://example.com/events' ) ) );
	}

	public function test_ability_rejects_missing_target_url_from_schema(): void {
		$ability = wp_get_ability( 'data-machine-events/test-event-scraper' );
		$this->assertNotNull( $ability );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$result = $ability->execute( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	// ────────────────────────────────────────────────────────────────────
	// Unit-level: summarizer reads every packet's event payload
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Synthesizes the exact packet entry shape produced by
	 * StructuredDataProcessor::process() → FetchHandler::toDataPackets() →
	 * DataPacket::addTo() and asserts the summarizer counts each packet's
	 * inner event correctly.
	 *
	 * This is the lowest-level guarantee that the fix cannot regress to
	 * "first packet only" without breaking this test.
	 */
	public function test_summarizer_counts_every_packet_event() {
		$ability = new EventScraperTest();
		$method  = ( new ReflectionClass( $ability ) )->getMethod( 'summarizeEventsFromPackets' );
		$method->setAccessible( true );

		$packets = array();
		foreach ( range( 1, 5 ) as $i ) {
			$packets[] = $this->buildPacketEntry(
				array(
					'title'     => 'Event ' . $i,
					'startDate' => sprintf( '2026-06-%02d', $i ),
					'startTime' => '20:00',
					'ticketUrl' => 'https://example.com/tickets/' . $i,
				)
			);
		}

		$summary = $method->invoke( $ability, $packets );

		$this->assertCount( 5, $summary, 'Summarizer must return one entry per packet.' );
		$this->assertSame( 'Event 1', $summary[0]['title'] );
		$this->assertSame( '2026-06-05', $summary[4]['startDate'] );
	}

	/**
	 * Skipping non-event packets (raw_html / vision_flyer / malformed JSON)
	 * keeps the summary list focused on extractable structured events.
	 */
	public function test_summarizer_skips_non_event_packets() {
		$ability = new EventScraperTest();
		$method  = ( new ReflectionClass( $ability ) )->getMethod( 'summarizeEventsFromPackets' );
		$method->setAccessible( true );

		$packets = array(
			$this->buildPacketEntry( array( 'title' => 'Real Event', 'startDate' => '2026-06-01' ) ),
			array( 'data' => array( 'body' => '' ) ),
			array( 'data' => array( 'body' => 'not-json' ) ),
			array( 'data' => array( 'body' => wp_json_encode( array( 'raw_html' => '<p>section</p>' ) ) ) ),
		);

		$summary = $method->invoke( $ability, $packets );

		$this->assertCount( 1, $summary, 'Only the one decodable event packet should be summarized.' );
		$this->assertSame( 'Real Event', $summary[0]['title'] );
	}

	/**
	 * Regression for #511: qualification must apply the production config,
	 * collapse repeated source records by stable identifier, and explicitly
	 * report that handler context was supplied.
	 */
	public function test_qualification_matches_production_config_and_stable_identity() {
		$html = '<script type="application/ld+json">' . wp_json_encode(
			array(
				array(
					'@context'  => 'https://schema.org',
					'@type'     => 'MusicEvent',
					'name'      => 'Repeated Concert',
					'startDate' => '2030-08-01T20:00:00-04:00',
				),
				array(
					'@context'  => 'https://schema.org',
					'@type'     => 'MusicEvent',
					'name'      => 'Repeated Concert',
					'startDate' => '2030-08-01T20:00:00-04:00',
				),
				array(
					'@context'  => 'https://schema.org',
					'@type'     => 'MusicEvent',
					'name'      => 'Trivia Club',
					'startDate' => '2030-08-02T20:00:00-04:00',
				),
			)
		) . '</script>';

		$this->mockHttpResponse( $html );

		$config = array(
			'exclude_keywords' => 'Trivia Club',
			'venue_name'        => 'Test Venue',
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$ability = wp_get_ability( 'data-machine-events/test-event-scraper' );
		$this->assertNotNull( $ability );
		$result = $ability->execute(
			array(
				'target_url'     => 'https://example.com/events',
				'handler_config' => $config,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['extraction_info']['extracted_packet_count'] );
		$this->assertSame( 1, $result['extraction_info']['unique_source_event_count'] );
		$this->assertSame( 1, $result['extraction_info']['duplicate_packet_count'] );
		$this->assertTrue( $result['extraction_info']['context_supplied'] );
		$this->assertSame( 1, $result['event_data']['event_count'] );
		$this->assertCount( 1, $result['event_data']['items'] );
	}

	public function test_max_items_does_not_cap_source_inventory_or_full_items_list(): void {
		$events = array();
		foreach ( range( 1, 105 ) as $index ) {
			$events[] = array(
				'@context'  => 'https://schema.org',
				'@type'     => 'MusicEvent',
				'name'      => 'Inventory Event ' . $index,
				'startDate' => '2030-09-01T20:00:00-04:00',
			);
		}

		$html = '<script type="application/ld+json">' . wp_json_encode( $events ) . '</script>';
		$this->mockHttpResponse( $html );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$ability = wp_get_ability( 'data-machine-events/test-event-scraper' );
		$this->assertNotNull( $ability );
		$result = $ability->execute(
			array(
				'target_url'     => 'https://example.com/large-calendar',
				'handler_config' => array(
					'max_items'  => 1,
					'venue_name' => 'Inventory Venue',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 105, $result['extraction_info']['extracted_packet_count'] );
		$this->assertSame( 105, $result['extraction_info']['unique_source_event_count'] );
		$this->assertSame( 1, $result['extraction_info']['production_max_items'] );
		$this->assertSame( 0, $result['extraction_info']['candidate_packet_count'] );
		$this->assertSame( 105, $result['event_data']['event_count'] );
		$this->assertCount( 105, $result['event_data']['items'] );
	}

	public function test_raw_and_vision_packets_report_truthful_source_counts(): void {
		$ability = new EventScraperTest();
		$method  = ( new ReflectionClass( $ability ) )->getMethod( 'analyzePacketEntries' );
		$method->setAccessible( true );

		$raw = $method->invoke(
			$ability,
			array( $this->buildNonEventPacketEntry( array( 'raw_html' => '<article>Event</article>' ), 'raw-section' ) ),
			false,
			null
		);
		$vision = $method->invoke(
			$ability,
			array(
				$this->buildNonEventPacketEntry(
					array(
						'source_type' => 'vision_flyer',
						'image_url'   => 'https://example.com/flyer.jpg',
					),
					'vision-flyer'
				),
			),
			true,
			5
		);

		foreach ( array( $raw, $vision ) as $inventory ) {
			$this->assertSame( 1, $inventory['extraction_info']['extracted_packet_count'] );
			$this->assertSame( 0, $inventory['extraction_info']['event_count'] );
			$this->assertSame( 0, $inventory['extraction_info']['unique_source_event_count'] );
			$this->assertSame( 0, $inventory['extraction_info']['duplicate_packet_count'] );
			$this->assertSame( 1, $inventory['extraction_info']['candidate_packet_count'] );
		}
		$this->assertSame( 1, $raw['extraction_info']['raw_section_count'] );
		$this->assertSame( 0, $raw['extraction_info']['flyer_candidate_count'] );
		$this->assertNull( $raw['extraction_info']['production_max_items'] );
		$this->assertFalse( $raw['extraction_info']['context_supplied'] );
		$this->assertSame( 0, $vision['extraction_info']['raw_section_count'] );
		$this->assertSame( 1, $vision['extraction_info']['flyer_candidate_count'] );
		$this->assertSame( 5, $vision['extraction_info']['production_max_items'] );
		$this->assertTrue( $vision['extraction_info']['context_supplied'] );
	}

	// ────────────────────────────────────────────────────────────────────
	// End-to-end: ability count matches direct extractor count
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Regression for #265.
	 *
	 * For the committed Bandzoogle Elephant Room snapshot, the ability MUST
	 * report the same count BandzoogleExtractor::extract() returns directly
	 * on the same HTML. Prior to the fix this returned 1 regardless of how
	 * many events the snapshot contained.
	 */
	public function test_test_event_scraper_returns_correct_event_count_for_bandzoogle_fixture() {
		$fixture_path = $this->fixtures_dir . '/bandzoogle-elephant-room.html';
		if ( ! file_exists( $fixture_path ) ) {
			$this->markTestSkipped( 'Bandzoogle fixture not present.' );
		}

		$html         = (string) file_get_contents( $fixture_path );
		$future_year  = (int) current_time( 'Y' ) + 1;
		$html         = str_replace( '2026', (string) $future_year, $html );
		$target_url   = 'https://elephantroom.com/calendar';
		$direct       = ( new BandzoogleExtractor() )->extract( $html, $target_url );
		$direct_count = count( $direct );

		// Sanity: fixture must contain >1 event, otherwise this test cannot
		// catch the regression it's named after.
		$this->assertGreaterThan(
			1,
			$direct_count,
			'Bandzoogle fixture must contain multiple events to exercise the qualify-path fix.'
		);

		$this->mockHttpResponse( $html );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$ability = wp_get_ability( 'data-machine-events/test-event-scraper' );
		$this->assertNotNull( $ability );
		$result = $ability->execute( array( 'target_url' => $target_url ) );

		$this->assertIsArray( $result, 'Ability must succeed against the Bandzoogle fixture.' );
		$this->assertTrue( $result['success'] ?? false, 'Ability must report success.' );
		$this->assertSame(
			$direct_count,
			$result['event_data']['event_count'] ?? null,
			'event_data.event_count must match BandzoogleExtractor::extract() count exactly.'
		);
		$this->assertSame(
			$direct_count,
			count( $result['event_data']['items'] ?? array() ),
			'event_data.items[] length must match BandzoogleExtractor::extract() count exactly.'
		);
		$this->assertSame(
			$direct_count,
			$result['extraction_info']['event_count'] ?? null,
			'extraction_info.event_count must mirror event_data.event_count.'
		);
	}

	/**
	 * Regression for the JSON-LD half of #265.
	 *
	 * Multi-event JSON-LD pages (Charleston Pour House — 12 events in the
	 * committed fixture) must report the same count JsonLdExtractor returns
	 * directly. Previously the ability undercounted these the same way as
	 * Bandzoogle calendars.
	 */
	public function test_test_event_scraper_returns_correct_count_for_jsonld_fixture() {
		$fixture_path = $this->fixtures_dir . '/ld+json/charleston-pour-house.html';
		if ( ! file_exists( $fixture_path ) ) {
			$this->markTestSkipped( 'Charleston Pour House JSON-LD fixture not present.' );
		}

		$html         = (string) file_get_contents( $fixture_path );
		$html         = str_replace( '2026-', ( (int) gmdate( 'Y' ) + 5 ) . '-', $html );
		$target_url   = 'https://charlestonpourhouse.com';
		$direct       = ( new JsonLdExtractor() )->extract( $html, $target_url );
		$direct_count = count( $direct );

		$this->assertGreaterThan(
			1,
			$direct_count,
			'JSON-LD fixture must contain multiple events to exercise this regression.'
		);

		$this->mockHttpResponse( $html );

		$ability = new EventScraperTest();
		$result  = $ability->test( $target_url );

		$this->assertIsArray( $result, 'Ability must succeed against the JSON-LD fixture.' );
		$this->assertTrue( $result['success'] ?? false, 'Ability must report success.' );
		$this->assertSame(
			$direct_count,
			$result['event_data']['event_count'] ?? null,
			'event_data.event_count must match JsonLdExtractor::extract() count exactly.'
		);
		$this->assertSame(
			$direct_count,
			count( $result['event_data']['items'] ?? array() ),
			'event_data.items[] length must match JsonLdExtractor::extract() count exactly.'
		);
	}

	/**
	 * Inverse-regression guard: a single-event JSON-LD page (Royal American
	 * detail page in #264's fixture set) must STILL report exactly 1. This
	 * protects against an over-counting bug where the fix could
	 * accidentally double-count via both `items[]` and the top-level event
	 * record, or where a future refactor could insert spurious entries.
	 */
	public function test_test_event_scraper_returns_correct_count_for_single_event_jsonld() {
		$fixture_path = $this->fixtures_dir . '/ld+json/royal-american-single-event.html';
		if ( ! file_exists( $fixture_path ) ) {
			$this->markTestSkipped( 'Royal American single-event fixture not present.' );
		}

		$html         = (string) file_get_contents( $fixture_path );
		$target_url   = 'https://royalamerican.com/events/single';
		$direct       = ( new JsonLdExtractor() )->extract( $html, $target_url );
		$direct_count = count( $direct );

		$this->assertSame(
			1,
			$direct_count,
			'Single-event JSON-LD fixture must yield exactly 1 event from JsonLdExtractor.'
		);

		$this->mockHttpResponse( $html );

		$ability = new EventScraperTest();
		$result  = $ability->test( $target_url );

		$this->assertIsArray( $result, 'Ability must succeed against the single-event fixture.' );
		$this->assertTrue( $result['success'] ?? false, 'Ability must report success.' );
		$this->assertSame(
			1,
			$result['event_data']['event_count'] ?? null,
			'Single-event JSON-LD page must report event_count of exactly 1.'
		);
		$this->assertCount(
			1,
			$result['event_data']['items'] ?? array(),
			'Single-event JSON-LD page must produce items[] with exactly one entry.'
		);
		$this->assertFalse( $result['extraction_info']['context_supplied'] );
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Build a packet entry shaped like DataPacket::addTo() output, with a
	 * body that matches StructuredDataProcessor::process()'s JSON layout.
	 *
	 * @param array $event Event fields (title / startDate / startTime / ticketUrl).
	 * @return array Packet entry.
	 */
	private function buildPacketEntry( array $event ): array {
		return array(
			'type'      => 'fetch',
			'timestamp' => time(),
			'data'      => array(
				'title' => $event['title'] ?? '',
				'body'  => wp_json_encode(
					array(
						'event'             => $event,
						'import_source'     => 'universal_web_scraper',
						'extraction_method' => 'test',
					)
				),
			),
			'metadata'  => array(
				'source_type' => 'universal_web_scraper',
			),
		);
	}

	private function buildNonEventPacketEntry( array $payload, string $identifier ): array {
		return array(
			'data'     => array(
				'title' => 'Diagnostic Packet',
				'body'  => wp_json_encode( $payload ),
			),
			'metadata' => array(
				'item_identifier' => $identifier,
				'source_type'     => 'universal_web_scraper',
			),
		);
	}

	/**
	 * Short-circuit WP_Http so UniversalWebScraper::fetch_html() returns
	 * the supplied HTML instead of making a network call. Returns a
	 * synthetic 200 response shaped to satisfy DataMachine\Core\HttpClient::get().
	 */
	private function mockHttpResponse( string $html ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $html ) {
				return array(
					'headers'  => array(),
					'body'     => $html,
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
}
