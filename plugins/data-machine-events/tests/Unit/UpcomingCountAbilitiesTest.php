<?php
/**
 * UpcomingCountAbilities Tests
 *
 * Covers the `data-machine-events/get-upcoming-counts` ability and the
 * optional co-occurrence filter pair (filter_taxonomy + filter_term_id)
 * used by per-archive upcoming-events stats lines on the consuming sites.
 *
 * Calls the ability's execute method directly (matching the
 * VenueStatsAbilitiesTest pattern) rather than going through the Abilities
 * API runner. The shape returned here is the contract the downstream
 * extrachill-events block keys on.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.39.2
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Abilities\UpcomingCountAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\EventDatesTable;

class UpcomingCountAbilitiesTest extends WP_UnitTestCase {

	private UpcomingCountAbilities $abilities;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		// `artist` is registered by extrachill-events in production. For the
		// unit test we just need *a* second taxonomy attached to the event CPT
		// so we can exercise the co-occurrence filter join.
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy(
				'artist',
				array( 'data_machine_events' ),
				array(
					'public'       => true,
					'hierarchical' => false,
					'rewrite'      => array( 'slug' => 'artist' ),
				)
			);
		} else {
			register_taxonomy_for_object_type( 'artist', 'data_machine_events' );
		}

		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}

		$this->abilities = new UpcomingCountAbilities();
	}

	/**
	 * Insert a published event, attach it to venue + (optional) artist terms,
	 * and seed the event_dates table with a future datetime so it counts as
	 * "upcoming".
	 *
	 * @param int      $venue_term_id  Venue term ID to attach.
	 * @param int|null $artist_term_id Artist term ID to attach (optional).
	 * @param string   $start_datetime MySQL datetime; defaults to far-future.
	 * @return int Inserted post ID.
	 */
	private function seed_upcoming_event(
		int $venue_term_id,
		?int $artist_term_id = null,
		string $start_datetime = '2099-01-01 20:00:00'
	): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Event ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		wp_set_object_terms( $post_id, array( $venue_term_id ), 'venue' );
		if ( null !== $artist_term_id ) {
			wp_set_object_terms( $post_id, array( $artist_term_id ), 'artist' );
		}

		EventDatesTable::upsert( $post_id, $start_datetime, null, 'publish' );

		return $post_id;
	}

	private function make_venue( string $name ): int {
		$term = wp_insert_term( $name . ' ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		return (int) $term['term_id'];
	}

	private function make_artist( string $name ): int {
		$term = wp_insert_term( $name . ' ' . uniqid(), 'artist' );
		$this->assertNotWPError( $term );
		return (int) $term['term_id'];
	}

	/**
	 * Regression: the unfiltered call must continue returning ALL venues
	 * with any upcoming event. The pre-existing contract must not narrow.
	 */
	public function test_unfiltered_returns_all_venues_with_upcoming_events(): void {
		$venue_a = $this->make_venue( 'Venue A' );
		$venue_b = $this->make_venue( 'Venue B' );

		$artist_one = $this->make_artist( 'Artist One' );

		// 2 events at Venue A (both tagged Artist One).
		$this->seed_upcoming_event( $venue_a, $artist_one );
		$this->seed_upcoming_event( $venue_a, $artist_one );
		// 1 event at Venue B (no artist tag).
		$this->seed_upcoming_event( $venue_b, null );

		$result = $this->abilities->executeGetUpcomingCounts(
			array( 'taxonomy' => 'venue' )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'venue', $result['taxonomy'] );
		$this->assertSame( 2, $result['total'] );

		$by_id = array();
		foreach ( $result['terms'] as $row ) {
			$by_id[ $row['term_id'] ] = $row['count'];
		}
		$this->assertArrayHasKey( $venue_a, $by_id );
		$this->assertArrayHasKey( $venue_b, $by_id );
		$this->assertSame( 2, $by_id[ $venue_a ] );
		$this->assertSame( 1, $by_id[ $venue_b ] );
	}

	/**
	 * Filtered call: scoping `taxonomy=venue` by `filter_taxonomy=artist` +
	 * `filter_term_id=N` must return only venues that co-occur with that
	 * artist on at least one upcoming event.
	 */
	public function test_filtered_returns_only_venues_cooccurring_with_filter_term(): void {
		$venue_a = $this->make_venue( 'Venue A' );
		$venue_b = $this->make_venue( 'Venue B' );
		$venue_c = $this->make_venue( 'Venue C' );

		$artist_target = $this->make_artist( 'Target Artist' );
		$artist_other  = $this->make_artist( 'Other Artist' );

		// 2 upcoming events at Venue A tagged Target Artist.
		$this->seed_upcoming_event( $venue_a, $artist_target );
		$this->seed_upcoming_event( $venue_a, $artist_target );
		// 1 upcoming event at Venue B tagged Target Artist.
		$this->seed_upcoming_event( $venue_b, $artist_target );
		// 1 upcoming event at Venue C tagged ONLY Other Artist — must be excluded.
		$this->seed_upcoming_event( $venue_c, $artist_other );

		$result = $this->abilities->executeGetUpcomingCounts(
			array(
				'taxonomy'        => 'venue',
				'filter_taxonomy' => 'artist',
				'filter_term_id'  => $artist_target,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['total'] );

		$by_id = array();
		foreach ( $result['terms'] as $row ) {
			$by_id[ $row['term_id'] ] = $row['count'];
		}
		$this->assertArrayHasKey( $venue_a, $by_id );
		$this->assertArrayHasKey( $venue_b, $by_id );
		$this->assertArrayNotHasKey( $venue_c, $by_id );
		$this->assertSame( 2, $by_id[ $venue_a ] );
		$this->assertSame( 1, $by_id[ $venue_b ] );
	}

	/**
	 * Filter for a term that has no upcoming events at all must return
	 * an empty terms array (not a WP_Error, not the unfiltered set).
	 */
	public function test_filter_with_no_cooccurrences_returns_empty_terms(): void {
		$venue_a = $this->make_venue( 'Venue A' );

		$artist_one      = $this->make_artist( 'Artist One' );
		$artist_isolated = $this->make_artist( 'Isolated Artist' );

		// All upcoming events are tagged Artist One only.
		$this->seed_upcoming_event( $venue_a, $artist_one );
		$this->seed_upcoming_event( $venue_a, $artist_one );

		$result = $this->abilities->executeGetUpcomingCounts(
			array(
				'taxonomy'        => 'venue',
				'filter_taxonomy' => 'artist',
				'filter_term_id'  => $artist_isolated,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['terms'] );
	}

	/**
	 * Documented contract: providing only one of the filter pair is misuse
	 * and returns a WP_Error('invalid_filter_pair'). This prevents callers
	 * from silently getting unfiltered results when they thought they were
	 * filtering.
	 */
	public function test_partial_filter_pair_returns_wp_error(): void {
		$venue_a = $this->make_venue( 'Venue A' );
		$this->seed_upcoming_event( $venue_a, null );

		$only_taxonomy = $this->abilities->executeGetUpcomingCounts(
			array(
				'taxonomy'        => 'venue',
				'filter_taxonomy' => 'artist',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $only_taxonomy );
		$this->assertSame( 'invalid_filter_pair', $only_taxonomy->get_error_code() );

		$only_term = $this->abilities->executeGetUpcomingCounts(
			array(
				'taxonomy'       => 'venue',
				'filter_term_id' => 42,
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $only_term );
		$this->assertSame( 'invalid_filter_pair', $only_term->get_error_code() );
	}

	/**
	 * A nonexistent filter taxonomy returns a distinct error code so
	 * callers can differentiate a wiring bug from a usage bug.
	 */
	public function test_unknown_filter_taxonomy_returns_wp_error(): void {
		$venue_a = $this->make_venue( 'Venue A' );
		$this->seed_upcoming_event( $venue_a, null );

		$result = $this->abilities->executeGetUpcomingCounts(
			array(
				'taxonomy'        => 'venue',
				'filter_taxonomy' => 'no_such_taxonomy_exists',
				'filter_term_id'  => 1,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_filter_taxonomy', $result->get_error_code() );
	}
}
