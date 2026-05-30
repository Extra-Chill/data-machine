<?php
/**
 * EventQueryBuilder geo-skip tests.
 *
 * Verifies that EventQueryBuilder::build_query_args() short-circuits the
 * geo-radius haversine query when the request is already scoped to a
 * single venue archive (archive_taxonomy=venue + archive_term_id), and
 * that the short-circuit does NOT fire for other archive taxonomies.
 *
 * Issue: Extra-Chill/data-machine-events#247
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Blocks\Calendar\Query\EventQueryBuilder;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventQueryBuilderGeoSkipTest extends WP_UnitTestCase {

	private int $venue_a;
	private int $venue_b;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		// Two venues at known coordinates so any haversine-driven test
		// would observably differ from a non-geo run.
		$term_a = wp_insert_term( 'Geo Skip Venue A ' . uniqid(), 'venue' );
		$term_b = wp_insert_term( 'Geo Skip Venue B ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term_a );
		$this->assertNotWPError( $term_b );

		$this->venue_a = (int) $term_a['term_id'];
		$this->venue_b = (int) $term_b['term_id'];

		// Seattle-ish (covered by 47.66,-122.33 / r=2mi)
		update_term_meta( $this->venue_a, '_venue_coordinates', '47.660,-122.330' );
		// Far away (Charleston, SC) — never matched by the Seattle query.
		update_term_meta( $this->venue_b, '_venue_coordinates', '32.776,-79.931' );
	}

	public function tearDown(): void {
		wp_delete_term( $this->venue_a, 'venue' );
		wp_delete_term( $this->venue_b, 'venue' );
		parent::tearDown();
	}

	/**
	 * Strip out date filter callbacks from the args so we can compare the
	 * shape we actually care about (post_type, tax_query, etc.) without
	 * the closure-identity noise of posts_clauses callbacks.
	 *
	 * @param array $args Args returned from build_query_args().
	 * @return array Cleaned args.
	 */
	private function clean_args( array $args ): array {
		// posts_clauses filters live outside the args array — nothing to scrub.
		unset( $args['s'] );
		return $args;
	}

	public function test_skip_geo_when_archive_taxonomy_is_venue() {
		$with_geo = EventQueryBuilder::build_query_args(
			array(
				'archive_taxonomy'   => 'venue',
				'archive_term_id'    => $this->venue_a,
				'tax_query_override' => array(
					array(
						'taxonomy' => 'venue',
						'field'    => 'term_id',
						'terms'    => $this->venue_a,
					),
				),
				'geo_lat'            => 47.66,
				'geo_lng'            => -122.33,
				'geo_radius'         => 2,
				'geo_radius_unit'    => 'mi',
			)
		);
		call_user_func( $with_geo['cleanup'] );

		$without_geo = EventQueryBuilder::build_query_args(
			array(
				'archive_taxonomy'   => 'venue',
				'archive_term_id'    => $this->venue_a,
				'tax_query_override' => array(
					array(
						'taxonomy' => 'venue',
						'field'    => 'term_id',
						'terms'    => $this->venue_a,
					),
				),
			)
		);
		call_user_func( $without_geo['cleanup'] );

		$this->assertEquals(
			$this->clean_args( $without_geo['args'] ),
			$this->clean_args( $with_geo['args'] ),
			'Args with geo params should be identical to args without them when archive_taxonomy=venue.'
		);

		// Direct shape check: tax_query should NOT contain any clause whose
		// terms include venue_b (the far-away venue would have been filtered
		// out by haversine, but only if haversine actually ran).
		$tax_query = $with_geo['args']['tax_query'] ?? array();
		foreach ( $tax_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}
			if ( is_array( $clause ) && isset( $clause['terms'] ) ) {
				$terms = (array) $clause['terms'];
				$this->assertNotContains(
					0,
					$terms,
					'tax_query should not contain the "no venues matched" sentinel — proves haversine did not run.'
				);
			}
		}
	}

	public function test_geo_still_runs_for_artist_archive() {
		$artist_term = wp_insert_term( 'Geo Skip Artist ' . uniqid(), 'post_tag' );
		$this->assertNotWPError( $artist_term );
		$artist_id = (int) $artist_term['term_id'];

		$result = EventQueryBuilder::build_query_args(
			array(
				'archive_taxonomy' => 'artist',
				'archive_term_id'  => $artist_id,
				'geo_lat'          => 47.66,
				'geo_lng'          => -122.33,
				'geo_radius'       => 2,
				'geo_radius_unit'  => 'mi',
			)
		);
		call_user_func( $result['cleanup'] );

		// Geo filter should have injected a venue tax_query clause.
		$tax_query        = $result['args']['tax_query'] ?? array();
		$has_venue_clause = false;
		foreach ( $tax_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}
			if ( is_array( $clause ) && ( $clause['taxonomy'] ?? '' ) === 'venue' ) {
				$has_venue_clause = true;
				break;
			}
		}
		$this->assertTrue(
			$has_venue_clause,
			'Geo filter must still execute (and inject a venue tax_query clause) for non-venue archives.'
		);

		wp_delete_term( $artist_id, 'post_tag' );
	}

	public function test_geo_runs_when_no_archive_set() {
		$result = EventQueryBuilder::build_query_args(
			array(
				'geo_lat'         => 47.66,
				'geo_lng'         => -122.33,
				'geo_radius'      => 2,
				'geo_radius_unit' => 'mi',
			)
		);
		call_user_func( $result['cleanup'] );

		$tax_query        = $result['args']['tax_query'] ?? array();
		$has_venue_clause = false;
		foreach ( $tax_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}
			if ( is_array( $clause ) && ( $clause['taxonomy'] ?? '' ) === 'venue' ) {
				$has_venue_clause = true;
				break;
			}
		}
		$this->assertTrue(
			$has_venue_clause,
			'Geo filter must execute when no archive is set.'
		);
	}

	public function test_geo_skipped_only_when_both_archive_taxonomy_and_term_id_set() {
		// archive_taxonomy=venue but no term_id → geo must NOT be skipped.
		$result = EventQueryBuilder::build_query_args(
			array(
				'archive_taxonomy' => 'venue',
				'archive_term_id'  => 0,
				'geo_lat'          => 47.66,
				'geo_lng'          => -122.33,
				'geo_radius'       => 2,
				'geo_radius_unit'  => 'mi',
			)
		);
		call_user_func( $result['cleanup'] );

		$tax_query        = $result['args']['tax_query'] ?? array();
		$has_venue_clause = false;
		foreach ( $tax_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}
			if ( is_array( $clause ) && ( $clause['taxonomy'] ?? '' ) === 'venue' ) {
				$has_venue_clause = true;
				break;
			}
		}
		$this->assertTrue(
			$has_venue_clause,
			'Geo filter must execute when archive_term_id is missing — the venue scope is not actually narrow.'
		);
	}
}
