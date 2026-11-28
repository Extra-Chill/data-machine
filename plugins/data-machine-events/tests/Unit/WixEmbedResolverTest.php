<?php
/** Wix embeds must reach the universal extractor chain used by qualification. */
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\WixEmbedResolver;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use DataMachine\Core\ExecutionContext;
use WP_UnitTestCase;

class WixEmbedResolverTest extends WP_UnitTestCase {
	private const SOURCE = 'https://www.thesaintcecilia.com/tour';
	private array $requests = array();

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function fixture( string $name ): string {
		return file_get_contents( __DIR__ . '/../Fixtures/wix/' . $name );
	}

	private function mockHttp( string $embed, array $api_payload ): void {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $embed, $api_payload ) {
			$this->requests[] = $url;
			if ( self::SOURCE === $url ) {
				$body = $this->fixture( 'html-component.html' );
			} elseif ( str_contains( $url, 'siteassets.parastorage.com/' ) ) {
				$this->assertLessThanOrEqual( 20, $args['timeout'] );
				$body = str_contains( $url, 'masterPage' ) ? '{}' : $this->fixture( 'html-component-features.json' );
			} elseif ( str_contains( $url, '.filesusr.com/html/' ) ) {
				$this->assertLessThanOrEqual( 15, $args['timeout'] );
				$this->assertSame( 0, $args['redirection'] );
				$body = $embed;
			} elseif ( str_contains( $url, 'cdn.seated.com/api/tour/' ) || str_contains( $url, 'venuepilot.co/graphql' ) ) {
				$body = wp_json_encode( $api_payload );
			} else {
				return new \WP_Error( 'unexpected_url', $url );
			}
			return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		}, 10, 3 );
	}

	private function scrape(): array {
		// Exercise the fetch stage shared by production and qualification without
		// coupling this regression to Data Machine's packet serialization version.
		$method = new \ReflectionMethod( UniversalWebScraper::class, 'executeFetch' );
		return $method->invoke( new UniversalWebScraper(), array( 'source_url' => self::SOURCE ), ExecutionContext::direct( 'universal_web_scraper' ) );
	}

	public function test_seated_embed_reaches_qualification_scraper(): void {
		$this->mockHttp( $this->fixture( 'seated-embed.html' ), array(
			'data' => array( 'type' => 'tours', 'attributes' => array( 'name' => 'The Saint Cecilia' ) ),
			'included' => array( array( 'type' => 'tour-events', 'id' => 'show-1', 'attributes' => array(
				'starts-at' => '2030-08-11T23:00:00Z', 'starts-at-date-local' => '2030-08-11',
				'is-starts-at-known' => true, 'venue-name' => 'Test Venue', 'formatted-address' => 'Philadelphia, PA',
			) ) ),
		) );
		$result = $this->scrape();
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'The Saint Cecilia', $result['items'][0]['title'] );
		$this->assertSame( 'seated', $result['items'][0]['metadata']['extraction_method'] );
		$this->assertCount( 5, $this->requests );
	}

	public function test_venuepilot_embed_still_extracts(): void {
		$this->mockHttp( '<script>window.venuepilotSettings = {general: {accountIds: [148]}};</script>', array(
			'data' => array( 'paginatedEvents' => array(
				'collection' => array( array( 'name' => 'VenuePilot Show', 'date' => '2030-08-11', 'startTime' => '20:00', 'venue' => array( 'name' => 'Test Venue' ) ) ),
				'metadata' => array( 'currentPage' => 1, 'totalPages' => 1 ),
			) ),
		) );
		$result = $this->scrape();
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'VenuePilot Show', $result['items'][0]['title'] );
		$this->assertSame( 'venuepilot', $result['items'][0]['metadata']['extraction_method'] );
	}

	public function test_duplicate_feature_and_embed_urls_are_fetched_once(): void {
		$this->mockHttp( $this->fixture( 'seated-embed.html' ), array() );
		$html = $this->fixture( 'html-component.html' );
		$result = ( new WixEmbedResolver() )->resolve( $html . $html );
		$this->assertSame( array( $this->fixture( 'seated-embed.html' ) ), $result );
		$this->assertCount( 3, $this->requests );
	}

	public function test_failed_features_leave_no_embeds(): void {
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'Unavailable' ) );
		$this->assertSame( array(), ( new WixEmbedResolver() )->resolve( $this->fixture( 'html-component.html' ) ) );
	}

	public function test_resolver_rejects_non_filesusr_hosts_and_bad_json(): void {
		foreach ( array( 'https://filesusr.com.evil.test/embed', 'https://evil.test/filesusr.com', 'http://valid.filesusr.com/embed' ) as $url ) {
			$mock = static function () use ( $url ) {
				return array( 'headers' => array(), 'body' => wp_json_encode( array( 'props' => array( 'render' => array( 'compProps' => array( 'comp-mk73y1e3' => array( 'url' => $url ) ) ) ) ) ), 'response' => array( 'code' => 200 ) );
			};
			add_filter( 'pre_http_request', $mock );
			$this->assertSame( array(), ( new WixEmbedResolver() )->resolve( $this->fixture( 'html-component.html' ) ) );
			remove_filter( 'pre_http_request', $mock );
		}
		add_filter( 'pre_http_request', static fn() => array( 'headers' => array(), 'body' => '{invalid', 'response' => array( 'code' => 200 ) ) );
		$this->assertSame( array(), ( new WixEmbedResolver() )->resolve( $this->fixture( 'html-component.html' ) ) );
		$this->assertSame( array(), ( new WixEmbedResolver() )->resolve( '<html>No Wix</html>' ) );
	}
}
