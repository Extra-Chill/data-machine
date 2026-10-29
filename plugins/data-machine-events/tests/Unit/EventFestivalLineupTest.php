<?php
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy;

require_once __DIR__ . '/EventUpsertTestCase.php';

class EventFestivalLineupTest extends EventUpsertTestCase {
	private int $venue;
	private int $festival;

	public function setUp(): void {
		parent::setUp();
		foreach ( array( 'artist', 'festival' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, Event_Post_Type::POST_TYPE );
			}
		}
		$this->venue = (int) wp_insert_term( 'The Royal American', 'venue' )['term_id'];
		update_term_meta( $this->venue, '_venue_city', 'Charleston' );
		update_term_meta( $this->venue, '_venue_state', 'SC' );
		$this->festival = self::factory()->post->create( array(
			'post_type' => Event_Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'post_title' => '6th Annual Boogieman Halloween Festival',
			'post_content' => 'Canonical festival description',
		) );
		wp_set_object_terms( $this->festival, array( $this->venue ), 'venue' );
		EventDatesTable::upsert( $this->festival, '2027-10-31 13:00:00', '2027-10-31 18:00:00' );
	}

	public function test_festival_venue_variants_resolve_without_creating_junk(): void {
		foreach ( array( ' @ ', ' at ', ' - ', ' — ' ) as $separator ) {
			$name = 'Boogieman Halloween Festival' . $separator . 'The Royal American';
			$parsed = Venue_Taxonomy::parse_festival_venue( $name, array( 'city' => 'Charleston', 'state' => 'SC' ) );
			$this->assertSame( 'The Royal American', $parsed['venue'] );
			$this->assertSame( 'Boogieman Halloween Festival', $parsed['festival'] );
			$result = Venue_Taxonomy::find_or_create_venue( $name, array( 'city' => 'Charleston', 'state' => 'SC' ) );
			$this->assertSame( $this->venue, (int) $result['term_id'] );
			$this->assertEmpty( term_exists( $name, 'venue' ) );
		}
		$this->assertSame( '', Venue_Taxonomy::parse_festival_venue( 'Festival @ Unknown Venue' )['festival'] );
	}

	public function test_artist_set_merges_lineup_without_rewriting_festival(): void {
		wp_set_object_terms( $this->festival, 'Original Artist', 'artist' );
		$before = get_post( $this->festival )->to_array();
		$dates = EventDatesTable::get( $this->festival );
		$result = $this->invoke_upsert( array(
			'title' => 'The Saint Cecilia at Boogieman Halloween Festival — The Royal American, Charleston',
			'startDate' => '2027-10-31',
			'startTime' => '19:00',
			'venue' => 'Boogieman Halloween Festival @ The Royal American',
			'venueCity' => 'Charleston',
			'venueState' => 'SC',
			'performer' => 'The Saint Cecilia',
			'description' => 'Artist set description',
			'ticketUrl' => 'https://link.seated.com/example',
		) );
		$this->assertSame( $this->festival, $result['data']['post_id'] ?? 0, wp_json_encode( $result ) );
		$this->assertSame( $before, get_post( $this->festival )->to_array() );
		$this->assertEquals( $dates, EventDatesTable::get( $this->festival ) );
		$this->assertSame( array( $this->venue ), wp_get_post_terms( $this->festival, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertEqualsCanonicalizing( array( 'Original Artist', 'The Saint Cecilia' ), wp_get_post_terms( $this->festival, 'artist', array( 'fields' => 'names' ) ) );
		$this->assertSame( array( 'Boogieman Halloween Festival' ), wp_get_post_terms( $this->festival, 'festival', array( 'fields' => 'names' ) ) );
	}

	public function test_venue_string_alone_supplies_festival_context(): void {
		$result = EventDuplicateStrategy::check( array(
			'title' => 'The Saint Cecilia',
			'context' => array( 'venue' => 'Boogieman Halloween Festival @ The Royal American', 'startDate' => '2027-10-31 19:00:00' ),
		) );
		$this->assertSame( $this->festival, $result['match']['post_id'] );
		$this->assertSame( 'festival_lineup', $result['match']['merge_mode'] );
	}

	public function test_taxonomy_only_festival_context_matches(): void {
		wp_update_post( array( 'ID' => $this->festival, 'post_title' => 'Halloween Celebration' ) );
		wp_set_object_terms( $this->festival, 'Boogieman Halloween Festival', 'festival' );
		EventDatesTable::upsert( $this->festival, '2027-10-31 13:00:00', '2027-10-31 18:00:00' );
		$result = EventDuplicateStrategy::check( array(
			'title' => 'The Saint Cecilia',
			'context' => array( 'festival' => 'Boogieman Halloween Festival', 'venue_term_id' => $this->venue, 'startDate' => '2027-10-31 19:00:00' ),
		) );
		$this->assertSame( $this->festival, $result['match']['post_id'] );
		$this->assertSame( 'festival_lineup', $result['match']['merge_mode'] );
	}

	public function test_same_location_venue_matches_festival(): void {
		$alias = (int) wp_insert_term( 'Royal American Outdoor Stage', 'venue' )['term_id'];
		foreach ( array( $alias, $this->venue ) as $term_id ) {
			update_term_meta( $term_id, '_venue_address', '970 Morrison Drive' );
			update_term_meta( $term_id, '_venue_city', 'Charleston' );
			update_term_meta( $term_id, '_venue_state', 'SC' );
		}
		$result = EventDuplicateStrategy::check( array(
			'title' => 'The Saint Cecilia at Boogieman Halloween Festival',
			'context' => array( 'venue_term_id' => $alias, 'startDate' => '2027-10-31 19:00:00' ),
		) );
		$this->assertSame( $this->festival, $result['match']['post_id'] );
		$this->assertSame( 'festival_lineup', $result['match']['merge_mode'] );
	}

	public function test_festival_context_does_not_match_another_date_or_venue(): void {
		$other_venue = (int) wp_insert_term( 'Another Venue', 'venue' )['term_id'];
		foreach ( array( array( $this->venue, '2027-11-01' ), array( $other_venue, '2027-10-31' ) ) as $case ) {
			$this->assertNull( EventDuplicateStrategy::check( array(
				'title' => 'The Saint Cecilia at Boogieman Halloween Festival',
				'context' => array( 'venue_term_id' => $case[0], 'startDate' => $case[1] . ' 19:00:00' ),
			) ) );
		}
	}

	public function test_unrelated_show_at_same_venue_and_date_is_not_festival_match(): void {
		$show = self::factory()->post->create( array(
			'post_type' => Event_Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'post_title' => 'Early Jazz Quartet',
		) );
		wp_set_object_terms( $show, array( $this->venue ), 'venue' );
		EventDatesTable::upsert( $show, '2027-10-31 17:00:00' );
		$this->assertNull( EventDuplicateStrategy::check( array(
			'title' => 'An Unrelated Band',
			'context' => array( 'venue_term_id' => $this->venue, 'venue' => 'The Royal American', 'startDate' => '2027-10-31 19:00:00' ),
		) ) );
	}

	public function test_festival_listing_reimport_is_not_a_lineup_merge(): void {
		foreach ( array( '6th Annual Boogieman Halloween Festival', 'Boogieman Halloween Festival 2027' ) as $title ) {
			$result = EventDuplicateStrategy::check( array(
				'title' => $title,
				'context' => array( 'venue_term_id' => $this->venue, 'venue' => 'The Royal American', 'startDate' => '2027-10-31 13:00:00' ),
			) );
			$this->assertNotSame( 'festival_lineup', $result['match']['merge_mode'] ?? '', $title );
		}
	}
}
