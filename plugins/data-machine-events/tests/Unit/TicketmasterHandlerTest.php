<?php
/**
 * Ticketmaster Handler Tests
 *
 * Tests Ticketmaster API integration handler.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSettings;
use ReflectionClass;

class TicketmasterHandlerTest extends WP_UnitTestCase {

	private Ticketmaster $handler;

	public function setUp(): void {
		parent::setUp();
		$this->handler = new Ticketmaster();
	}

	public function test_handler_type() {
		$this->assertEquals( 'ticketmaster', $this->handler->getHandlerType() );
	}

	public function test_handler_extends_event_import_handler() {
		$this->assertInstanceOf(
			\DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler::class,
			$this->handler
		);
	}

	public function test_settings_class_exists() {
		$this->assertTrue( class_exists( TicketmasterSettings::class ) );
	}

	public function test_map_event_returns_array() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'        => 'Test Concert',
			'id'          => 'TM123456',
			'url'         => 'https://www.ticketmaster.com/event/123',
			'dates'       => array(
				'start' => array(
					'localDate' => '2026-03-15',
					'localTime' => '19:30:00',
				),
				'timezone' => 'America/Denver',
			),
			'_embedded'   => array(
				'venues' => array(
					array(
						'name'    => 'Test Arena',
						'address' => array(
							'line1' => '123 Main St',
						),
						'city'    => array(
							'name' => 'Denver',
						),
						'state'   => array(
							'stateCode' => 'CO',
						),
						'postalCode' => '80202',
						'country' => array(
							'countryCode' => 'US',
						),
						'timezone' => 'America/Denver',
					),
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Test Concert', $result['title'] );
		$this->assertEquals( 'Test Arena', $result['venue'] );
		$this->assertEquals( '2026-03-15', $result['startDate'] );
		$this->assertEquals( '19:30', $result['startTime'] );
	}

	public function test_map_event_handles_missing_venue() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'  => 'No Venue Event',
			'id'    => 'TM789',
			'dates' => array(
				'start' => array(
					'localDate' => '2026-04-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'No Venue Event', $result['title'] );
		$this->assertEquals( '', $result['venue'] ?? '' );
	}

	public function test_map_event_handles_price_ranges() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'        => 'Priced Event',
			'id'          => 'TM456',
			'priceRanges' => array(
				array(
					'min'      => 25.00,
					'max'      => 75.00,
					'currency' => 'USD',
				),
			),
			'dates'       => array(
				'start' => array(
					'localDate' => '2026-05-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['price'] ?? '' );
	}

	public function test_map_event_formats_price_correctly() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'        => 'Price Format Test',
			'id'          => 'TM789',
			'priceRanges' => array(
				array(
					'min'      => 50.00,
					'max'      => 50.00,
					'currency' => 'USD',
				),
			),
			'dates'       => array(
				'start' => array(
					'localDate' => '2026-06-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'price', $result );
		$this->assertEquals( '$50.00', $result['price'] );
	}

	public function test_map_event_handles_missing_price() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'  => 'No Price Event',
			'id'    => 'TM999',
			'dates' => array(
				'start' => array(
					'localDate' => '2026-07-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'price', $result );
		$this->assertEquals( '', $result['price'] );
	}

	private function getProtectedMethod( string $name ) {
		$reflection = new ReflectionClass( $this->handler );
		$method = $reflection->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}
}
