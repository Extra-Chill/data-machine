<?php
/**
 * Ticketmaster Handler Tests
 *
 * Tests Ticketmaster API integration handler.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachineEvents\Core\DuplicateDetection\EventIdentityWriter;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSettings;
use ReflectionClass;

class TicketmasterHandlerTest extends WP_UnitTestCase {

	private Ticketmaster $handler;

	public function setUp(): void {
		parent::setUp();
		$this->ensureEventIdentityTables();
		$this->handler = new Ticketmaster();
		set_transient( 'data_machine_events_ticketmaster_classifications', array( 'music' => 'Music' ) );
	}

	public function tearDown(): void {
		delete_transient( 'data_machine_events_ticketmaster_classifications' );
		parent::tearDown();
	}

	public function test_handler_type() {
		$this->assertEquals( 'ticketmaster', $this->handler->getHandlerType() );
	}

	public function test_handler_extends_event_import_handler() {
		$this->assertInstanceOf(
			\DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler::class,
			$this->handler
		);
	}

	public function test_settings_class_exists() {
		$this->assertTrue( class_exists( TicketmasterSettings::class ) );
	}

	public function test_map_event_returns_array() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'      => 'Test Concert',
			'id'        => 'TM123456',
			'url'       => 'https://www.ticketmaster.com/event/123',
			'dates'     => array(
				'start'    => array(
					'localDate' => '2026-03-15',
					'localTime' => '19:30:00',
				),
				'timezone' => 'America/Denver',
			),
			'_embedded' => array(
				'venues' => array(
					array(
						'name'       => 'Test Arena',
						'address'    => array(
							'line1' => '123 Main St',
						),
						'city'       => array(
							'name' => 'Denver',
						),
						'state'      => array(
							'stateCode' => 'CO',
						),
						'postalCode' => '80202',
						'country'    => array(
							'countryCode' => 'US',
						),
						'timezone'   => 'America/Denver',
					),
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Test Concert', $result['title'] );
		$this->assertEquals( 'Test Arena', $result['venue'] );
		$this->assertEquals( '2026-03-15', $result['startDate'] );
		$this->assertEquals( '19:30', $result['startTime'] );
	}

	public function test_new_event_uses_stable_ticketmaster_id_for_processed_identity(): void {
		$handler = new TicketmasterHandlerTestDouble(
			array(
				0 => $this->ticketmasterPage( array( $this->ticketmasterEvent( 'TM-new-event', 'New Event ' . uniqid() ) ) ),
			)
		);

		$packets = $handler->get_fetch_data( 'direct', $this->handlerConfig( '32.7765,-79.9311' ) );

		$this->assertCount( 1, $packets );
		$packet = $packets[0]->addTo( array() )[0];
		$this->assertSame( 'TM-new-event', $packet['metadata']['item_identifier'] );
	}

	public function test_repeated_ticketmaster_ids_across_pages_only_schedule_once(): void {
		$repeated = $this->ticketmasterEvent( 'TM-overlap', 'Overlap Event ' . uniqid() );
		$handler  = new TicketmasterHandlerTestDouble(
			array(
				0 => $this->ticketmasterPage( array( $repeated ), 0, 2 ),
				1 => $this->ticketmasterPage(
					array(
						$repeated,
						$this->ticketmasterEvent( 'TM-distinct', 'Distinct Event ' . uniqid() ),
					),
					1,
					2
				),
			)
		);

		$packets = $handler->get_fetch_data( 'direct', $this->handlerConfig( '37.7749,-122.4194' ) );

		$this->assertCount( 2, $packets );
	}

	public function test_already_processed_ticketmaster_id_is_removed_before_packet_creation(): void {
		$processed_items = new ProcessedItems();
		$processed_items->create_table();
		$flow_step_id    = 'ticketmaster-processed-' . wp_generate_uuid4();
		$processed_items->add_processed_item( $flow_step_id, 'ticketmaster', 'TM-processed', 123 );

		$handler = new TicketmasterHandlerTestDouble(
			array(
				0 => $this->ticketmasterPage(
					array( $this->ticketmasterEvent( 'TM-processed', 'Processed Event ' . uniqid() ) )
				),
			)
		);
		$config  = array_merge(
			$this->handlerConfig( '40.7128,-74.0060' ),
			array(
				'pipeline_id'  => 1,
				'flow_id'      => 2,
				'flow_step_id' => $flow_step_id,
			)
		);

		$this->assertCount( 0, $handler->get_fetch_data( 1, $config, '123' ) );
	}

	public function test_overlapping_city_queries_skip_event_already_imported_by_neighboring_city(): void {
		$title      = 'Neighboring City Event ' . uniqid();
		$ticket_url = 'https://www.ticketmaster.com/event/TM-neighboring-city';
		$raw_event  = $this->ticketmasterEvent( 'TM-neighboring-city', $title, $ticket_url );

		$new_handler = new TicketmasterHandlerTestDouble(
			array( 0 => $this->ticketmasterPage( array( $raw_event ) ) )
		);
		$this->assertCount(
			1,
			$new_handler->get_fetch_data( 'direct', $this->handlerConfig( '37.7749,-122.4194' ) ),
			'A new Ticketmaster event must remain eligible.'
		);

		[ $post_id, $term_id ] = $this->seedImportedEvent( $title, $ticket_url );
		$logs                  = array();
		$logger                = static function ( string $level, string $message, array $context ) use ( &$logs ): void {
			$level;
			if ( 'Ticketmaster: Import fan-out summary' === $message ) {
				$logs[] = $context;
			}
		};
		add_action( 'datamachine_log', $logger, 10, 3 );

		$san_francisco = new TicketmasterHandlerTestDouble(
			array( 0 => $this->ticketmasterPage( array( $raw_event ) ) )
		);
		$oakland       = new TicketmasterHandlerTestDouble(
			array( 0 => $this->ticketmasterPage( array( $raw_event ) ) )
		);

		$this->assertCount( 0, $san_francisco->get_fetch_data( 'direct', $this->handlerConfig( '37.7749,-122.4194' ) ) );
		$this->assertCount( 0, $oakland->get_fetch_data( 'direct', $this->handlerConfig( '37.8044,-122.2712' ) ) );

		remove_action( 'datamachine_log', $logger, 10 );
		$this->assertCount( 2, $logs );
		$this->assertSame( 1, $logs[0]['fetched'] );
		$this->assertSame( 1, $logs[0]['pre_fanout_deduped'] );
		$this->assertSame( 1, $logs[0]['existing_events'] );
		$this->assertSame( 0, $logs[0]['schedule_candidates'] );

		wp_delete_post( $post_id, true );
		wp_delete_term( $term_id, 'venue' );
	}

	public function test_default_fanout_is_bounded_to_one_hundred_packets(): void {
		$events = array();
		for ( $index = 0; $index < 101; ++$index ) {
			$events[] = $this->ticketmasterEvent( 'TM-bound-' . $index, 'Bounded Event ' . $index . ' ' . uniqid() );
		}

		$handler = new TicketmasterHandlerTestDouble(
			array( 0 => $this->ticketmasterPage( $events ) )
		);

		$this->assertCount( 100, $handler->get_fetch_data( 'direct', $this->handlerConfig( '34.0522,-118.2437' ) ) );
	}

	public function test_map_event_handles_missing_venue() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'  => 'No Venue Event',
			'id'    => 'TM789',
			'dates' => array(
				'start' => array(
					'localDate' => '2026-04-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'No Venue Event', $result['title'] );
		$this->assertEquals( '', $result['venue'] ?? '' );
	}

	public function test_map_event_handles_price_ranges() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'        => 'Priced Event',
			'id'          => 'TM456',
			'priceRanges' => array(
				array(
					'min'      => 25.00,
					'max'      => 75.00,
					'currency' => 'USD',
				),
			),
			'dates'       => array(
				'start' => array(
					'localDate' => '2026-05-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['price'] ?? '' );
	}

	public function test_map_event_formats_price_correctly() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'        => 'Price Format Test',
			'id'          => 'TM789',
			'priceRanges' => array(
				array(
					'min'      => 50.00,
					'max'      => 50.00,
					'currency' => 'USD',
				),
			),
			'dates'       => array(
				'start' => array(
					'localDate' => '2026-06-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'price', $result );
		$this->assertEquals( '$50.00', $result['price'] );
	}

	public function test_map_event_handles_missing_price() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'  => 'No Price Event',
			'id'    => 'TM999',
			'dates' => array(
				'start' => array(
					'localDate' => '2026-07-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'price', $result );
		$this->assertEquals( '', $result['price'] );
	}

	public function test_is_rate_limited_detects_429_from_error_message() {
		$method = $this->getProtectedMethod( 'is_rate_limited' );

		$result = array(
			'success' => false,
			'error'   => 'Ticketmaster GET returned HTTP 429: {"fault":{"faultstring":"Spike arrest violation"}}',
		);

		$this->assertTrue( $method->invoke( $this->handler, $result ) );
	}

	public function test_is_rate_limited_detects_429_from_status_code() {
		$method = $this->getProtectedMethod( 'is_rate_limited' );

		$result = array(
			'success'     => false,
			'status_code' => 429,
			'error'       => 'throttled',
		);

		$this->assertTrue( $method->invoke( $this->handler, $result ) );
	}

	public function test_is_rate_limited_false_for_success() {
		$method = $this->getProtectedMethod( 'is_rate_limited' );

		$result = array(
			'success'     => true,
			'status_code' => 200,
			'data'        => '{}',
		);

		$this->assertFalse( $method->invoke( $this->handler, $result ) );
	}

	public function test_is_rate_limited_false_for_other_errors() {
		$method = $this->getProtectedMethod( 'is_rate_limited' );

		$result = array(
			'success' => false,
			'error'   => 'Ticketmaster GET returned HTTP 500: server error',
		);

		$this->assertFalse( $method->invoke( $this->handler, $result ) );
	}

	public function test_retry_after_seconds_parses_delta_seconds_header() {
		$method = $this->getProtectedMethod( 'retry_after_seconds' );

		$result = array(
			'success' => false,
			'headers' => array( 'Retry-After' => '5' ),
		);

		$this->assertSame( 5, $method->invoke( $this->handler, $result ) );
	}

	public function test_retry_after_seconds_is_case_insensitive() {
		$method = $this->getProtectedMethod( 'retry_after_seconds' );

		$result = array(
			'success' => false,
			'headers' => array( 'retry-after' => '3' ),
		);

		$this->assertSame( 3, $method->invoke( $this->handler, $result ) );
	}

	public function test_retry_after_seconds_null_when_absent() {
		$method = $this->getProtectedMethod( 'retry_after_seconds' );

		$result = array(
			'success' => false,
			'error'   => 'Ticketmaster GET returned HTTP 429: throttled',
		);

		$this->assertNull( $method->invoke( $this->handler, $result ) );
	}

	public function test_backoff_grows_exponentially_and_is_clamped() {
		$method = $this->getProtectedMethod( 'rate_limit_backoff_seconds' );

		$result = array(
			'success' => false,
			'error'   => 'Ticketmaster GET returned HTTP 429: throttled',
		);

		// Without a Retry-After header, delay grows with the attempt index and
		// is clamped to RATE_LIMIT_BACKOFF_MAX_SECONDS. Jitter adds 0 or 1s.
		$delay0 = $method->invoke( $this->handler, $result, 0 );
		$delay3 = $method->invoke( $this->handler, $result, 3 );

		$this->assertGreaterThanOrEqual( 1, $delay0 );
		$this->assertLessThanOrEqual( 8, $delay0 );
		$this->assertLessThanOrEqual( 8, $delay3 );
	}

	public function test_backoff_respects_retry_after_header() {
		$method = $this->getProtectedMethod( 'rate_limit_backoff_seconds' );

		$result = array(
			'success' => false,
			'headers' => array( 'Retry-After' => '4' ),
		);

		$this->assertSame( 4, $method->invoke( $this->handler, $result, 0 ) );
	}

	public function test_backoff_clamps_oversized_retry_after_header() {
		$method = $this->getProtectedMethod( 'rate_limit_backoff_seconds' );

		$result = array(
			'success' => false,
			'headers' => array( 'Retry-After' => '600' ),
		);

		$this->assertSame( 8, $method->invoke( $this->handler, $result, 0 ) );
	}

	private function getProtectedMethod( string $name ) {
		$reflection = new ReflectionClass( $this->handler );
		$method     = $reflection->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}

	public function test_register_junk_patterns_seeds_ticketmaster_defaults() {
		$result = $this->handler->register_junk_patterns( array(), 'ticketmaster' );

		$this->assertContains( 'CCPER-', $result['id'] );
		$this->assertContains( 'CCPER-', $result['title'] );
		$this->assertContains( 'Standalone Upsell', $result['title'] );
		$this->assertContains( 'Test Event', $result['title'] );
		$this->assertContains( 'Upcoming Event', $result['title_prefix_no_artist'] );
		$this->assertTrue( $result['honor_test_flag'] );
	}

	public function test_register_junk_patterns_ignores_other_sources() {
		$empty = array( 'id' => array(), 'title' => array() );
		$result = $this->handler->register_junk_patterns( $empty, 'dice' );

		$this->assertSame( $empty, $result );
	}

	public function test_junk_patterns_exposed_via_filter() {
		$patterns = apply_filters( 'data_machine_events_junk_payload_patterns', array(), 'ticketmaster' );

		$this->assertNotEmpty( $patterns['id'] );
		$this->assertNotEmpty( $patterns['title'] );
		$this->assertNotEmpty( $patterns['title_prefix_no_artist'] );
	}

	public function test_is_junk_payload_drops_ccper_event() {
		$filter = new \DataMachineEvents\Steps\EventImport\JunkPayloadFilter();
		$this->assertTrue(
			$filter->is_junk(
				array(
					'source_id'        => 'CCPER-2756',
					'title'            => 'Upcoming Event CCPER-2756',
					'artist'           => '',
					'is_explicit_test' => false,
				),
				'ticketmaster'
			)
		);
	}

	public function test_is_junk_payload_drops_explicit_test_flag() {
		$filter = new \DataMachineEvents\Steps\EventImport\JunkPayloadFilter();
		$this->assertTrue(
			$filter->is_junk(
				array(
					'source_id'        => 'Z5xNormal',
					'title'            => 'Some Real Event',
					'artist'           => 'Real Artist',
					'is_explicit_test' => true,
				),
				'ticketmaster'
			)
		);
	}

	public function test_is_junk_payload_keeps_normal_event() {
		$filter = new \DataMachineEvents\Steps\EventImport\JunkPayloadFilter();
		$this->assertFalse(
			$filter->is_junk(
				array(
					'source_id'        => 'vvG1hZwAd_k-p',
					'title'            => 'Phish - Summer Tour 2026',
					'artist'           => 'Phish',
					'is_explicit_test' => false,
				),
				'ticketmaster'
			)
		);
	}

	private function handlerConfig( string $location ): array {
		return array(
			'pipeline_id'        => 'direct',
			'flow_id'            => 'direct',
			'classification_type' => 'music',
			'location'           => $location,
		);
	}

	private function ticketmasterEvent( string $id, string $title, string $ticket_url = '' ): array {
		return array(
			'id'    => $id,
			'name'  => $title,
			'url'   => '' !== $ticket_url ? $ticket_url : 'https://www.ticketmaster.com/event/' . rawurlencode( $id ),
			'dates' => array(
				'status' => array( 'code' => 'onsale' ),
				'start'  => array(
					'localDate' => '2027-08-15',
					'localTime' => '20:00:00',
				),
			),
			'_embedded' => array(
				'venues' => array(
					array(
						'name'     => 'Overlap Arena',
						'timezone' => 'America/Los_Angeles',
						'address'  => array( 'line1' => '1 Music Way' ),
						'city'     => array( 'name' => 'Oakland' ),
						'state'    => array( 'stateCode' => 'CA' ),
					),
				),
			),
		);
	}

	private function ticketmasterPage( array $events, int $number = 0, int $total_pages = 1 ): array {
		return array(
			'events' => $events,
			'page'   => array(
				'number'     => $number,
				'totalPages' => $total_pages,
			),
		);
	}

	private function ensureEventIdentityTables(): void {
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		( new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex() )->create_table();
	}

	private function seedImportedEvent( string $title, string $ticket_url ): array {
		$term = wp_insert_term( 'Overlap Arena ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, '_venue_address', '1 Music Way' );
		update_term_meta( $term_id, '_venue_city', 'Oakland' );

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		wp_set_object_terms( $post_id, array( $term_id ), 'venue' );
		update_post_meta( $post_id, \DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY, $ticket_url );
		EventDatesTable::upsert( $post_id, '2027-08-15 20:00:00' );
		EventIdentityWriter::syncIdentityRow( $post_id, $title, $ticket_url );

		return array( $post_id, $term_id );
	}
}

class TicketmasterHandlerTestDouble extends Ticketmaster {

	private array $pages;

	public function __construct( array $pages ) {
		$this->pages = $pages;
		parent::__construct();
	}

	protected function getAuthProvider( string $provider_key ): ?object {
		$provider_key;
		return new class() {
			public function get_account(): array {
				return array( 'api_key' => 'test-key' );
			}
		};
	}

	protected function fetch_events( array $params, ExecutionContext $context ): array {
		$context;
		return $this->pages[ (int) ( $params['page'] ?? 0 ) ] ?? array(
			'events' => array(),
			'page'   => array(
				'number'     => (int) ( $params['page'] ?? 0 ),
				'totalPages' => 0,
			),
		);
	}
}
