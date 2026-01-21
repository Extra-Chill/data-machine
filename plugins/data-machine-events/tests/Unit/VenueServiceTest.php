<?php
/**
 * VenueService Tests
 *
 * Tests venue CRUD operations and normalization.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\VenueService;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueServiceTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Ensure venue taxonomy is registered
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	public function test_normalize_venue_data_sanitizes_name() {
		$raw_data = array(
			'name' => '  Test Venue  ',
		);

		$normalized = VenueService::normalize_venue_data( $raw_data );

		$this->assertEquals( 'Test Venue', $normalized['name'] );
	}

	public function test_normalize_venue_data_includes_meta_fields() {
		$raw_data = array(
			'name'    => 'Test Venue',
			'address' => '123 Main St',
			'city'    => 'Denver',
			'state'   => 'CO',
			'zip'     => '80202',
			'country' => 'USA',
			'phone'   => '555-1234',
			'website' => 'https://venue.com',
		);

		$normalized = VenueService::normalize_venue_data( $raw_data );

		$this->assertEquals( 'Test Venue', $normalized['name'] );
		$this->assertEquals( '123 Main St', $normalized['address'] );
		$this->assertEquals( 'Denver', $normalized['city'] );
		$this->assertEquals( 'CO', $normalized['state'] );
		$this->assertEquals( '80202', $normalized['zip'] );
	}

	public function test_normalize_venue_data_handles_missing_fields() {
		$raw_data = array(
			'name' => 'Minimal Venue',
		);

		$normalized = VenueService::normalize_venue_data( $raw_data );

		$this->assertEquals( 'Minimal Venue', $normalized['name'] );
		$this->assertEquals( '', $normalized['address'] ?? '' );
	}

	public function test_get_or_create_venue_creates_new_venue() {
		$venue_data = array(
			'name'    => 'New Test Venue ' . uniqid(),
			'address' => '456 Test St',
			'city'    => 'Boulder',
		);

		$term_id = VenueService::get_or_create_venue( $venue_data );

		$this->assertIsInt( $term_id );
		$this->assertGreaterThan( 0, $term_id );

		// Verify term exists
		$term = get_term( $term_id, 'venue' );
		$this->assertNotNull( $term );
		$this->assertEquals( $venue_data['name'], $term->name );
	}

	public function test_get_or_create_venue_returns_existing_venue() {
		$venue_name = 'Existing Venue ' . uniqid();
		$venue_data = array( 'name' => $venue_name );

		// Create first
		$first_id = VenueService::get_or_create_venue( $venue_data );

		// Get or create again
		$second_id = VenueService::get_or_create_venue( $venue_data );

		$this->assertEquals( $first_id, $second_id );
	}

	public function test_get_or_create_venue_returns_error_for_empty_name() {
		$venue_data = array( 'name' => '' );

		$result = VenueService::get_or_create_venue( $venue_data );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'empty_venue_name', $result->get_error_code() );
	}

	public function test_get_or_create_venue_saves_metadata() {
		$venue_data = array(
			'name'     => 'Metadata Test Venue ' . uniqid(),
			'address'  => '789 Meta St',
			'city'     => 'Fort Collins',
			'state'    => 'CO',
			'timezone' => 'America/Denver',
		);

		$term_id = VenueService::get_or_create_venue( $venue_data );

		$this->assertIsInt( $term_id );

		// Check metadata was saved
		$saved_address = get_term_meta( $term_id, 'venue_address', true );
		$this->assertEquals( '789 Meta St', $saved_address );

		$saved_city = get_term_meta( $term_id, 'venue_city', true );
		$this->assertEquals( 'Fort Collins', $saved_city );
	}
}
