<?php
/**
 * Address-less listing venue variants (#3965).
 *
 * Multi-venue listing pages give only a venue name plus "City, ST". Their
 * names are routinely variants of the venue's own term ("Iron Horse Music
 * Hall" for "The Iron Horse"), and the import created ~100 duplicate venue
 * terms. Venue_Taxonomy::find_or_create_venue() now resolves those to the
 * existing same-city term and reports ambiguity instead of guessing.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueTaxonomyListingVariantTest extends WP_UnitTestCase {

	/** @var int[] */
	private array $existing = array();

	public function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		$this->existing = $this->termIds();
	}

	public function tearDown(): void {
		foreach ( array_diff( $this->termIds(), $this->existing ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		parent::tearDown();
	}

	public function test_music_hall_variant_resolves_to_existing_same_city_venue(): void {
		$term_id = $this->venue( 'The Iron Horse', 'Northampton', 'MA', '20 Center St' );

		$result = Venue_Taxonomy::find_or_create_venue( 'Iron Horse Music Hall', array( 'city' => 'Northampton', 'state' => 'MA', 'country' => 'US' ) );

		$this->assertSame( $term_id, (int) $result['term_id'] );
		$this->assertFalse( $result['was_created'] );
		$this->assertSame( 'matched', $result['match_status'] );
		$this->assertSame( 'name_variant', $result['matched_via'] );
		$this->assertFalse( get_term_by( 'name', 'Iron Horse Music Hall', 'venue' ) );
	}

	/**
	 * @dataProvider listing_variant_provider
	 */
	public function test_listing_variants_resolve_to_existing_venue( string $stored, string $incoming, string $city, string $state ): void {
		$term_id = $this->venue( $stored, $city, $state );

		$identity = Venue_Taxonomy::resolve_venue_identity( $incoming, array( 'city' => $city, 'state' => $state ) );

		$stored_term = get_term( $term_id, 'venue' );
		$diagnostic  = wp_json_encode(
			array(
				'identity'    => array( $identity['match_status'], $identity['term_id'], $identity['matched_via'] ),
				'stored_name' => $stored_term ? $stored_term->name : null,
				'stored_key'  => Venue_Taxonomy::venue_variant_key( $stored_term ? $stored_term->name : $stored, $state, $city ),
				'incoming'    => Venue_Taxonomy::venue_variant_key( $incoming, $state, $city ),
				'variants'    => Venue_Taxonomy::venue_names_are_variants( $incoming, $stored_term ? $stored_term->name : $stored, $state, $state, $city, $city ),
				'term_count'  => count( $this->termIds() ),
			)
		);
		$this->assertSame( 'matched', $identity['match_status'], "{$incoming} should resolve to {$stored}: {$diagnostic}" );
		$this->assertSame( $term_id, (int) $identity['term_id'] );
	}

	public function listing_variant_provider(): array {
		return array(
			'sponsor suffix'      => array( 'Paradise Rock Club presented by Citizens', 'Paradise Rock Club', 'Boston', 'MA' ),
			'state suffix'        => array( 'Rockwell - MA', 'Rockwell', 'Somerville', 'MA' ),
			'city suffix'         => array( 'Roadrunner-Boston', 'Roadrunner', 'Boston', 'MA' ),
			'room, venue'         => array( 'Capitol Center for the Arts', 'John F. Swope Theater, Capitol Center for the Arts', 'Concord', 'NH' ),
			'parenthetical city'  => array( 'The Grand (Boston)', 'The Grand', 'Boston', 'MA' ),
			'room at vs comma'    => array( 'Crystal Ballroom at Somerville Theatre', 'Crystal Ballroom, Somerville Theatre', 'Somerville', 'MA' ),
			'music hall + accent' => array( 'Café Nine', 'Cafe Nine Music Hall', 'New Haven', 'CT' ),
		);
	}

	public function test_two_same_city_variants_are_ambiguous(): void {
		$this->venue( 'Paradise Rock Club presented by Citizens', 'Boston', 'MA' );
		$this->venue( 'Paradise Rock Club (Boston)', 'Boston', 'MA' );
		$before = count( $this->termIds() );

		$result = Venue_Taxonomy::find_or_create_venue( 'Paradise Rock Club', array( 'city' => 'Boston', 'state' => 'MA' ) );

		$this->assertSame( 'ambiguous', $result['match_status'] );
		$this->assertNull( $result['term_id'] );
		$this->assertFalse( $result['was_created'] );
		$this->assertCount( $before, $this->termIds() );
	}

	public function test_variant_in_other_city_is_not_matched(): void {
		$term_id = $this->venue( 'The Iron Horse', 'Northampton', 'MA' );

		$result = Venue_Taxonomy::find_or_create_venue( 'Iron Horse Music Hall', array( 'city' => 'Springfield', 'state' => 'MA' ) );

		$this->assertNotSame( $term_id, (int) $result['term_id'] );
		$this->assertTrue( $result['was_created'] );
	}

	public function test_variant_requires_incoming_city(): void {
		$term_id = $this->venue( 'The Iron Horse', 'Northampton', 'MA' );

		$identity = Venue_Taxonomy::resolve_venue_identity( 'Iron Horse Music Hall', array() );

		$this->assertNotSame( $term_id, (int) $identity['term_id'] );
	}

	/**
	 * An incoming street address keeps the stricter address/name rules:
	 * variants are only for address-less listings.
	 */
	public function test_variant_not_used_when_incoming_has_street_address(): void {
		$term_id = $this->venue( 'The Iron Horse', 'Northampton', 'MA', '20 Center St' );

		$identity = Venue_Taxonomy::resolve_venue_identity( 'Iron Horse Music Hall', array( 'address' => '99 King St', 'city' => 'Northampton', 'state' => 'MA' ) );

		$this->assertNotSame( $term_id, (int) $identity['term_id'] );
	}

	public function test_single_word_prefix_is_not_a_variant(): void {
		$term_id = $this->venue( 'The Basement East', 'Nashville', 'TN' );

		$identity = Venue_Taxonomy::resolve_venue_identity( 'The Basement', array( 'city' => 'Nashville', 'state' => 'TN' ) );

		$this->assertNotSame( $term_id, (int) $identity['term_id'] );
		$this->assertFalse( Venue_Taxonomy::venue_names_are_variants( 'The Basement', 'The Basement East' ) );
	}

	public function test_venue_names_are_variants_rules(): void {
		$this->assertTrue( Venue_Taxonomy::venue_names_are_variants( 'Iron Horse Music Hall', 'The Iron Horse' ) );
		$this->assertTrue( Venue_Taxonomy::venue_names_are_variants( "Jimmy's Jazz & Blues Club", 'Jimmys Jazz and Blues Club' ) );
		$this->assertTrue( Venue_Taxonomy::venue_names_are_variants( 'Rockwell', 'Rockwell - MA', 'MA', 'MA' ) );
		$this->assertFalse( Venue_Taxonomy::venue_names_are_variants( 'Rockwell', 'Rockwell - MA', '', 'CA' ) );
		$this->assertFalse( Venue_Taxonomy::venue_names_are_variants( 'Middle East - Upstairs', 'Middle East - Downstairs' ) );
		$this->assertFalse( Venue_Taxonomy::venue_names_are_variants( 'Royale', 'Roadrunner' ) );
		$this->assertFalse( Venue_Taxonomy::venue_names_are_variants( 'Upstairs at the Palladium', 'Palladium' ) );
	}

	/** @return int[] */
	private function termIds(): array {
		return array_map(
			'intval',
			(array) get_terms(
				array(
					'taxonomy'   => 'venue',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			)
		);
	}

	private function venue( string $name, string $city, string $state, string $address = '' ): int {
		$result = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $result );
		$term_id = (int) $result['term_id'];
		update_term_meta( $term_id, Venue_Taxonomy::$meta_fields['city'], $city );
		update_term_meta( $term_id, Venue_Taxonomy::$meta_fields['state'], $state );
		if ( '' !== $address ) {
			update_term_meta( $term_id, Venue_Taxonomy::$meta_fields['address'], $address );
		}

		return $term_id;
	}
}
