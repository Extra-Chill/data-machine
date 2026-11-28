<?php
/**
 * DetailPageFetcher transport fallback tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\DetailPageFetcher;
use WP_UnitTestCase;

class DetailPageFetcherTest extends WP_UnitTestCase {

	/** @var array<int, string> */
	private array $agents = array();

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function fake_http( callable $responder ): void {
		$this->agents = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $responder ) {
				$agent          = (string) ( $args['headers']['User-Agent'] ?? $args['user-agent'] ?? '' );
				$this->agents[] = $agent;
				return $responder( false !== strpos( $agent, 'Mozilla/' ) );
			},
			10,
			2
		);
	}

	private static function page( string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	public function test_browser_mode_transport_failure_retries_once_in_standard_mode(): void {
		$this->fake_http(
			fn ( bool $browser ) => $browser
				? new \WP_Error( 'http_request_failed', 'cURL error 52: Empty reply from server' )
				: self::page( '<p>Monday, November 2, 2026 | 7:30 PM</p>' )
		);

		$body = ( new DetailPageFetcher() )->fetch( 'https://venue.example/event/show/' );

		$this->assertSame( '<p>Monday, November 2, 2026 | 7:30 PM</p>', $body );
		$this->assertCount( 2, $this->agents );
		$this->assertStringContainsString( 'Mozilla/', $this->agents[0] );
		$this->assertStringNotContainsString( 'Mozilla/', $this->agents[1] );
	}

	public function test_captcha_page_retries_in_standard_mode(): void {
		$this->fake_http(
			fn ( bool $browser ) => self::page( $browser ? '<div class="cloudflare-challenge"></div>' : '<p>Real page</p>' )
		);

		$this->assertSame( '<p>Real page</p>', ( new DetailPageFetcher() )->fetch( 'https://venue.example/event/show/' ) );
	}

	public function test_successful_browser_fetch_does_not_retry(): void {
		$this->fake_http( fn ( bool $browser ) => self::page( '<p>Browser page</p>' ) );

		$this->assertSame( '<p>Browser page</p>', ( new DetailPageFetcher() )->fetch( 'https://venue.example/event/show/' ) );
		$this->assertCount( 1, $this->agents );
	}

	public function test_failure_in_both_modes_returns_null(): void {
		$this->fake_http( fn ( bool $browser ) => new \WP_Error( 'http_request_failed', 'down' ) );

		$this->assertNull( ( new DetailPageFetcher() )->fetch( 'https://venue.example/event/show/' ) );
		$this->assertCount( 2, $this->agents );
	}
}
