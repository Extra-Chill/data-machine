<?php
/**
 * Wix Events extractor tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WixEventsExtractor;
use WP_UnitTestCase;

class WixEventsExtractorTest extends WP_UnitTestCase {

	private WixEventsExtractor $extractor;
	private string $fixtures_dir;
	private array $requests = array();

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new WixEventsExtractor();
		$this->fixtures_dir = __DIR__ . '/../Fixtures/wix';
		$this->requests     = array();
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	public function test_extract_uses_canonical_origin_and_paginates_api_results(): void {
		$this->mockHttpRoutes(
			array(
				'https://www.venue.test/_api/v1/access-tokens' => 'access-tokens.json',
				'https://www.venue.test/_api/wix-events-web/v1/events?offset=0&limit=100' => 'events-page-1.json',
				'https://www.venue.test/_api/wix-events-web/v1/events?offset=100&limit=100' => 'events-page-2.json',
			)
		);

		$events = $this->extractor->extract(
			file_get_contents( $this->fixtures_dir . '/app-state-empty.html' ),
			'http://venue.test/events'
		);

		$this->assertCount( 2, $events );
		$this->assertSame( 'First Fixture Show', $events[0]['title'] );
		$this->assertSame( 'Second Fixture Show', $events[1]['title'] );
		$this->assertSame(
			array(
				'https://www.venue.test/_api/v1/access-tokens',
				'https://www.venue.test/_api/wix-events-web/v1/events?offset=0&limit=100',
				'https://www.venue.test/_api/wix-events-web/v1/events?offset=100&limit=100',
			),
			array_column( $this->requests, 'url' )
		);
		$this->assertSame( 'sanitized-instance-token', $this->requests[1]['headers']['Authorization'] );
		$this->assertSame( 'sanitized-instance-token', $this->requests[2]['headers']['Authorization'] );
	}

	public function test_extract_returns_empty_without_wix_events_app_evidence(): void {
		$this->mockHttpRoutes(
			array(
				'https://www.venue.test/_api/v1/access-tokens' => array(
					'apps' => array(
						'00000000-0000-0000-0000-000000000000' => array(
							'instance' => 'unrelated-app-token',
						),
					),
				),
			)
		);

		$events = $this->extractor->extract(
			file_get_contents( $this->fixtures_dir . '/app-state-empty.html' ),
			'http://venue.test/events'
		);

		$this->assertSame( array(), $events );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Mock Wix API routes with fixture filenames or decoded payloads.
	 *
	 * @param array $routes URL-keyed fixture filenames or response arrays.
	 */
	private function mockHttpRoutes( array $routes ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $routes ) {
				$this->requests[] = array(
					'url'     => $url,
					'headers' => $args['headers'] ?? array(),
				);

				if ( ! array_key_exists( $url, $routes ) ) {
					return new \WP_Error( 'http_request_failed', 'Unmocked URL: ' . $url );
				}

				$payload = $routes[ $url ];
				$body    = is_string( $payload )
					? file_get_contents( $this->fixtures_dir . '/' . $payload )
					: wp_json_encode( $payload );

				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}
}
