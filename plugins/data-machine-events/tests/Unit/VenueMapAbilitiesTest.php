<?php
/**
 * Venue map ability timing tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\VenueMapAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class VenueMapAbilitiesTest extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();

		EventDatesTable::create_table();
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! taxonomy_exists( 'venue_map_timing_test' ) ) {
			register_taxonomy( 'venue_map_timing_test', Event_Post_Type::POST_TYPE );
		}
	}

	private function seed_event( string $title, int $venue_id, int $filter_id, string $start, string $end ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $post_id, $start, $end, 'publish' );
		wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		wp_set_object_terms( $post_id, array( $filter_id ), 'venue_map_timing_test' );

		return $post_id;
	}

	/**
	 * Seed an event at a venue WITHOUT the filter term — used to prove
	 * term-scoped counts ignore a venue's non-term upcoming events.
	 */
	private function seed_event_at_venue( string $title, int $venue_id, string $start, string $end ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $post_id, $start, $end, 'publish' );
		wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );

		return $post_id;
	}

	public function test_venue_map_counts_and_rows_use_canonical_upcoming_predicate(): void {
		$venue  = wp_insert_term( 'Map venue ' . uniqid(), 'venue' );
		$filter = wp_insert_term( 'Map filter ' . uniqid(), 'venue_map_timing_test' );
		$this->assertNotWPError( $venue );
		$this->assertNotWPError( $filter );
		$venue_id  = (int) $venue['term_id'];
		$filter_id = (int) $filter['term_id'];
		add_term_meta( $venue_id, '_venue_coordinates', '32.7765,-79.9311', true );

		$now       = current_datetime();
		$ongoing   = $this->seed_event(
			'Ongoing map event',
			$venue_id,
			$filter_id,
			$now->modify( '-1 day' )->format( 'Y-m-d H:i:s' ),
			$now->modify( '+1 hour' )->format( 'Y-m-d H:i:s' )
		);
		$this->seed_event(
			'Ended map event',
			$venue_id,
			$filter_id,
			$now->setTime( 0, 0 )->format( 'Y-m-d H:i:s' ),
			$now->modify( '-1 minute' )->format( 'Y-m-d H:i:s' )
		);

		$result = ( new VenueMapAbilities() )->executeListVenues(
			array(
				'taxonomy'       => 'venue_map_timing_test',
				'term_id'        => $filter_id,
				'include_events' => true,
			)
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['venues'][0]['event_count'] );
		$this->assertSame( array( $ongoing ), array_column( $result['venues'][0]['upcoming_events_at_venue'], 'post_id' ) );
	}

	/**
	 * #785: term-filtered requests must exclude venues whose only events
	 * with the term are in the past, and event_count must be scoped to
	 * upcoming events carrying the term — not the venue-wide upcoming
	 * count.
	 */
	public function test_term_filter_excludes_past_only_venues_and_scopes_event_count(): void {
		$filter    = wp_insert_term( 'Scoping filter ' . uniqid(), 'venue_map_timing_test' );
		$past_only = wp_insert_term( 'Past-only venue ' . uniqid(), 'venue' );
		$mixed     = wp_insert_term( 'Mixed venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $filter );
		$this->assertNotWPError( $past_only );
		$this->assertNotWPError( $mixed );
		$filter_id    = (int) $filter['term_id'];
		$past_only_id = (int) $past_only['term_id'];
		$mixed_id     = (int) $mixed['term_id'];
		add_term_meta( $past_only_id, '_venue_coordinates', '33.1000,-80.1000', true );
		add_term_meta( $mixed_id, '_venue_coordinates', '32.7765,-79.9311', true );

		$now = current_datetime();

		// Past-only venue: its single event with the term already ended.
		$this->seed_event(
			'Ended term event',
			$past_only_id,
			$filter_id,
			$now->modify( '-3 days' )->format( 'Y-m-d H:i:s' ),
			$now->modify( '-2 days' )->format( 'Y-m-d H:i:s' )
		);

		// Mixed venue: 1 upcoming event with the term plus 5 upcoming
		// events WITHOUT the term. The term-scoped count must be 1, not 6.
		$upcoming_term_event = $this->seed_event(
			'Upcoming term event',
			$mixed_id,
			$filter_id,
			$now->modify( '+1 day' )->format( 'Y-m-d H:i:s' ),
			$now->modify( '+2 days' )->format( 'Y-m-d H:i:s' )
		);
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seed_event_at_venue(
				'Upcoming non-term event ' . $i,
				$mixed_id,
				$now->modify( '+' . ( 2 + $i ) . ' days' )->format( 'Y-m-d H:i:s' ),
				$now->modify( '+' . ( 3 + $i ) . ' days' )->format( 'Y-m-d H:i:s' )
			);
		}

		$result = ( new VenueMapAbilities() )->executeListVenues(
			array(
				'taxonomy' => 'venue_map_timing_test',
				'term_id'  => $filter_id,
			)
		);

		$this->assertSame( array( $mixed_id ), array_column( $result['venues'], 'term_id' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['venues'][0]['event_count'] );
	}

	/**
	 * #785: unfiltered requests keep today's behaviour — every venue with
	 * coordinates appears (including zero-upcoming ones), event_count is
	 * the venue-wide upcoming count, and no per-venue event list is
	 * attached.
	 */
	public function test_unfiltered_request_keeps_venue_wide_counts_and_zero_count_venues(): void {
		$with_upcoming = wp_insert_term( 'Unfiltered upcoming venue ' . uniqid(), 'venue' );
		$no_events     = wp_insert_term( 'Unfiltered empty venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $with_upcoming );
		$this->assertNotWPError( $no_events );
		$with_upcoming_id = (int) $with_upcoming['term_id'];
		$no_events_id     = (int) $no_events['term_id'];
		add_term_meta( $with_upcoming_id, '_venue_coordinates', '32.7765,-79.9311', true );
		add_term_meta( $no_events_id, '_venue_coordinates', '33.1000,-80.1000', true );

		$now = current_datetime();
		$this->seed_event_at_venue(
			'Unfiltered upcoming event',
			$with_upcoming_id,
			$now->modify( '+1 day' )->format( 'Y-m-d H:i:s' ),
			$now->modify( '+2 days' )->format( 'Y-m-d H:i:s' )
		);

		$result = ( new VenueMapAbilities() )->executeListVenues( array() );

		$by_id = array_column( $result['venues'], null, 'term_id' );
		$this->assertArrayHasKey( $with_upcoming_id, $by_id );
		$this->assertArrayHasKey( $no_events_id, $by_id );
		$this->assertSame( 1, $by_id[ $with_upcoming_id ]['event_count'] );
		$this->assertSame( 0, $by_id[ $no_events_id ]['event_count'] );
		$this->assertArrayNotHasKey( 'upcoming_events_at_venue', $by_id[ $with_upcoming_id ] );
	}
}
