<?php
/**
 * Venue Fuzzy Name Match Tests
 *
 * Covers the fuzzy typo tier of Venue_Taxonomy::find_venue_by_qualified_name()
 * reached through find_or_create_venue().
 *
 * Issue #921: a one- or two-character typo of an existing venue name
 * ("Recoery Room Tavern") in the same city must resolve to the existing
 * term instead of splitting the venue's archive across a second term. The
 * same typo in a different city stays a conflict and creates a distinct
 * term; several near matches stay ambiguous with no silent pick.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.66.10
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueTaxonomyFuzzyMatchTest extends WP_UnitTestCase {
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
	 * @param string                $name Venue name.
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

	private function venue_count(): int {
		return (int) wp_count_terms( array( 'taxonomy' => 'venue', 'hide_empty' => false ) );
	}

	// ---------------------------------------------------------------------
	// Part A: #921 done-when scenarios
	// ---------------------------------------------------------------------

	public function test_one_character_typo_same_city_resolves_to_existing_term(): void {
		$suffix  = uniqid();
		$term_id = $this->create_venue_with_meta(
			"Recovery Room Tavern {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);
		$before = $this->venue_count();

		$result = Venue_Taxonomy::find_or_create_venue(
			"Recoery Room Tavern {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertFalse( $result['was_created'] );
		$this->assertSame( $before, $this->venue_count(), 'A typo must not create a second venue term.' );
	}

	public function test_one_character_typo_different_city_still_creates_new_term(): void {
		$suffix  = uniqid();
		$term_id = $this->create_venue_with_meta(
			"Recovery Room Tavern {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);

		$result = Venue_Taxonomy::find_or_create_venue(
			"Recoery Room Tavern {$suffix}",
			array(
				'city'  => 'Atlanta',
				'state' => 'GA',
			)
		);

		// The near match is rejected by geography, so the conflict path
		// creates a distinct venue instead of merging places (#806, #921).
		$this->assertSame( 'created', $result['match_status'] );
		$this->assertTrue( $result['was_created'] );
		$this->assertNotNull( $result['term_id'] );
		$this->assertNotSame( $term_id, $result['term_id'] );
		$this->assertSame( 'Atlanta', get_term_meta( (int) $result['term_id'], '_venue_city', true ) );

		// The original Charleston term is untouched.
		$this->assertSame( 'Charleston', get_term_meta( $term_id, '_venue_city', true ) );
	}

	public function test_two_near_matches_same_city_resolve_as_ambiguous(): void {
		$suffix = uniqid();
		$this->create_venue_with_meta(
			"Recovery Room Tavern {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);
		$this->create_venue_with_meta(
			"Recovery Room Taverns {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);
		$before = $this->venue_count();

		$result = Venue_Taxonomy::find_or_create_venue(
			"Recoery Room Tavern {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);

		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'ambiguous', $result['match_status'] );
		$this->assertSame( $before, $this->venue_count(), 'Ambiguous near matches must not silently pick or create a term.' );
	}

	// ---------------------------------------------------------------------
	// Part B: gating details
	// ---------------------------------------------------------------------

	public function test_near_match_with_conflicting_address_creates_distinct_term(): void {
		$suffix  = uniqid();
		$term_id = $this->create_venue_with_meta(
			"Recovery Room Tavern {$suffix}",
			array(
				'address' => '100 King Street',
				'city'    => 'Charleston',
				'state'   => 'SC',
			)
		);

		$result = Venue_Taxonomy::find_or_create_venue(
			"Recoery Room Tavern {$suffix}",
			array(
				'address' => '200 King Street',
				'city'    => 'Charleston',
				'state'   => 'SC',
			)
		);

		// A near match contradicted by a different street is a different
		// venue, not the existing one (#806 semantics on the fuzzy tier).
		$this->assertSame( 'created', $result['match_status'] );
		$this->assertNotSame( $term_id, $result['term_id'] );
		$this->assertSame( '200 King Street', get_term_meta( (int) $result['term_id'], '_venue_address', true ) );
		$this->assertSame( '100 King Street', get_term_meta( $term_id, '_venue_address', true ) );
	}

	public function test_short_names_never_fuzzy_match(): void {
		$this->create_venue_with_meta(
			'The Jinx',
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);

		$result = Venue_Taxonomy::find_or_create_venue(
			'The Jenx',
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);

		// Normalized names under five characters carry too little signal
		// for edit-distance matching (#921).
		$this->assertSame( 'created', $result['match_status'] );
		$this->assertTrue( $result['was_created'] );
	}

	public function test_exact_name_match_still_wins_before_fuzzy_tier(): void {
		$suffix  = uniqid();
		$term_id = $this->create_venue_with_meta(
			"Recovery Room Tavern {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);

		$result = Venue_Taxonomy::resolve_venue_identity(
			"Recovery Room Tavern {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertSame( 'name', $result['matched_via'] );
	}

	// ---------------------------------------------------------------------
	// Part C: fuzzy matches need positive location evidence (#921 review)
	// ---------------------------------------------------------------------

	public function test_near_match_without_incoming_city_creates_new_term(): void {
		// Flyer and AI extractions often carry no city. A near-named venue in
		// some other market must not absorb the event just because nothing
		// contradicts it ("Red Room" vs. Buffalo's "Rec Room").
		$suffix  = uniqid();
		$term_id = $this->create_venue_with_meta(
			"Recovery Room Tavern {$suffix}",
			array(
				'city'  => 'Charleston',
				'state' => 'SC',
			)
		);
		$before = $this->venue_count();

		$result = Venue_Taxonomy::find_or_create_venue( "Recoery Room Tavern {$suffix}", array() );

		$this->assertSame( 'created', $result['match_status'] );
		$this->assertTrue( $result['was_created'] );
		$this->assertNotSame( $term_id, $result['term_id'] );
		$this->assertSame( $before + 1, $this->venue_count() );
		$this->assertSame( "Recovery Room Tavern {$suffix}", get_term( $term_id, 'venue' )->name, 'The existing venue must not be merged into.' );
	}

	public function test_near_match_with_no_stored_city_creates_new_term(): void {
		$suffix  = uniqid();
		$term_id = $this->create_venue_with_meta( "Recovery Room Tavern {$suffix}", array() );

		$result = Venue_Taxonomy::find_or_create_venue(
			"Recoery Room Tavern {$suffix}",
			array( 'city' => 'Charleston' )
		);

		$this->assertTrue( $result['was_created'] );
		$this->assertNotSame( $term_id, $result['term_id'] );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_city', true ), 'The existing venue must not be merged into.' );
	}

	public function test_near_match_with_same_street_address_and_no_city_resolves(): void {
		$suffix  = uniqid();
		$term_id = $this->create_venue_with_meta(
			"Recovery Room Tavern {$suffix}",
			array( 'address' => '685 King Street' )
		);

		$result = Venue_Taxonomy::find_or_create_venue(
			"Recoery Room Tavern {$suffix}",
			array( 'address' => '685 King St' )
		);

		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertFalse( $result['was_created'] );
	}
}
