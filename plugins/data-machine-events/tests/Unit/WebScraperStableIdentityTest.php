<?php
/**
 * Web scraper stable source identity tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

require_once __DIR__ . '/EventUpsertTestCase.php';

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\EngineData;
use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Api\Controllers\Calendar;
use DataMachineEvents\Core\DuplicateDetection\EventIdentity;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\JsonLdExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\SquarespaceExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\StructuredDataProcessor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;

class WebScraperStableIdentityTest extends EventUpsertTestCase {

	private const SOURCE_URL = 'https://example.com/events';
	private ExecutionContext $context;
	private string $flow_step_id;

	public function setUp(): void {
		parent::setUp();
		$this->flow_step_id = 'scraper-stable-' . wp_generate_uuid4();
		$this->context      = ExecutionContext::fromFlow( 219, 219, $this->flow_step_id, null, 'universal_web_scraper' );
		( new ProcessedItems() )->create_table();
	}

	public function tearDown(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . ProcessedItems::TABLE_NAME, array( 'flow_step_id' => $this->flow_step_id ), array( '%s' ) );
		parent::tearDown();
	}

	public function test_jsonld_id_reimport_updates_same_post_after_title_edit(): void {
		$raw        = $this->rawEvent();
		$raw['@id'] = 'https://example.com/event/123#event';
		$raw['url'] = 'https://example.com/event/123';
		$first      = $this->process( array( $raw ) )[0];
		$this->assertSame( $raw['@id'], $first['metadata']['item_identifier'] );
		$created = $this->upsertItem( $first );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );

		$raw['name']      = 'Completely renamed show';
		$raw['startDate'] = self::future_year() . '-11-20T23:00:00';
		$edited           = $this->process( array( $raw ) )[0];
		$this->assertSame( $first['metadata']['item_identifier'], $edited['metadata']['item_identifier'] );
		$this->assertNotSame( $first['metadata']['event_identifier'], $edited['metadata']['event_identifier'] );
		$updated = $this->upsertItem( $edited );
		$this->assertTrue( $updated['success'], wp_json_encode( $updated ) );
		$this->assertSame( $created['data']['post_id'], $updated['data']['post_id'] );
		$this->assertSame( $raw['name'], get_the_title( $updated['data']['post_id'] ) );
	}

	public function test_calendar_data_preserves_scraped_attribution_and_id_after_reschedule(): void {
		$raw           = $this->rawEvent();
		$raw['@id']    = 'https://example.com/events/weekly-session#event';
		$raw['offers'] = array( 'url' => 'https://example.com/tickets/weekly-session' );
		$item          = $this->process( array( $raw ) )[0];
		$this->assertSame( self::SOURCE_URL, $item['metadata']['_engine_data']['source_url'] );
		$created = $this->upsertItem( $item );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );
		$post_id = $created['data']['post_id'];
		$this->assertSame( self::SOURCE_URL, get_post_meta( $post_id, EventUpsert::SOURCE_URL_META_KEY, true ) );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_header( 'X-Requested-With', 'XMLHttpRequest' );
		$request->set_param( 'format', 'data' );
		$request->set_param( 'month', self::future_year() . '-11' );
		$calendar = new Calendar();
		$before   = $calendar->calendar( $request )->get_data();
		$this->assertSame( \DataMachineEvents\Api\Controllers\Calendar::DATA_SCHEMA_VERSION, $before['schema']['version'] );
		$events = array_column( $before['events'], null, 'id' );
		$this->assertSame(
			array(
				'url'  => self::SOURCE_URL,
				'name' => 'Scraper test venue',
			),
			$events[ $post_id ]['source']
		);
		$this->assertSame( $raw['offers']['url'], $events[ $post_id ]['ticket']['url'] );

		$raw['startDate'] = self::future_year() . '-11-27T21:00:00';
		$raw['url']       = 'https://example.com/events/weekly-session';
		$updated          = $this->upsertItem( $this->process( array( $raw ) )[0] );
		$this->assertTrue( $updated['success'], wp_json_encode( $updated ) );
		$this->assertSame( $post_id, $updated['data']['post_id'] );
		$after  = $calendar->calendar( $request )->get_data();
		$events = array_column( $after['events'], null, 'id' );
		$this->assertSame( $post_id, $events[ $post_id ]['id'] );
		$this->assertSame( self::future_year() . '-11-27', $events[ $post_id ]['date']['start_date'] );
		$this->assertSame( $raw['url'], $events[ $post_id ]['source']['url'] );
	}

	public function test_shared_listing_url_is_not_identity(): void {
		$first          = $this->rawEvent();
		$first['url']   = 'https://example.com/shared-listing';
		$second         = $first;
		$second['name'] = 'Another event';
		$items          = $this->process( array( $first, $second ) );
		$this->assertCount( 2, $items );
		foreach ( $items as $item ) {
			$payload = json_decode( $item['content'], true );
			$this->assertArrayNotHasKey( 'source_item_id', $payload['event'] );
			$this->assertSame( $item['metadata']['event_identifier'], $item['metadata']['item_identifier'] );
		}
		$first['url'] = self::SOURCE_URL;
		$single       = $this->process( array( $first ) )[0];
		$this->assertSame( $single['metadata']['event_identifier'], $single['metadata']['item_identifier'] );
	}

	public function test_series_id_repeated_across_occurrences_is_not_identity(): void {
		$first               = $this->rawEvent();
		$first['@id']        = 'https://example.com/series/weekly-jam';
		$second              = $first;
		$second['startDate'] = self::future_year() . '-11-27T20:00:00';
		$items               = $this->process( array( $first, $second ) );
		$this->assertCount( 2, $items );
		$this->assertNotSame( $items[0]['metadata']['item_identifier'], $items[1]['metadata']['item_identifier'] );
		foreach ( $items as $item ) {
			$this->assertSame( $item['metadata']['event_identifier'], $item['metadata']['item_identifier'] );
		}
	}

	public function test_unique_detail_url_is_identity_but_ticket_url_is_not(): void {
		$raw        = $this->rawEvent();
		$raw['url'] = 'https://example.com/event/unique';
		$item       = $this->process( array( $raw ) )[0];
		$this->assertSame( $raw['url'], $item['metadata']['item_identifier'] );
		unset( $raw['url'] );
		$raw['offers'] = array( 'url' => 'https://tickets.example.com/listing' );
		$item          = $this->process( array( $raw ) )[0];
		$this->assertSame( $item['metadata']['event_identifier'], $item['metadata']['item_identifier'] );
	}

	public function test_existing_content_hash_converges_to_jsonld_id(): void {
		$raw     = $this->rawEvent();
		$old     = $this->process( array( $raw ) )[0];
		$created = $this->upsertItem( $old );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );
		( new ProcessedItems() )->add_processed_item( $this->flow_step_id, 'universal_web_scraper', $old['metadata']['item_identifier'], 4001 );

		$raw['@id'] = 'https://example.com/event/migrated#event';
		$migrated   = $this->process( array( $raw ) )[0];
		$this->assertSame( $raw['@id'], $migrated['metadata']['item_identifier'] );
		$raw['name'] = 'Renamed after identity migration';
		$updated     = $this->upsertItem( $this->process( array( $raw ) )[0] );
		$this->assertTrue( $updated['success'], wp_json_encode( $updated ) );
		$this->assertSame( $created['data']['post_id'], $updated['data']['post_id'] );
	}

	public function test_squarespace_provider_id_survives_normalization(): void {
		$extractor = new SquarespaceExtractor();
		$method    = new \ReflectionMethod( $extractor, 'normalizeItem' );
		foreach ( array( 'Original title', 'Edited title' ) as $title ) {
			$event = $method->invoke( $extractor, array(
				'id'    => 'squarespace-123',
				'title' => $title,
			), array() );
			$this->assertSame( 'squarespace-123', $event['source_item_id'] );
		}
	}

	private function rawEvent(): array {
		return array(
			'@type'     => 'MusicEvent',
			'name'      => 'Original scraper show ' . $this->flow_step_id,
			'startDate' => self::future_year() . '-11-20T20:00:00',
			'location'  => array(
				'@type' => 'Place',
				'name'  => 'Scraper test venue',
			),
		);
	}

	private function process( array $raw_events ): array {
		$html      = '<script type="application/ld+json">' . wp_json_encode( $raw_events ) . '</script>';
		$events    = ( new JsonLdExtractor() )->extract( $html, self::SOURCE_URL );
		$processor = new StructuredDataProcessor( new UniversalWebScraper() );
		$result    = $processor->process( $events, 'jsonld', self::SOURCE_URL, array(), $this->context );
		$this->assertNotNull( $result );
		return $result['items'];
	}

	private function upsertItem( array $item ): array {
		$payload                  = json_decode( $item['content'], true );
		$event                    = array_merge( $payload['event'], $item['metadata']['_engine_data'] );
		$event['source_identity'] = EventIdentity::sourceIdentity(
			array(
				'source'    => 'universal_web_scraper',
				'source_id' => $item['metadata']['item_identifier'],
			),
			new EngineData( $event, 0 )
		);
		return $this->invoke_upsert( $event );
	}
}
