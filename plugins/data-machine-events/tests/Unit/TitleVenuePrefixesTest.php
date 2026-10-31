<?php
/**
 * Room prefix regression coverage.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\IcsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\StructuredDataProcessor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\TitleVenuePrefixNormalizer;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraperSettings;

class TitleVenuePrefixesTest extends \WP_UnitTestCase {

	public function test_ics_prefixes_are_normalized_before_payload_creation(): void {
		$default = self::factory()->term->create( array( 'taxonomy' => 'venue', 'name' => 'The Starlight Motor Inn' ) );
		$room    = self::factory()->term->create( array( 'taxonomy' => 'venue', 'name' => 'Burgundy Lounge' ) );
		$fixture = file_get_contents( dirname( __DIR__ ) . '/Fixtures/title-venue-prefixes.ics' );
		foreach ( array( '20990501', '20990502', '20990503' ) as $i => $date ) {
			$fixture = str_replace( $date, gmdate( 'Ymd', strtotime( '+' . ( $i + 1 ) . ' days' ) ), $fixture );
		}
		$events = ( new IcsExtractor() )->extract( $fixture, 'https://example.com/calendar.ics' );
		$this->assertCount( 3, $events );
		$config = array(
			'venue'                => $default,
			'title_venue_prefixes' => "Burgundy => {$room}\nPool House",
		);
		$processor = new StructuredDataProcessor( new UniversalWebScraper() );
		$context = $this->createMock( ExecutionContext::class );
		$context->method( 'classifySourceItems' )->willReturn( array( 'classifications' => array() ) );
		$result = $processor->process( $events, 'ics', 'https://example.com/calendar.ics', $config, $context );
		$this->assertCount( 3, $result['items'] );
		$titles = array( 'Good Vibes Band', '"Karaoke Nite" DJ Wild Bill', 'Good Vibes Band' );
		$venues = array( 'Burgundy Lounge', 'The Starlight Motor Inn', 'The Starlight Motor Inn' );
		foreach ( $result['items'] as $i => $item ) {
			$payload = json_decode( $item['content'], true );
			$this->assertSame( $titles[ $i ], $item['title'] );
			$this->assertSame( $titles[ $i ], $payload['event']['title'] );
			$this->assertSame( $titles[ $i ], $payload['raw_source']['title'] );
			$this->assertSame( $venues[ $i ], $payload['event']['venue'] );
			$this->assertEmpty( $payload['event']['performer'] );
		}
		$this->assertSame( $default, $config['venue'] );
	}

	public function test_name_mapping_and_inferred_performer_cleanup(): void {
		$config = array( 'venue' => 123, 'title_venue_prefixes' => 'Side Room => Concert Hall' );
		$event  = TitleVenuePrefixNormalizer::normalize( array( 'title' => 'Side Room: Band', 'performer' => 'Side Room', 'artist' => 'Side Room: Band' ), $config );
		$this->assertSame( 'Band', $event['title'] );
		$this->assertSame( '', $event['performer'] );
		$this->assertSame( 'Band', $event['artist'] );
		$this->assertSame( 'Concert Hall', $config['venue_name'] );
		$this->assertArrayNotHasKey( 'venue', $config );
	}

	public function test_setting_preserves_lines_and_is_optional(): void {
		$value = "Burgundy => 110674\nPool House";
		$settings = UniversalWebScraperSettings::sanitize( array( 'title_venue_prefixes' => $value ) );
		$this->assertSame( $value, $settings['title_venue_prefixes'] );
		$this->assertSame( 'textarea', UniversalWebScraperSettings::get_fields()['title_venue_prefixes']['type'] );
		$this->assertSame( '', UniversalWebScraperSettings::get_defaults()['title_venue_prefixes'] );
	}
}
