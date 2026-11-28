<?php
/**
 * VenueStatsAbilities Tests
 *
 * Covers Part C of issue #277: the small `data-machine-events/venue-stats`
 * ability that the weekly qualify-digest will read for trend lines.
 *
 * Calls the ability's execute method directly rather than going through
 * wp_register_ability + the Abilities API runner. The shape is what the
 * downstream consumer (extrachill-events digest) keys on; the unit test
 * pins exactly that shape.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.38.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Abilities\VenueStatsAbilities;

class VenueStatsAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	private function make_venue( string $name, array $meta = array(), int $faked_count = 0 ): int {
		global $wpdb;

		$term = wp_insert_term( $name, 'venue' );
		$this->assertNotInstanceOf( \WP_Error::class, $term );
		$term_id = (int) $term['term_id'];

		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, $key, $value );
		}

		if ( $faked_count > 0 ) {
			$wpdb->update(
				$wpdb->term_taxonomy,
				array( 'count' => $faked_count ),
				array(
					'term_id'  => $term_id,
					'taxonomy' => 'venue',
				),
				array( '%d' ),
				array( '%d', '%s' )
			);
			clean_term_cache( array( $term_id ), 'venue' );
		}

		return $term_id;
	}

	public function test_venue_stats_ability_returns_expected_shape(): void {
		// Seed: 5 venue terms total
		//   - 2 with no _venue_address (counts as no_address)
		//   - 2 with addresses AND count=2 (NOT orphans, NOT missing address)
		//   - 1 with no address AND count=0 (counts in BOTH no_address and orphans)
		//
		// Expected:
		//   no_address = 3
		//   orphans    = 3 (2 of the seeded no-address + 1 of the joint)
		//                  wait — we need to re-tally. Two seeded "no_address only" have count=0
		//                  because nothing was assigned. The joint one is also count=0. So
		//                  orphans = 3 (all the no-address ones). The two addressed ones have
		//                  count=2 explicitly faked.
		//   total      = 5

		$this->make_venue( 'No Address A' );
		$this->make_venue( 'No Address B' );
		$this->make_venue( 'Joint (no addr + orphan)' );

		$this->make_venue(
			'Addressed Active A',
			array( '_venue_address' => '100 Main St' ),
			2
		);
		$this->make_venue(
			'Addressed Active B',
			array( '_venue_address' => '200 Oak Ave' ),
			2
		);

		$ability = new VenueStatsAbilities();
		$result  = $ability->executeVenueStats( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'no_address', $result );
		$this->assertArrayHasKey( 'orphans', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'queried_at', $result );

		$this->assertSame( 5, $result['total'] );
		$this->assertSame( 3, $result['no_address'] );
		$this->assertSame( 3, $result['orphans'] );

		// queried_at is a recent unix timestamp.
		$this->assertIsInt( $result['queried_at'] );
		$this->assertGreaterThan( time() - 60, $result['queried_at'] );
		$this->assertLessThanOrEqual( time(), $result['queried_at'] );
	}
}
