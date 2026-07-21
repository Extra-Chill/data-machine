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

	public function test_same_name_in_different_city_returns_ambiguity_without_merging(): void {
		$name  = 'The Foundry Collision ' . uniqid();
		$first = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '100 Main Street',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);
		$result = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '200 Main Street',
				'city'    => 'Atlanta',
				'state'   => 'GA',
				'country' => 'US',
				'phone'   => '555-0100',
			)
		);

		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'ambiguous', $result['match_status'] );
		$this->assertSame( '', get_term_meta( $first['term_id'], '_venue_phone', true ) );
	}

	public function test_missing_address_preserves_safe_name_match(): void {
		$name  = 'Incomplete Evidence Venue ' . uniqid();
		$first = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '300 King Street',
				'city'    => 'Charleston',
				'state'   => 'SC',
			)
		);
		$result = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'city'  => 'Charleston',
				'phone' => '555-0101',
			)
		);

		$this->assertSame( $first['term_id'], $result['term_id'] );
		$this->assertSame( '555-0101', get_term_meta( $first['term_id'], '_venue_phone', true ) );
	}

	public function test_state_and_country_conflicts_reject_address_identity(): void {
		$name  = 'Border Venue ' . uniqid();
		$first = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '400 State Street',
				'city'    => 'Springfield',
				'state'   => 'IL',
				'country' => 'US',
			)
		);

		$state_conflict = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '400 State Street',
				'city'    => 'Springfield',
				'state'   => 'MO',
				'country' => 'US',
			)
		);
		$country_conflict = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '400 State Street',
				'city'    => 'Springfield',
				'state'   => 'IL',
				'country' => 'CA',
			)
		);

		$this->assertNotNull( $first['term_id'] );
		$this->assertNull(
			Venue_Taxonomy::find_venue_by_address( '400 State St.', 'Springfield', 'MO', 'US' )
		);
		$this->assertNull(
			Venue_Taxonomy::find_venue_by_address( '400 State St.', 'Springfield', 'IL', 'CA' )
		);
		$this->assertNull( $state_conflict['term_id'] );
		$this->assertNull( $country_conflict['term_id'] );
		$this->assertSame( 'ambiguous', $state_conflict['match_status'] );
		$this->assertSame( 'ambiguous', $country_conflict['match_status'] );
	}

	public function test_equivalent_state_and_country_forms_preserve_identity(): void {
		$name  = 'Geography Alias Venue ' . uniqid();
		$first = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '600 Meeting Street',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);
		$full_names = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '600 Meeting St.',
				'city'    => 'Charleston',
				'state'   => 'South Carolina',
				'country' => 'United States',
			)
		);
		$country_code = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'USA',
			)
		);

		$this->assertSame( $first['term_id'], $full_names['term_id'] );
		$this->assertSame( $first['term_id'], $country_code['term_id'] );
		$this->assertSame( 'matched', $full_names['match_status'] );
		$this->assertSame( 'matched', $country_code['match_status'] );
	}

	public function test_article_toggle_does_not_cross_city_boundaries(): void {
		$base  = 'Article Collision ' . uniqid();
		$first = Venue_Taxonomy::find_or_create_venue(
			'The ' . $base,
			array(
				'city'  => 'Nashville',
				'state' => 'TN',
			)
		);
		$result = Venue_Taxonomy::find_or_create_venue(
			$base,
			array(
				'city'  => 'Austin',
				'state' => 'TX',
			)
		);

		$this->assertNotNull( $first['term_id'] );
		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'ambiguous', $result['match_status'] );
	}

	public function test_normalized_name_collision_does_not_cross_city_boundaries(): void {
		$suffix = uniqid();
		$first  = Venue_Taxonomy::find_or_create_venue(
			'Rock - House ' . $suffix,
			array(
				'city'  => 'Denver',
				'state' => 'CO',
			)
		);
		$result = Venue_Taxonomy::find_or_create_venue(
			'Rock House ' . $suffix,
			array(
				'city'  => 'Phoenix',
				'state' => 'AZ',
			)
		);

		$this->assertNotNull( $first['term_id'] );
		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'ambiguous', $result['match_status'] );
	}

	public function test_compatible_identity_fills_empty_metadata_without_overwriting(): void {
		$name    = 'Metadata Merge Venue ' . uniqid();
		$created = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address'     => '500 Market Street',
				'city'        => 'Philadelphia',
				'website'     => 'https://existing.example',
				'coordinates' => '39.9526,-75.1652',
			)
		);
		$result  = Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '500 Market St.',
				'city'    => 'Philadelphia',
				'state'   => 'PA',
				'country' => 'US',
				'website' => 'https://incoming.example',
			)
		);

		$this->assertSame( $created['term_id'], $result['term_id'] );
		$this->assertSame( 'PA', get_term_meta( $created['term_id'], '_venue_state', true ) );
		$this->assertSame( 'US', get_term_meta( $created['term_id'], '_venue_country', true ) );
		$this->assertSame( 'https://existing.example', get_term_meta( $created['term_id'], '_venue_website', true ) );
	}

	// ---------------------------------------------------------------------
	// Part C: address-in-name extraction (#433)
	// ---------------------------------------------------------------------

	public function test_extract_address_from_name_strips_full_address(): void {
		$result = Venue_Taxonomy::extract_address_from_name(
			'The Dinghy , 8 J C Long Blvd, Isle of Palms, SC 29451'
		);

		$this->assertSame( 'The Dinghy', $result['name'] );
		$this->assertSame( '8 J C Long Blvd', $result['address'] );
		$this->assertSame( 'Isle of Palms', $result['city'] );
		$this->assertSame( 'SC', $result['state'] );
		$this->assertSame( '29451', $result['zip'] );
	}

	public function test_extract_address_from_name_strips_address_no_zip(): void {
		$result = Venue_Taxonomy::extract_address_from_name(
			'Blind Tiger Pub, 36-38 Broad St, Charleston, SC'
		);

		$this->assertSame( 'Blind Tiger Pub', $result['name'] );
		$this->assertSame( '36-38 Broad St', $result['address'] );
		$this->assertSame( 'Charleston', $result['city'] );
		$this->assertSame( 'SC', $result['state'] );
	}

	public function test_extract_address_from_name_strips_city_state_only(): void {
		$result = Venue_Taxonomy::extract_address_from_name( 'Lake Oconee , Greensboro, GA' );

		$this->assertSame( 'Lake Oconee', $result['name'] );
		$this->assertSame( '', $result['address'] );
		$this->assertSame( 'Greensboro', $result['city'] );
		$this->assertSame( 'GA', $result['state'] );
	}

	public function test_extract_address_from_name_ignores_plain_name(): void {
		$this->assertSame( array(), Venue_Taxonomy::extract_address_from_name( 'The Dinghy' ) );
	}

	public function test_extract_address_from_name_does_not_truncate_legit_comma_name(): void {
		// "Restaurant & Grill" does not end in a state abbreviation, so the
		// conservative guard must refuse to touch it.
		$this->assertSame(
			array(),
			Venue_Taxonomy::extract_address_from_name( 'Bar, Restaurant & Grill' )
		);
	}

	public function test_extract_address_from_name_does_not_truncate_slash_name(): void {
		// No comma at all — nothing to strip regardless of trailing tokens.
		$this->assertSame( array(), Venue_Taxonomy::extract_address_from_name( 'RADIO/EAST' ) );
	}

	public function test_find_or_create_venue_dedupes_clean_name_and_address_baked_name(): void {
		// First call: clean name only (no metadata) — this is the scenario
		// from #433 where a canonical clean-name term already exists.
		$clean = Venue_Taxonomy::find_or_create_venue( 'The Dinghy Test ' . uniqid() );
		$this->assertIsArray( $clean );
		$this->assertNotNull( $clean['term_id'] );

		$clean_term = get_term( $clean['term_id'], 'venue' );

		// Second call: AI-style blob with the address baked into the name,
		// but matching address metadata so it resolves via address match
		// once extraction populates $venue_data.
		$blob_name = $clean_term->name . ' , 8 J C Long Blvd, Isle of Palms, SC 29451';
		$blob      = Venue_Taxonomy::find_or_create_venue(
			$blob_name,
			array(
				'address' => '8 J C Long Blvd',
				'city'    => 'Isle of Palms',
				'state'   => 'SC',
				'zip'     => '29451',
			)
		);

		$this->assertIsArray( $blob );
		$this->assertNotNull( $blob['term_id'] );

		// Third call: same blob, but WITHOUT any pre-supplied address
		// metadata — extraction must recover it from the name itself and
		// still land on the same term via the normalized-name match
		// (since no address meta was pre-populated on the second call's
		// term until smart-merge runs).
		$blob_only_name = $clean_term->name . ' , 8 J C Long Blvd, Isle of Palms, SC 29451';
		$blob_only      = Venue_Taxonomy::find_or_create_venue( $blob_only_name );

		$this->assertIsArray( $blob_only );
		$this->assertSame(
			$blob['term_id'],
			$blob_only['term_id'],
			'Extraction must recover address from the name itself even with no pre-supplied venue_data.'
		);

		// The created term's name must be the clean name — never the blob.
		$created_term = get_term( $blob['term_id'], 'venue' );
		$this->assertSame( $clean_term->name, $created_term->name );

		// Address metadata must have landed on the term, not been discarded.
		$saved_address = get_term_meta( $blob['term_id'], '_venue_address', true );
		$this->assertSame( '8 J C Long Blvd', $saved_address );
	}
}
