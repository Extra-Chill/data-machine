<?php
/**
 * Venue Geographic Conflict Tests
 *
 * Covers Venue_Taxonomy::has_geographic_conflict() semantics through the
 * public identity API and address_has_street_component() directly.
 *
 * Issue #798: a partial incoming address (city/zip/country only, no street)
 * must not contradict a stored full street address on an exact name match;
 * a different street must still conflict.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.61.2
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueTaxonomyGeographicConflictTest extends WP_UnitTestCase {
	/** @var int[] */
	private array $existing_venue_ids = array();

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		$existing_ids = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertNotWPError( $existing_ids );
		$this->existing_venue_ids = array_map( 'intval', $existing_ids );
	}

	public function tearDown(): void {
		global $wpdb;
		$current_ids = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertNotWPError( $current_ids );
		foreach ( array_diff( array_map( 'intval', $current_ids ), $this->existing_venue_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	/**
	 * Create a venue term with exactly the meta supplied.
	 *
	 * @param string               $name Venue name.
	 * @param array<string, string> $meta Field => value using data keys (address, city, ...).
	 * @return int
	 */
	private function create_venue_with_meta( string $name, array $meta ): int {
		$result = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $result );
		$term_id = (int) $result['term_id'];

		foreach ( $meta as $field => $value ) {
			if ( isset( Venue_Taxonomy::$meta_fields[ $field ] ) ) {
				update_term_meta( $term_id, Venue_Taxonomy::$meta_fields[ $field ], $value );
			}
		}

		return $term_id;
	}

	// ---------------------------------------------------------------------
	// Part A: has_geographic_conflict semantics via resolve_venue_identity
	// ---------------------------------------------------------------------

	public function test_partial_incoming_address_matches_exact_name(): void {
		$name   = 'Partial Address Venue ' . uniqid();
		$term_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '880 Island Park Dr',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => 'Charleston, SC 29492-2901, United States',
				'city'    => 'Charleston',
				'state'   => 'South Carolina',
				'country' => 'US',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
	}

	public function test_incoming_street_subset_of_stored_address_matches(): void {
		$name   = 'Subset Address Venue ' . uniqid();
		$term_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '880 Island Park Dr, Charleston, SC 29492',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '880 Island Park Dr',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
	}

	public function test_partial_suffix_incoming_address_matches(): void {
		$name   = 'Suffix Address Venue ' . uniqid();
		$term_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '880 Island Park Dr, Charleston, SC 29492',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => 'Charleston, SC 29492',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
	}

	public function test_different_street_address_same_city_is_conflict(): void {
		$name = 'Street Conflict Venue ' . uniqid();
		$this->create_venue_with_meta(
			$name,
			array(
				'address' => '880 Island Park Dr',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '100 King St',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'conflict', $result['match_status'] );
		$this->assertNotEmpty( $result['conflicting_term_ids'] );
	}

	public function test_different_city_is_conflict(): void {
		$name = 'City Conflict Venue ' . uniqid();
		$this->create_venue_with_meta(
			$name,
			array(
				'address' => '880 Island Park Dr',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '880 Island Park Dr',
				'city'    => 'Atlanta',
				'state'   => 'GA',
				'country' => 'US',
			)
		);

		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'conflict', $result['match_status'] );
		$this->assertNotEmpty( $result['conflicting_term_ids'] );
	}

	// ---------------------------------------------------------------------
	// Part A2: #803 directional/unit/ordinal variants are not conflicts
	// ---------------------------------------------------------------------

	public function test_directional_variant_matches(): void {
		$name    = 'Far Out Lounge ' . uniqid();
		$term_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '8504 South Congress Avenue',
				'city'    => 'Austin',
				'state'   => 'TX',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '8504 S Congress Ave',
				'city'    => '',
				'state'   => '',
				'country' => '',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
	}

	public function test_hyphenated_unit_suffix_and_directional_match(): void {
		$name    = "Eddie's Attic " . uniqid();
		$term_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '515 North Mcdonough Street',
				'city'    => 'Decatur',
				'state'   => 'GA',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '515-B N. McDonough St.',
				'city'    => '',
				'state'   => '',
				'country' => '',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
	}

	public function test_directional_and_spelled_ordinal_match(): void {
		$name    = 'Moroccan Lounge ' . uniqid();
		$term_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '901 East 1st Street',
				'city'    => 'Los Angeles',
				'state'   => 'CA',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '901 E 1st Street',
				'city'    => '',
				'state'   => '',
				'country' => '',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
	}

	public function test_directional_present_on_one_side_only_matches(): void {
		$name    = 'T-Mobile Arena ' . uniqid();
		$term_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '3780 Las Vegas Blvd.',
				'city'    => 'Las Vegas',
				'state'   => 'NV',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '3780 S Las Vegas Blvd',
				'city'    => '',
				'state'   => '',
				'country' => '',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
	}

	public function test_spelled_ordinal_matches_numeric_ordinal(): void {
		$name    = 'First Ave Venue ' . uniqid();
		$term_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '100 1st Ave',
				'city'    => 'New York',
				'state'   => 'NY',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '100 First Ave',
				'city'    => '',
				'state'   => '',
				'country' => '',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
	}

	public function test_different_house_number_with_directional_variants_is_conflict(): void {
		$name = 'West Ave Conflict Venue ' . uniqid();
		$this->create_venue_with_meta(
			$name,
			array(
				'address' => '1101 N West Ave',
				'city'    => 'Jacksonville',
				'state'   => 'FL',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '1201 North West Avenue',
				'city'    => '',
				'state'   => '',
				'country' => '',
			)
		);

		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'conflict', $result['match_status'] );
	}

	public function test_different_street_with_directional_prefix_is_conflict(): void {
		$name = 'Congress Lamar Conflict Venue ' . uniqid();
		$this->create_venue_with_meta(
			$name,
			array(
				'address' => '8504 S Lamar Blvd',
				'city'    => 'Austin',
				'state'   => 'TX',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '8504 S Congress Ave',
				'city'    => '',
				'state'   => '',
				'country' => '',
			)
		);

		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'conflict', $result['match_status'] );
	}

	// ---------------------------------------------------------------------
	// Part B: address_has_street_component
	// ---------------------------------------------------------------------

	public function test_address_with_house_number_and_street_word_has_street_component(): void {
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '880 Island Park Dr' ) );
	}

	public function test_city_zip_country_only_address_has_no_street_component(): void {
		$this->assertFalse(
			Venue_Taxonomy::address_has_street_component( 'Charleston, SC 29492-2901, United States' )
		);
	}

	public function test_empty_address_has_no_street_component(): void {
		$this->assertFalse( Venue_Taxonomy::address_has_street_component( '' ) );
	}

	public function test_full_street_address_with_city_has_street_component(): void {
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '123 Main St, Austin, TX' ) );
	}

	public function test_bare_postal_code_has_no_street_component(): void {
		$this->assertFalse( Venue_Taxonomy::address_has_street_component( '29492' ) );
	}

	public function test_address_with_new_street_types_has_street_component(): void {
		// Real venue addresses use more street types than the core set (#806).
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '101 Founders Way' ) );
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '1 River Trail' ) );
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '9 Waterfront Square' ) );
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '500 Center' ) );
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '300 Turnpike' ) );
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '22 The Terrace' ) );
		$this->assertTrue( Venue_Taxonomy::address_has_street_component( '12 Crossing' ) );
	}

	// ---------------------------------------------------------------------
	// Part C: normalize_address_for_matching (#803)
	// ---------------------------------------------------------------------

	public function test_normalizer_canonicalizes_directional_after_house_number(): void {
		$this->assertSame(
			Venue_Taxonomy::normalize_address_for_matching( '8504 S Congress Ave' ),
			Venue_Taxonomy::normalize_address_for_matching( '8504 South Congress Avenue' )
		);
	}

	public function test_normalizer_strips_hyphenated_unit_suffix(): void {
		$this->assertSame( '515 n mcdonough st', Venue_Taxonomy::normalize_address_for_matching( '515-B N. McDonough St.' ) );
	}

	public function test_normalizer_canonicalizes_directional_and_street_words(): void {
		$this->assertSame( '901 e 1st st', Venue_Taxonomy::normalize_address_for_matching( '901 East 1st Street' ) );
	}

	public function test_normalizer_canonicalizes_full_directional_words(): void {
		// 'west' is immediately followed by the street-type word 'avenue', so
		// it is kept as a street-name token; only 'north' canonicalizes.
		$this->assertSame( '1201 n west ave', Venue_Taxonomy::normalize_address_for_matching( '1201 North West Avenue' ) );
	}

	public function test_normalizer_maps_spelled_ordinals(): void {
		$this->assertSame( '100 1st ave', Venue_Taxonomy::normalize_address_for_matching( '100 First Ave' ) );
	}

	public function test_normalizer_preserves_hyphenated_house_number_range(): void {
		$this->assertSame( '36-38 broad st', Venue_Taxonomy::normalize_address_for_matching( '36-38 Broad St' ) );
	}

	public function test_normalizer_leaves_street_named_directional_before_street_type_untouched(): void {
		// "West Street" — West is the street NAME; a directional immediately
		// followed by a street-type word must not canonicalize (#803 follow-up).
		$this->assertNotSame(
			Venue_Taxonomy::normalize_address_for_matching( '100 West Street' ),
			Venue_Taxonomy::normalize_address_for_matching( '100 W Street' )
		);
		$this->assertSame( '100 west st', Venue_Taxonomy::normalize_address_for_matching( '100 West Street' ) );
	}

	public function test_normalizer_collapses_spaced_digit_ranges(): void {
		$this->assertSame( '9-17 market st', Venue_Taxonomy::normalize_address_for_matching( '9 - 17 Market Street' ) );
		$this->assertSame(
			Venue_Taxonomy::normalize_address_for_matching( '9 - 17 Market St' ),
			Venue_Taxonomy::normalize_address_for_matching( '9-17 Market Street' )
		);
	}

	public function test_normalizer_maps_saint_after_house_number(): void {
		$this->assertSame(
			Venue_Taxonomy::normalize_address_for_matching( '111 St Claude Rd' ),
			Venue_Taxonomy::normalize_address_for_matching( '111 Saint Claude Road' )
		);
		$this->assertSame( '111 st claude rd', Venue_Taxonomy::normalize_address_for_matching( '111 Saint Claude Rd' ) );
	}

	public function test_normalizer_maps_saint_after_directional(): void {
		$this->assertSame(
			Venue_Taxonomy::normalize_address_for_matching( '12 N Saint Louis St' ),
			Venue_Taxonomy::normalize_address_for_matching( '12 North Saint Louis Street' )
		);
	}

	public function test_normalizer_keeps_leading_saint_untouched(): void {
		// "Saint" as the first token may be a proper place name — the
		// conservative rule only rewrites it after a house number/directional.
		$this->assertSame( 'saint claude rd', Venue_Taxonomy::normalize_address_for_matching( 'Saint Claude Road' ) );
		$this->assertNotSame(
			Venue_Taxonomy::normalize_address_for_matching( 'Saint Claude Road' ),
			Venue_Taxonomy::normalize_address_for_matching( 'St Claude Road' )
		);
	}

	public function test_normalizer_singularizes_plural_street_words(): void {
		$this->assertSame( '5 townes st', Venue_Taxonomy::normalize_address_for_matching( '5 Townes Streets' ) );
		$this->assertSame( '100 georgia ave', Venue_Taxonomy::normalize_address_for_matching( '100 Georgia Avenues' ) );
		$this->assertSame( '9 county rd', Venue_Taxonomy::normalize_address_for_matching( '9 County Roads' ) );
	}

	public function test_normalizer_maps_new_street_type_words(): void {
		$this->assertSame( '450 ter', Venue_Taxonomy::normalize_address_for_matching( '450 Terrace' ) );
		$this->assertSame( '1 river trl', Venue_Taxonomy::normalize_address_for_matching( '1 River Trail' ) );
		$this->assertSame( '9 waterfront sq', Venue_Taxonomy::normalize_address_for_matching( '9 Waterfront Square' ) );
		$this->assertSame( '500 ctr', Venue_Taxonomy::normalize_address_for_matching( '500 Center' ) );
		$this->assertSame( '500 ctr', Venue_Taxonomy::normalize_address_for_matching( '500 Centre' ) );
		$this->assertSame( '300 tpke', Venue_Taxonomy::normalize_address_for_matching( '300 Turnpike' ) );
		$this->assertSame( '12 xing', Venue_Taxonomy::normalize_address_for_matching( '12 Crossing' ) );
		$this->assertSame( '2 plz', Venue_Taxonomy::normalize_address_for_matching( '2 Plaza' ) );
		$this->assertSame( '101 founders way', Venue_Taxonomy::normalize_address_for_matching( '101 Founders Way' ) );
	}

	// ---------------------------------------------------------------------
	// Part D: geographic conflict creates a distinct venue (#806)
	// ---------------------------------------------------------------------

	public function test_conflict_city_with_street_creates_distinct_term(): void {
		$name        = 'The Foundry ' . uniqid();
		$original_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '250 W Washington St',
				'city'    => 'Athens',
				'state'   => 'GA',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '4256 Pearl Rd',
				'city'    => 'Cleveland',
				'state'   => 'OH',
				'country' => 'US',
			)
		);

		$this->assertSame( 'created', $result['match_status'] );
		$this->assertTrue( $result['was_created'] );
		$this->assertNotNull( $result['term_id'] );
		$this->assertNotSame( $original_id, $result['term_id'] );

		$term = get_term( $result['term_id'], 'venue' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( $name, $term->name );
		$this->assertSame( sanitize_title( $name ) . '-cleveland', $term->slug );
		$this->assertSame( '4256 Pearl Rd', get_term_meta( (int) $result['term_id'], '_venue_address', true ) );
		$this->assertSame( 'Cleveland', get_term_meta( (int) $result['term_id'], '_venue_city', true ) );

		// The conflicting stored term stays untouched.
		$this->assertSame( '250 W Washington St', get_term_meta( $original_id, '_venue_address', true ) );
		$this->assertSame( 'Athens', get_term_meta( $original_id, '_venue_city', true ) );
	}

	public function test_conflict_street_same_city_creates_distinct_term(): void {
		// Bucket C: a stored address that is wrong/stale still conflicts, and
		// creation is acceptable — the operator merges terms afterwards.
		$name        = 'Wind Creek Event Center ' . uniqid();
		$original_id = $this->create_venue_with_meta(
			$name,
			array(
				'address' => '77 Sands Blvd',
				'city'    => 'Bethlehem',
				'state'   => 'PA',
				'country' => 'US',
			)
		);

		$result = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '101 Founders Way',
				'city'    => 'Bethlehem',
				'state'   => 'PA',
				'country' => 'US',
			)
		);

		$this->assertSame( 'created', $result['match_status'] );
		$this->assertNotNull( $result['term_id'] );
		$this->assertNotSame( $original_id, $result['term_id'] );

		$term = get_term( $result['term_id'], 'venue' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( sanitize_title( $name ) . '-bethlehem', $term->slug );
		$this->assertSame( '101 Founders Way', get_term_meta( (int) $result['term_id'], '_venue_address', true ) );
	}

	public function test_conflict_without_distinguishing_geography_does_not_create(): void {
		$name = 'State Only Conflict Venue ' . uniqid();
		$this->create_venue_with_meta(
			$name,
			array(
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);

		$before = (int) wp_count_terms( array( 'taxonomy' => 'venue', 'hide_empty' => false ) );

		// State-only conflict: no street, no city — nothing to distinguish a
		// new venue with, so no term is created (#806).
		$result = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '',
				'city'    => '',
				'state'   => 'GA',
				'country' => 'US',
			)
		);

		$this->assertNull( $result['term_id'] );
		$this->assertFalse( $result['was_created'] );
		$this->assertSame( 'conflict', $result['match_status'] );
		$this->assertSame( $before, (int) wp_count_terms( array( 'taxonomy' => 'venue', 'hide_empty' => false ) ) );
	}

	public function test_alt_name_conflict_falls_through_to_creation(): void {
		$suffix      = uniqid();
		$stored_name = 'Troubadour ' . $suffix;
		$original_id = $this->create_venue_with_meta(
			$stored_name,
			array(
				'address' => '9081 Sunset Blvd',
				'city'    => 'West Hollywood',
				'state'   => 'CA',
				'country' => 'US',
			)
		);

		// Same suffix so the alt-name tier ("The X" ↔ "X") actually collides.
		$incoming_name = 'The Troubadour ' . $suffix;
		$result        = Venue_Taxonomy::find_or_create_venue(
			$incoming_name,
			array(
				'address' => '265 Old Brompton Rd',
				'city'    => 'London',
				'state'   => '',
				'country' => 'UK',
			)
		);

		$this->assertSame( 'created', $result['match_status'] );
		$this->assertNotNull( $result['term_id'] );
		$this->assertNotSame( $original_id, $result['term_id'] );

		$term = get_term( $result['term_id'], 'venue' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( $incoming_name, $term->name );
		$this->assertSame( sanitize_title( $incoming_name ) . '-london', $term->slug );
		$this->assertSame( 'West Hollywood', get_term_meta( $original_id, '_venue_city', true ) );
	}

	public function test_two_compatible_exact_name_candidates_still_ambiguous(): void {
		$name  = 'Ambiguous Twins Venue ' . uniqid();
		$first = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $first );
		$second = wp_insert_term( $name, 'venue', array( 'slug' => sanitize_title( $name ) . '-2' ) );
		$this->assertNotWPError( $second );

		$result = Venue_Taxonomy::find_or_create_venue( $name, array() );

		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'ambiguous', $result['match_status'] );
	}
}
