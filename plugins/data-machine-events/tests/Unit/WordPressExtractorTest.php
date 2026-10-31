<?php
/**
 * WordPress Tribe extractor regression tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WordPressExtractor;
use WP_UnitTestCase;

class WordPressExtractorTest extends WP_UnitTestCase {

	private WordPressExtractor $extractor;
	private string $fixtures_dir;

	public function setUp(): void {
		parent::setUp();
		$this->extractor    = new WordPressExtractor();
		$this->fixtures_dir = __DIR__ . '/../Fixtures/wordpress-tribe';
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	public function test_uses_advertised_rest_root_and_falls_back_to_core_tribe_cpt(): void {
		$html     = file_get_contents( $this->fixtures_dir . '/archive.html' );
		$empty_v1 = file_get_contents( $this->fixtures_dir . '/empty-v1.json' );
		$core_cpt = file_get_contents( $this->fixtures_dir . '/core-cpt.json' );
		$requests = array();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $empty_v1, $core_cpt, &$requests ) {
				$requests[] = $url;
				$bodies     = array(
					'https://example.com/cms/wp-json/tribe/events/v1/events?per_page=100' => $empty_v1,
					'https://example.com/cms/wp-json/wp/v2/tribe_events?per_page=100&_embed=1' => $core_cpt,
				);

				if ( ! isset( $bodies[ $url ] ) ) {
					return new \WP_Error( 'http_request_failed', 'Unmocked URL: ' . $url );
				}

				return array(
					'headers'  => array(),
					'body'     => $bodies[ $url ],
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$events = $this->extractor->extract( $html, 'https://example.com/calendar/' );

		$this->assertCount( 2, $events );
		$this->assertSame( '2026-07-30', $events[0]['startDate'] );
		$this->assertSame( '2026-08-06', $events[1]['startDate'] );
		$this->assertSame( 'Example Room', $events[0]['venue'] );
		$this->assertSame(
			array(
				'https://example.com/cms/wp-json/tribe/events/v1/events?per_page=100',
				'https://example.com/cms/wp-json/wp/v2/tribe_events?per_page=100&_embed=1',
			),
			$requests
		);
	}

	public function test_private_core_fallback_remains_unsupported(): void {
		$html     = file_get_contents( $this->fixtures_dir . '/archive.html' );
		$empty_v1 = file_get_contents( $this->fixtures_dir . '/empty-v1.json' );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $empty_v1 ) {
				if ( false !== strpos( $url, '/tribe/events/v1/events' ) ) {
					return array(
						'headers'  => array(),
						'body'     => $empty_v1,
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'code' => 'rest_forbidden' ) ),
					'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$this->assertSame( array(), $this->extractor->extract( $html, 'https://example.com/calendar/' ) );
	}
}
