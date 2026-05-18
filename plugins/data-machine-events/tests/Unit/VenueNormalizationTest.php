<?php
/**
 * Venue Normalization Tests
 *
 * Covers Venue_Taxonomy::normalize_venue_name_for_matching() and
 * normalize_address_for_matching() — the two primitives that decide
 * whether two venue terms collide.
 *
 * Issue #276: ampersand / HTML-entity / apostrophe and suite-suffix
 * variants must all collapse to the same key.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.35.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueNormalizationTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	// ---------------------------------------------------------------------
	// Part A: name normalization
	// ---------------------------------------------------------------------

	public function test_normalize_venue_name_collapses_ampersand_and_and(): void {
		$canonical = Venue_Taxonomy::normalize_venue_name_for_matching( 'Hook and Ladder Theater' );

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_venue_name_for_matching( 'Hook & Ladder Theater' )
		);

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_venue_name_for_matching( 'Hook &amp; Ladder Theater' )
		);

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_venue_name_for_matching( 'hook  &amp;  ladder    theater' )
		);

		// Sanity check the canonical form itself.
		$this->assertSame( 'hook and ladder theater', $canonical );
	}

	public function test_normalize_venue_name_collapses_apostrophes(): void {
		$canonical = Venue_Taxonomy::normalize_venue_name_for_matching( 'Amos Southend' );

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_venue_name_for_matching( "Amos' Southend" )
		);

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_venue_name_for_matching( "Amos&#8217; Southend" )
		);

		$this->assertSame( 'amos southend', $canonical );

		// Cliff Bell's variant from the issue.
		$this->assertSame(
			Venue_Taxonomy::normalize_venue_name_for_matching( 'Cliff Bells' ),
			Venue_Taxonomy::normalize_venue_name_for_matching( "Cliff Bell's" )
		);

		// Proctor's Theatre variant from the issue.
		$this->assertSame(
			Venue_Taxonomy::normalize_venue_name_for_matching( 'Proctors Theatre' ),
			Venue_Taxonomy::normalize_venue_name_for_matching( "Proctor's Theatre" )
		);
	}

	public function test_normalize_venue_name_idempotent(): void {
		$already = 'hook and ladder theater';

		$this->assertSame(
			$already,
			Venue_Taxonomy::normalize_venue_name_for_matching( $already )
		);

		// Run it twice to be safe — normalization is a fixed point.
		$this->assertSame(
			$already,
			Venue_Taxonomy::normalize_venue_name_for_matching(
				Venue_Taxonomy::normalize_venue_name_for_matching( 'Hook & Ladder Theater' )
			)
		);
	}

	// ---------------------------------------------------------------------
	// Part B: address normalization
	// ---------------------------------------------------------------------

	public function test_normalize_address_strips_suite_suffix(): void {
		$canonical = Venue_Taxonomy::normalize_address_for_matching( '3010 Minnehaha Ave' );

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_address_for_matching( '3010 Minnehaha Ave STE 420' )
		);

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_address_for_matching( '3010 Minnehaha Ave Suite 420' )
		);

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_address_for_matching( '3010 Minnehaha Ave Unit 420' )
		);

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_address_for_matching( '3010 Minnehaha Ave #420' )
		);

		$this->assertSame(
			$canonical,
			Venue_Taxonomy::normalize_address_for_matching( '3010 minnehaha ave, ste 420' )
		);
	}

	public function test_normalize_address_idempotent(): void {
		$already = Venue_Taxonomy::normalize_address_for_matching( '3010 Minnehaha Ave STE 420' );

		$this->assertSame(
			$already,
			Venue_Taxonomy::normalize_address_for_matching( $already )
		);

		// Plain, already-canonical address.
		$plain = '123 main st';
		$this->assertSame( $plain, Venue_Taxonomy::normalize_address_for_matching( $plain ) );
	}

	// ---------------------------------------------------------------------
	// Integration: find_or_create_venue collapses variants
	// ---------------------------------------------------------------------

	public function test_find_or_create_venue_returns_same_id_for_ampersand_variants(): void {
		$venue_data = array(
			'address' => '3010 Minnehaha Ave',
			'city'    => 'Minneapolis',
			'state'   => 'MN',
		);

		$first = Venue_Taxonomy::find_or_create_venue( 'Hook & Ladder Theater', $venue_data );
		$this->assertIsArray( $first );
		$this->assertNotNull( $first['term_id'], 'First call should create a venue term.' );
		$this->assertTrue( $first['was_created'] );

		$second = Venue_Taxonomy::find_or_create_venue( 'Hook and Ladder Theater', $venue_data );
		$this->assertIsArray( $second );
		$this->assertSame(
			$first['term_id'],
			$second['term_id'],
			'Ampersand vs "and" variant should resolve to the same venue term.'
		);
		$this->assertFalse( $second['was_created'] );
	}

	public function test_find_or_create_venue_returns_same_id_for_suite_address_variants(): void {
		$venue_data_plain = array(
			'address' => '3010 Minnehaha Ave',
			'city'    => 'Minneapolis',
			'state'   => 'MN',
		);

		$first = Venue_Taxonomy::find_or_create_venue( 'Hook and Ladder Theater', $venue_data_plain );
		$this->assertIsArray( $first );
		$this->assertNotNull( $first['term_id'] );

		$venue_data_suite = array(
			'address' => '3010 Minnehaha Ave STE 420',
			'city'    => 'Minneapolis',
			'state'   => 'MN',
		);

		$second = Venue_Taxonomy::find_or_create_venue( 'Hook and Ladder Theater', $venue_data_suite );
		$this->assertSame(
			$first['term_id'],
			$second['term_id'],
			'Suite-suffix address variant should resolve to the same venue term.'
		);
	}
}
