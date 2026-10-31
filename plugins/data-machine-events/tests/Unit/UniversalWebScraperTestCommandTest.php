<?php
/**
 * UniversalWebScraperTestCommand regression tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Cli\UniversalWebScraperTestCommand;
use ReflectionClass;
use WP_UnitTestCase;

class UniversalWebScraperTestCommandTest extends WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	public function test_call_ability_preserves_wp_error(): void {
		add_filter(
			'pre_http_request',
			static fn() => new \WP_Error( 'http_request_failed', 'Fixture request failed.' )
		);

		$result = $this->callAbility( 'https://example.com/events' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'scraper_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Fixture request failed.', $result->get_error_message() );
	}

	public function test_call_ability_preserves_normal_array_result(): void {
		$html = <<<'HTML'
<!doctype html>
<html>
<head>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "MusicEvent",
    "name": "Adapter Boundary Show",
    "startDate": "2099-08-21T20:00:00-04:00",
    "location": {
        "@type": "Place",
        "name": "Fixture Hall",
        "address": {
            "@type": "PostalAddress",
            "streetAddress": "100 Fixture Way",
            "addressLocality": "Charleston",
            "addressRegion": "SC"
        }
    }
}
</script>
</head>
<body></body>
</html>
HTML;

		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => $html,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			)
		);

		$result = $this->callAbility( 'https://example.com/events' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Adapter Boundary Show', $result['event_data']['title'] );
	}

	private function callAbility( string $target_url ): array|\WP_Error {
		$command = new UniversalWebScraperTestCommand();
		$method  = ( new ReflectionClass( $command ) )->getMethod( 'callAbility' );
		$method->setAccessible( true );

		return $method->invoke( $command, $target_url );
	}
}
