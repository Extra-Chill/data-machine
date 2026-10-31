<?php
/**
 * Venue Core Name Match Tests
 *
 * Covers the core-name tier of Venue_Taxonomy::find_venue_by_qualified_name()
 * reached through find_or_create_venue(): a ticketing state suffix
 * ("The Catalyst-CA") and a generic "Club" word ("The Catalyst Club") name
 * the same venue in the same city.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueTaxonomyCoreNameMatchTest extends WP_UnitTestCase {

	/** @var int[] */
	private array $existing = array();

	public function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		$this->existing = array_map( 'intval', (array) get_terms( array( 'taxonomy' => 'venue', 'hide_empty' => false, 'fields' => 'ids' ) ) );
	}

	public function tearDown(): void {
		$current = array_map( 'intval', (array) get_terms( array( 'taxonomy' => 'venue', 'hide_empty' => false, 'fields' => 'ids' ) ) );
		foreach ( array_diff( $current, $this->existing ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		parent::tearDown();
	}

	private function venue( string $name, string $city, string $state ): int {
		$result = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $result );
		$term_id = (int) $result['term_id'];
		update_term_meta( $term_id, Venue_Taxonomy::$meta_fields['city'], $city );
		update_term_meta( $term_id, Venue_Taxonomy::$meta_fields['state'], $state );

		return $term_id;
	}

	public function test_club_variant_resolves_to_state_suffixed_venue_in_same_city(): void {
		$s       = uniqid();
		$term_id = $this->venue( "The Catalyst {$s}-CA", 'Santa Cruz', 'CA' );

		$result = Venue_Taxonomy::find_or_create_venue( "The Catalyst {$s} Club", array( 'city' => 'Santa Cruz', 'state' => 'CA' ) );

		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertFalse( $result['was_created'] );
	}

	public function test_core_name_in_other_city_creates_new_venue(): void {
		$s       = uniqid();
		$term_id = $this->venue( "The Catalyst {$s}-CA", 'Santa Cruz', 'CA' );

		$result = Venue_Taxonomy::find_or_create_venue( "The Catalyst {$s} Club", array( 'city' => 'Austin', 'state' => 'TX' ) );

		$this->assertNotSame( $term_id, $result['term_id'] );
	}

	public function test_core_name_requires_incoming_city(): void {
		$s       = uniqid();
		$term_id = $this->venue( "The Catalyst {$s}-CA", 'Santa Cruz', 'CA' );

		$result = Venue_Taxonomy::find_or_create_venue( "The Catalyst {$s} Club", array() );

		$this->assertNotSame( $term_id, $result['term_id'] );
	}

	public function test_core_venue_name_strips_only_matching_state_and_club(): void {
		$this->assertSame( 'catalyst', Venue_Taxonomy::core_venue_name( 'The Catalyst-CA', 'CA' ) );
		$this->assertSame( 'catalyst', Venue_Taxonomy::core_venue_name( 'The Catalyst Club', 'CA' ) );
		$this->assertSame( 'urban lounge', Venue_Taxonomy::core_venue_name( 'The Urban Lounge - UT', 'UT' ) );
		$this->assertSame( 'venueny', Venue_Taxonomy::core_venue_name( 'Venue-NY', 'CA' ) );
		$this->assertSame( 'basement east', Venue_Taxonomy::core_venue_name( 'The Basement East', 'TN' ) );
	}
}
