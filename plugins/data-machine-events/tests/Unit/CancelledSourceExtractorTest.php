<?php
/**
 * Cancellation normalization for API-backed scraper sources.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\SofarSoundsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WixEventsExtractor;
use WP_UnitTestCase;

class CancelledSourceExtractorTest extends WP_UnitTestCase {

	public function test_wix_retains_cancelled_events(): void {
		$extractor = new WixEventsExtractor();
		$method    = new \ReflectionMethod( $extractor, 'normalizeRawEvents' );
		$method->setAccessible( true );
		$events = $method->invoke( $extractor, array( array(
			'title'  => 'Cancelled Concert',
			'status' => 'CANCELED',
		) ) );
		$this->assertCount( 1, $events );
		$this->assertSame( 'EventCancelled', $events[0]['eventStatus'] );
	}

	public function test_sofar_normalizes_cancelled_events(): void {
		$extractor = new SofarSoundsExtractor();
		$method    = new \ReflectionMethod( $extractor, 'normalizeEvent' );
		$method->setAccessible( true );
		$event = $method->invoke( $extractor, array(
			'id'        => 123,
			'cancelled' => true,
			'headline'  => 'Cancelled Concert',
			'startsAt'  => '2099-08-02T19:00:00Z',
			'endsAt'    => '2099-08-02T21:00:00Z',
			'city'      => array( 'title' => 'Test City', 'timezone' => 'UTC' ),
		) );
		$this->assertSame( 'EventCancelled', $event['eventStatus'] );
	}

	public function test_engine_data_carries_schema_event_status(): void {
		$payload = EventEngineData::buildEngineData(
			array(
				'title'       => 'Cancelled Concert',
				'startDate'   => '2099-08-02',
				'eventStatus' => 'EventCancelled',
			),
			array()
		);
		$this->assertSame( 'EventCancelled', $payload['eventStatus'] );
	}

	public function test_engine_data_drops_raw_feed_status(): void {
		$payload = EventEngineData::buildEngineData(
			array(
				'title'       => 'Confirmed Concert',
				'startDate'   => '2099-08-02',
				'eventStatus' => 'CONFIRMED',
			),
			array()
		);
		$this->assertArrayNotHasKey( 'eventStatus', $payload );
	}
}
