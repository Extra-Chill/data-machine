<?php
/**
 * Evidence-ranked same-name venue selection tests (#4030).
 *
 * Several same-name venue terms that survive the geographic filter are no
 * longer automatically ambiguous: a single candidate with strong location
 * evidence wins, and same-coordinate candidates resolve to the term with
 * the most events. Different streets without coordinate agreement stay
 * ambiguous.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueTaxonomyEvidenceRankedTest extends WP_UnitTestCase {
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
	 * Create a venue term with the supplied meta and event count.
	 *
	 * @param string                $name  Venue name.
	 * @param array<string, string> $meta  Field => value using data keys.
	 * @param int                   $count Stored event count.
	 * @return int
	 */
	private function create_venue( string $name, array $meta, int $count = 0 ): int {
		global $wpdb;
		static $suffix = 0;
		++$suffix;

		$result = wp_insert_term( $name, 'venue', array( 'slug' => sanitize_title( $name ) . '-ranked-' . $suffix ) );
		$this->assertNotWPError( $result );
		$term_id = (int) $result['term_id'];

		foreach ( $meta as $field => $value ) {
			if ( isset( Venue_Taxonomy::$meta_fields[ $field ] ) ) {
				update_term_meta( $term_id, Venue_Taxonomy::$meta_fields[ $field ], $value );
			}
		}

		if ( $count > 0 ) {
			$wpdb->update( $wpdb->term_taxonomy, array( 'count' => $count ), array( 'term_taxonomy_id' => (int) $result['term_taxonomy_id'] ) );
			clean_term_cache( $term_id, 'venue' );
		}

		return $term_id;
	}

	private function venue_count(): int {
		return (int) wp_count_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
			)
		);
	}

	public function test_dinghy_same_coordinate_terms_resolve_to_most_events(): void {
		$name   = 'The Dinghy ' . uniqid();
		$coords = '32.7854319,-79.7899092';
		$main   = $this->create_venue(
			$name,
			array(
				'city'        => 'Isle of Palms',
				'state'       => 'South Carolina',
				'zip'         => '29451',
				'coordinates' => $coords,
			),
			475
		);
		$fork   = $this->create_venue(
			$name,
			array(
				'address'     => '8 J C Long Blvd',
				'city'        => 'Palms',
				'state'       => 'SC',
				'zip'         => '29451',
				'coordinates' => $coords,
			),
			3
		);

		$incoming = array(
			'city'        => 'Isle of Palms',
			'state'       => 'South Carolina',
			'zip'         => '29451',
			'coordinates' => $coords,
		);

		$identity = Venue_Taxonomy::resolve_venue_identity( $name, $incoming );
		$this->assertSame( 'matched', $identity['match_status'] );
		$this->assertSame( 'evidence_ranked', $identity['matched_via'] );
		$this->assertSame( $main, $identity['term_id'] );
		$this->assertNotSame( $fork, $identity['term_id'] );

		$before = $this->venue_count();
		$result = Venue_Taxonomy::find_or_create_venue( $name, $incoming );
		$this->assertFalse( $result['was_created'] );
		$this->assertSame( $main, $result['term_id'] );
		$this->assertSame( $before, $this->venue_count() );
	}

	public function test_dinghy_same_coordinate_terms_resolve_without_incoming_coordinates(): void {
		$name   = 'The Dinghy ' . uniqid();
		$coords = '32.7854319,-79.7899092';
		$fork   = $this->create_venue(
			$name,
			array(
				'city'        => 'Isle of Palms',
				'state'       => 'SC',
				'coordinates' => '32.7854500,-79.7899000',
			),
			3
		);
		$main   = $this->create_venue(
			$name,
			array(
				'city'        => 'Isle of Palms',
				'state'       => 'South Carolina',
				'coordinates' => $coords,
			),
			475
		);

		$identity = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'city'  => 'Isle of Palms',
				'state' => 'South Carolina',
			)
		);

		$this->assertSame( 'matched', $identity['match_status'] );
		$this->assertSame( 'evidence_ranked', $identity['matched_via'] );
		$this->assertSame( $main, $identity['term_id'] );
		$this->assertNotSame( $fork, $identity['term_id'] );
	}

	public function test_city_label_does_not_override_coordinate_and_postal_match(): void {
		$term_id = $this->create_venue(
			'Palms Label Venue ' . uniqid(),
			array(
				'city'        => 'Palms',
				'state'       => 'SC',
				'zip'         => '29451',
				'coordinates' => '32.7854319,-79.7899092',
			)
		);

		$conflict = new \ReflectionMethod( Venue_Taxonomy::class, 'has_geographic_conflict' );
		$incoming = array(
			'city'        => 'Isle of Palms',
			'state'       => 'South Carolina',
			'zip'         => '29451',
			'coordinates' => '32.7854319,-79.7899092',
		);

		$this->assertFalse( $conflict->invoke( null, $term_id, $incoming ) );
		$this->assertTrue( $conflict->invoke( null, $term_id, array_merge( $incoming, array( 'zip' => '29452' ) ) ), 'A different postal code keeps the city conflict.' );
		$this->assertTrue( $conflict->invoke( null, $term_id, array_merge( $incoming, array( 'coordinates' => '32.80,-79.75' ) ) ), 'Distant coordinates keep the city conflict.' );
	}

	public function test_ai_warehouse_resolves_to_coordinate_cluster_with_most_events(): void {
		$name   = 'A.i. Warehouse ' . uniqid();
		$coords = '38.910198,-77.017899';
		$base   = array(
			'city'  => 'Washington',
			'state' => 'DC',
			'zip'   => '20001',
		);
		$far    = $this->create_venue( $name, array_merge( $base, array( 'address' => '1200 7th St NW', 'coordinates' => '38.9200,-77.0300' ) ), 50 );
		$minor  = $this->create_venue( $name, array_merge( $base, array( 'address' => '1515 N Capitol St', 'coordinates' => $coords ) ), 2 );
		$main   = $this->create_venue( $name, array_merge( $base, array( 'address' => '1525 N Capitol St', 'coordinates' => $coords ) ), 12 );

		$incoming = array_merge( $base, array( 'coordinates' => $coords ) );
		$identity = Venue_Taxonomy::resolve_venue_identity( $name, $incoming );

		$this->assertSame( 'matched', $identity['match_status'] );
		$this->assertSame( 'evidence_ranked', $identity['matched_via'] );
		$this->assertSame( $main, $identity['term_id'] );
		$this->assertNotContains( $identity['term_id'], array( $far, $minor ) );

		$before = $this->venue_count();
		$result = Venue_Taxonomy::find_or_create_venue( $name, $incoming );
		$this->assertFalse( $result['was_created'] );
		$this->assertSame( $main, $result['term_id'] );
		$this->assertSame( $before, $this->venue_count() );
	}

	public function test_rough_trade_single_nearby_candidate_wins(): void {
		$name = 'Rough Trade East ' . uniqid();
		$far  = $this->create_venue(
			$name,
			array(
				'address'     => '96-98 Pentonville Rd',
				'city'        => 'London',
				'coordinates' => '51.5318,-0.1115',
			),
			40
		);
		$near = $this->create_venue(
			$name,
			array(
				'address'     => '91 Brick Lane',
				'city'        => 'London',
				'coordinates' => '51.5210,-0.0720',
			),
			5
		);

		$identity = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'city'        => 'London',
				'coordinates' => '51.5217,-0.0723',
			)
		);

		$this->assertSame( 'matched', $identity['match_status'] );
		$this->assertSame( 'evidence_ranked', $identity['matched_via'] );
		$this->assertSame( $near, $identity['term_id'] );
		$this->assertNotSame( $far, $identity['term_id'] );
	}

	public function test_incoming_coordinates_matching_no_candidate_stay_ambiguous(): void {
		$name = 'Rough Trade Elsewhere ' . uniqid();
		$this->create_venue( $name, array( 'city' => 'London', 'coordinates' => '51.5318,-0.1115' ) );
		$this->create_venue( $name, array( 'city' => 'London', 'coordinates' => '51.5318,-0.1115' ) );

		$identity = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'city'        => 'London',
				'coordinates' => '51.5217,-0.0723',
			)
		);

		$this->assertSame( 'ambiguous', $identity['match_status'] );
		$this->assertNull( $identity['term_id'] );
	}

	public function test_street_and_postal_evidence_selects_single_candidate(): void {
		$name  = 'Street Evidence Venue ' . uniqid();
		$other = $this->create_venue( $name, array( 'city' => 'Denver', 'state' => 'CO' ), 30 );
		$match = $this->create_venue(
			$name,
			array(
				'address' => '3317 E Colfax Ave',
				'city'    => 'Denver',
				'state'   => 'CO',
				'zip'     => '80206',
			),
			4
		);

		// The address tier needs a stored city match; mirror an incoming
		// listing whose city label differs to reach the name tier.
		$identity = Venue_Taxonomy::resolve_venue_identity(
			$name,
			array(
				'address' => '3317 East Colfax Avenue',
				'state'   => 'CO',
				'zip'     => '80206',
			)
		);

		$this->assertSame( 'matched', $identity['match_status'] );
		$this->assertSame( 'evidence_ranked', $identity['matched_via'] );
		$this->assertSame( $match, $identity['term_id'] );
		$this->assertNotSame( $other, $identity['term_id'] );
	}

	public function test_lodge_room_different_streets_stay_ambiguous(): void {
		$name = 'Lodge Room ' . uniqid();
		$this->create_venue(
			$name,
			array(
				'address'     => '104 N Ave 56',
				'city'        => 'Los Angeles',
				'state'       => 'CA',
				'coordinates' => '34.1106,-118.1926',
			),
			100
		);
		$this->create_venue(
			$name,
			array(
				'address'     => '4210 N Figueroa St',
				'city'        => 'Los Angeles',
				'state'       => 'CA',
				'coordinates' => '34.0900,-118.2050',
			),
			10
		);

		$incoming = array(
			'city'  => 'Los Angeles',
			'state' => 'CA',
		);
		$identity = Venue_Taxonomy::resolve_venue_identity( $name, $incoming );
		$this->assertSame( 'ambiguous', $identity['match_status'] );
		$this->assertNull( $identity['term_id'] );

		$before = $this->venue_count();
		$result = Venue_Taxonomy::find_or_create_venue( $name, $incoming );
		$this->assertNull( $result['term_id'] );
		$this->assertSame( 'ambiguous', $result['match_status'] );
		$this->assertSame( $before, $this->venue_count() );
	}
}
