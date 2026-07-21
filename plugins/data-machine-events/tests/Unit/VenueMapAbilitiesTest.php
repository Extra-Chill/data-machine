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
}
