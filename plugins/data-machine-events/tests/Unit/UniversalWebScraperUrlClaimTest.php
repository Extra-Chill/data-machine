<?php
/**
 * Universal Web Scraper URL claim tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\Steps\HandlerUrlResolver;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use WP_UnitTestCase;

class UniversalWebScraperUrlClaimTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_filter( 'datamachine_handlers', array( $this, 'registerSpecificHandler' ), 20 );
		parent::tear_down();
	}

	public function test_claims_http_and_https_urls(): void {
		$this->assertSame(
			array( 'source_url' => 'https://venue.example.com/events' ),
			UniversalWebScraper::claimUrl( 'https://venue.example.com/events' )
		);
		$this->assertSame(
			array( 'source_url' => 'http://venue.example.com/calendar?page=2' ),
			UniversalWebScraper::claimUrl( '  http://venue.example.com/calendar?page=2 ' )
		);
		$this->assertSame(
			array( 'source_url' => 'HTTPS://venue.example.com/' ),
			UniversalWebScraper::claimUrl( 'HTTPS://venue.example.com/' )
		);
	}

	public function test_rejects_non_http_input(): void {
		foreach ( array( '', 'not a url', 'venue.example.com/events', 'ftp://venue.example.com/file', 'mailto:hello@example.com', 'javascript:alert(1)', 'https://', 'https://venue.example.com/a b' ) as $input ) {
			$this->assertNull( UniversalWebScraper::claimUrl( $input ), 'Should reject: ' . $input );
		}
	}

	public function test_registration_declares_catch_all_claim(): void {
		$meta = $this->scraperMeta();

		$this->assertIsCallable( $meta['url_claim'] ?? null );
		$this->assertSame( UniversalWebScraper::URL_CLAIM_PRIORITY, $meta['url_claim_priority'] ?? null );
		$this->assertGreaterThan( 10, UniversalWebScraper::URL_CLAIM_PRIORITY );
	}

	public function test_specific_claim_wins_over_scraper(): void {
		if ( ! class_exists( HandlerUrlResolver::class ) ) {
			$this->markTestSkipped( 'Data Machine without handler URL claims.' );
		}
		$this->scraperMeta();
		add_filter( 'datamachine_handlers', array( $this, 'registerSpecificHandler' ), 20, 2 );

		$resolved = HandlerUrlResolver::resolve( 'https://social.example.com/somevenue', 'event_import' );
		$this->assertSame( 'test_specific_account', $resolved['handler_slug'] ?? null );

		$fallback = HandlerUrlResolver::resolve( 'https://venue.example.com/events', 'event_import' );
		$this->assertSame( 'universal_web_scraper', $fallback['handler_slug'] ?? null );
		$this->assertSame( array( 'source_url' => 'https://venue.example.com/events' ), $fallback['handler_config'] ?? null );
	}

	public function registerSpecificHandler( array $handlers, ?string $type = null ): array {
		if ( null === $type || 'event_import' === $type ) {
			$handlers['test_specific_account'] = array(
				'type' => 'event_import',
				'meta' => array(
					'url_claim'          => static function ( string $url ): ?array {
						$host = wp_parse_url( $url, PHP_URL_HOST );
						return 'social.example.com' === $host ? array( 'account' => 'somevenue' ) : null;
					},
					'url_claim_priority' => 10,
				),
			);
		}
		return $handlers;
	}

	/**
	 * Ensure the scraper is registered and return its handler meta.
	 */
	private function scraperMeta(): array {
		$handlers = apply_filters( 'datamachine_handlers', array(), 'event_import' );
		if ( ! isset( $handlers['universal_web_scraper'] ) ) {
			new UniversalWebScraper();
			$handlers = apply_filters( 'datamachine_handlers', array(), 'event_import' );
		}
		$this->assertArrayHasKey( 'universal_web_scraper', $handlers );
		return $handlers['universal_web_scraper']['meta'] ?? array();
	}
}
