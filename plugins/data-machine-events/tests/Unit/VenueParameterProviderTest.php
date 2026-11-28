<?php
/**
 * Model-facing venue parameter exposure.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\VenueParameterProvider;

/**
 * A scraper that found only a venue name must not hide the venue
 * address/country/timezone parameters from the model (#782).
 */
class VenueParameterProviderTest extends WP_UnitTestCase {

	public function test_scraper_venue_name_alone_keeps_missing_venue_fields_exposed(): void {
		$engine_data = array(
			'venue'         => 'Baggelycke Gård',
			'venueAddress'  => '',
			'venueCity'     => '',
			'venueState'    => '',
			'venueZip'      => '',
			'venueCountry'  => '',
			'venueTimezone' => '',
		);

		$fragment   = VenueParameterProvider::getToolParameters( array(), $engine_data );
		$properties = $fragment['properties'] ?? array();

		$this->assertArrayNotHasKey( 'venue', $properties, 'The scraper supplied the name; the model must not re-supply it.' );
		foreach ( array( 'venueCity', 'venueCountry', 'venueTimezone', 'venueAddress' ) as $key ) {
			$this->assertArrayHasKey( $key, $properties, "{$key} was empty in engine data and must stay available to the model." );
		}
	}

	public function test_scraper_populated_fields_are_hidden_from_the_model(): void {
		$engine_data = array(
			'venue'        => 'The Royal American',
			'venueCity'    => 'Charleston',
			'venueState'   => 'SC',
			'venueCountry' => 'US',
			'venueZip'     => '',
		);

		$properties = VenueParameterProvider::getToolParameters( array(), $engine_data )['properties'] ?? array();

		foreach ( array( 'venue', 'venueCity', 'venueState', 'venueCountry' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $properties );
		}
		$this->assertArrayHasKey( 'venueZip', $properties );
	}

	public function test_venue_pinned_in_handler_config_exposes_no_venue_parameters(): void {
		$engine_data = array(
			'venue'       => 'Anything',
			'venueCity'   => '',
			'venueCountry' => '',
		);

		$this->assertSame( array(), VenueParameterProvider::getToolParameters( array( 'universal_web_scraper' => array( 'venue' => 2 ) ), $engine_data ) );
		$this->assertSame( array(), VenueParameterProvider::getToolParameters( array( 'venue' => '2' ), $engine_data ) );
	}

	public function test_no_engine_data_exposes_all_venue_parameters(): void {
		$properties = VenueParameterProvider::getToolParameters( array(), array() )['properties'] ?? array();
		$this->assertArrayHasKey( 'venue', $properties );
		$this->assertArrayHasKey( 'venueCountry', $properties );
	}
}
