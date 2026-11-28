<?php

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DataPacket;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

class UniversalWebScraperFlowTest extends WP_UnitTestCase {
	private array $log_entries = [];

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( UniversalWebScraper::class ) || ! class_exists( EventUpsert::class ) ) {
			$this->markTestSkipped( 'datamachine-events not installed.' );
		}

		$this->log_entries = [];
		add_action( 'datamachine_log', [ $this, 'capture_log' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_action( 'datamachine_log', [ $this, 'capture_log' ], 10 );
		remove_filter( 'pre_http_request', [ $this, 'stub_http_request' ], 10 );

		parent::tear_down();
	}

	public function capture_log( string $level, string $message, array $context = [] ): void {
		$this->log_entries[] = [
			'level' => $level,
			'message' => $message,
			'context' => $context,
		];
	}

	public function stub_http_request( $preempt, $args, $url ) {
		global $wp_filesystem;
		$fixtures = [
			'https://example.test/events' => 'jsonld-with-venue.html',
			'https://example.test/events-no-venue' => 'jsonld-without-venue.html',
		];

		if ( ! isset( $fixtures[ $url ] ) ) {
			return $preempt;
		}

		$path = dirname( __DIR__, 2 ) . '/fixtures/universal-web-scraper/' . $fixtures[ $url ];
		$html = $wp_filesystem->get_contents( $path );
		if ( false === $html ) {
			return $preempt;
		}

		return [
			'headers' => [],
			'body' => $html,
			'response' => [
				'code' => 200,
				'message' => 'OK',
			],
			'cookies' => [],
			'filename' => null,
		];
	}

	private function create_job_id(): int {
		$db_jobs = new Jobs();
		self::factory()->user->create( [ 'role' => 'administrator' ] );

		$job_id = $db_jobs->create_job( [
			'pipeline_id' => 1,
			'flow_id' => 1,
		] );

		$this->assertIsInt( $job_id );
		$this->assertGreaterThan( 0, $job_id );

		return $job_id;
	}

	private function new_flow_step_id(): string {
		return 'flow_step_' . wp_generate_uuid4();
	}

	private function assert_has_warning( string $contains ): void {
		foreach ( $this->log_entries as $log_entry ) {
			if ( 'warning'=== $log_entry['level'] && str_contains( $log_entry['message'], $contains ) ) {
				return;
			}
		}

		$this->fail( 'Expected warning log containing: ' . $contains );
	}

	private function assert_no_warning( string $contains ): void {
		foreach ( $this->log_entries as $log_entry ) {
			$this->assertFalse(
				'warning'=== $log_entry['level'] && str_contains( $log_entry['message'], $contains ),
				'Unexpected warning log containing: ' . $contains
			);
		}
	}

	private function decode_payload( DataPacket $packet ): array {
		$packet_array = $packet->addTo( [] );
		$this->assertArrayHasKey( 'data', $packet_array[0] );
		$this->assertArrayHasKey( 'body', $packet_array[0]['data'] );
		$payload = json_decode( (string) $packet_array[0]['data']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'event', $payload );
		$this->assertIsArray( $payload['event'] );

		return $payload;
	}

	public function test_fixture_with_venue_returns_packet_and_upsert_creates_post(): void {
		add_filter( 'pre_http_request', [ $this, 'stub_http_request' ], 10, 3 );

		$job_id = $this->create_job_id();

		$handler = new UniversalWebScraper();
		$result  = $handler->get_fetch_data( 1, [
			'source_url' => 'https://example.test/events',
			'flow_step_id' => $this->new_flow_step_id(),
			'flow_id' => 1,
			'search' => '',
		], (string) $job_id );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result, 'Unexpected empty result. Logs: ' . wp_json_encode( $this->log_entries ) );
		$this->assertInstanceOf( DataPacket::class, $result[0] );

		$payload = $this->decode_payload( $result[0] );
		$event   = $payload['event'];

		$this->assertSame( 'Scraped Event Title', $event['title'] ?? '' );
		$this->assertSame( '2099-06-02', $event['startDate'] ?? '' );
		$this->assertSame( 'Test Venue', $event['venue'] ?? '' );

		$upsert = new EventUpsert();
		$response = $upsert->handle_tool_call( [
			'title' => (string) ( $event['title'] ?? '' ),
			'venue' => (string) ( $event['venue'] ?? '' ),
			'startDate' => (string) ( $event['startDate'] ?? '' ),
			'job_id' => $job_id,
		], [] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) ( $response['success'] ?? false ) );
		$this->assertNotEmpty( $response['data']['post_id'] ?? null );

		$post_id = (int) $response['data']['post_id'];
		$this->assertSame( Event_Post_Type::POST_TYPE, get_post_type( $post_id ) );
		$this->assertSame( 'Scraped Event Title', get_the_title( $post_id ) );
	}

	public function test_fixture_without_venue_returns_packet_and_emits_warning(): void {
		add_filter( 'pre_http_request', [ $this, 'stub_http_request' ], 10, 3 );

		$job_id = $this->create_job_id();

		$handler = new UniversalWebScraper();
		$result  = $handler->get_fetch_data( 1, [
			'source_url' => 'https://example.test/events-no-venue',
			'flow_step_id' => $this->new_flow_step_id(),
			'flow_id' => 1,
			'search' => '',
		], (string) $job_id );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result, 'Unexpected empty result. Logs: ' . wp_json_encode( $this->log_entries ) );
		$this->assertInstanceOf( DataPacket::class, $result[0] );

		$payload = $this->decode_payload( $result[0] );
		$event   = $payload['event'];

		$this->assertSame( 'Scraped Event Missing Venue', $event['title'] ?? '' );
		$this->assertSame( '2099-06-03', $event['startDate'] ?? '' );
		$this->assertTrue( empty( trim( (string) ( $event['venue'] ?? '' ) ) ) );

		$this->assert_has_warning( 'Missing venue; configure venue override' );
	}

	public function test_fixture_without_venue_suppresses_warning_when_override_present(): void {
		add_filter( 'pre_http_request', [ $this, 'stub_http_request' ], 10, 3 );

		$job_id = $this->create_job_id();

		$handler = new UniversalWebScraper();
		$result  = $handler->get_fetch_data( 1, [
			'source_url' => 'https://example.test/events-no-venue',
			'venue_name' => 'Manual Venue Override',
			'flow_step_id' => $this->new_flow_step_id(),
			'flow_id' => 1,
			'search' => '',
		], (string) $job_id );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result, 'Unexpected empty result. Logs: ' . wp_json_encode( $this->log_entries ) );
		$this->assertInstanceOf( DataPacket::class, $result[0] );

		$payload = $this->decode_payload( $result[0] );
		$event   = $payload['event'];

		$this->assertSame( 'Manual Venue Override', $event['venue'] ?? '' );
		$this->assert_no_warning( 'Missing venue; configure venue override' );
	}
}
