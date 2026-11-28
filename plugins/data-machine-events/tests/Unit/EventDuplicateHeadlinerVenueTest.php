<?php
/**
 * Headliner + venue identity duplicate detection (#3965).
 *
 * A multi-venue listings import (mosplaceradio.com/bandschedules) titled
 * shows "Headliner with Support at Venue" and named venues loosely
 * ("Iron Horse Music Hall" for "The Iron Horse"), while the venues' own
 * feeds add tour names ("Hunxho - 2Night Only Tour"). None of the existing
 * strategies saw those as one show, so 341 events were created and 0
 * matched. These tests pin the headliner/venue-identity strategy and the
 * negatives that keep it conservative.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventDuplicateHeadlinerVenueTest extends WP_UnitTestCase {

	/** @var int[] */
	private array $existing_terms = array();

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->existing_terms = $this->venueTermIds();
	}

	public function tearDown(): void {
		foreach ( array_diff( $this->venueTermIds(), $this->existing_terms ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// Positives
	// ---------------------------------------------------------------------

	/**
	 * Hunxho / Big Night Live: same venue term, tour suffix on the stored
	 * title, same day. Doors vs show times beyond the one-hour short-title
	 * window still match as the single cross-source candidate.
	 */
	public function test_same_venue_tour_suffix_same_day_is_duplicate(): void {
		$venue   = $this->venue( 'Big Night Live', 'Boston', 'MA' );
		$post_id = $this->event( 'Hunxho - 2Night Only Tour', '2031-12-04 18:00:00', $venue, 'ticketmaster' );

		$same_time = $this->check( 'Hunxho', '2031-12-04 18:00', $venue, 'Big Night Live' );
		$this->assertSame( $post_id, (int) $same_time['match']['post_id'] );

		$doors = $this->check( 'Hunxho', '2031-12-04 19:30', $venue, 'Big Night Live' );
		$this->assertIsArray( $doors );
		$this->assertSame( $post_id, (int) $doors['match']['post_id'] );
		$this->assertStringContainsString( 'headliner_venue_identity_cross_source', $doors['reason'] );
	}

	/**
	 * Whitechapel / Palladium: full support list plus " at Venue" against the
	 * venue feed's shorter opener list, 3.5 hours apart, different sources.
	 */
	public function test_support_list_and_venue_suffix_match_short_listing_cross_source(): void {
		$venue   = $this->venue( 'Palladium', 'Worcester', 'MA' );
		$post_id = $this->event( 'Whitechapel w/ Chelsea Grin', '2031-11-21 15:30:00', $venue, 'ticketmaster' );

		$result = $this->check(
			'Whitechapel with The Acacia Strain, Chelsea Grin, Suicide Silence, Emmure, AngelMaker & Nailed Shut at Palladium',
			'2031-11-21 19:00',
			$venue,
			'Palladium'
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, (int) $result['match']['post_id'] );
	}

	/**
	 * Iron Horse: the listing venue resolved to a name-variant term in the
	 * same city ("Iron Horse Music Hall" vs "The Iron Horse").
	 */
	public function test_name_variant_venue_in_same_city_is_duplicate(): void {
		$canonical = $this->venue( 'The Iron Horse', 'Northampton', 'MA', '20 Center St' );
		$variant   = $this->venue( 'Iron Horse Music Hall', 'Northampton', 'MA' );
		$post_id   = $this->event( 'Fantastic Cat at The Iron Horse (2 Shows)', '2031-12-29 19:00:00', $canonical, 'universal_web_scraper' );

		$result = $this->check( 'Fantastic Cat at Iron Horse Music Hall', '2031-12-29 19:00', $variant, 'Iron Horse Music Hall' );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, (int) $result['match']['post_id'] );
		$this->assertStringContainsString( 'headliner_venue_identity', $result['reason'] );
	}

	/**
	 * Béla Fleck: diacritics, and a "Room, Venue" listing name against the
	 * venue's own term.
	 */
	public function test_accented_headliner_at_room_qualified_venue_is_duplicate(): void {
		$canonical = $this->venue( 'Capitol Center for the Arts', 'Concord', 'NH', '44 South Main Street' );
		$room      = $this->venue( 'John F. Swope Theater, Capitol Center for the Arts', 'Concord', 'NH' );
		$post_id   = $this->event( 'Bela Fleck', '2031-12-05 20:00:00', $canonical, 'ticketmaster' );

		$result = $this->check( 'Béla Fleck', '2031-12-05 20:00', $room, 'John F. Swope Theater, Capitol Center for the Arts' );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, (int) $result['match']['post_id'] );
	}

	/**
	 * Street Dogs: "presented by" venue variant and a ":" tour subtitle.
	 */
	public function test_sponsor_variant_venue_and_subtitle_is_duplicate(): void {
		$canonical = $this->venue( 'Paradise Rock Club presented by Citizens', 'Boston', 'MA', '967 Commonwealth Avenue' );
		$variant   = $this->venue( 'Paradise Rock Club', 'Boston', 'MA' );
		$post_id   = $this->event( 'Street Dogs: Wreck The Halls', '2031-12-18 18:30:00', $canonical, 'ticketmaster' );

		$result = $this->check( 'Street Dogs with Gang Green & Liberty And Justice at Paradise Rock Club', '2031-12-18 18:30', $variant, 'Paradise Rock Club' );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, (int) $result['match']['post_id'] );
	}

	// ---------------------------------------------------------------------
	// Negatives
	// ---------------------------------------------------------------------

	public function test_different_headliners_at_different_venues_same_day_are_distinct(): void {
		$venue_a = $this->venue( 'Royale', 'Boston', 'MA' );
		$venue_b = $this->venue( 'Roadrunner', 'Boston', 'MA' );
		$this->event( 'Street Dogs at Royale', '2031-12-10 19:00:00', $venue_a, 'ticketmaster' );

		$this->assertNull( $this->check( 'Bonobo at Roadrunner', '2031-12-10 19:00', $venue_b, 'Roadrunner' ) );
	}

	/**
	 * The same act listed at two genuinely different venues in one city on
	 * one night is not merged (source disagreement, not a duplicate).
	 */
	public function test_same_headliner_at_different_venues_same_city_is_distinct(): void {
		$venue_a = $this->venue( 'Roadrunner', 'Boston', 'MA' );
		$venue_b = $this->venue( 'Royale', 'Boston', 'MA' );
		$this->event( 'Shakey Graves w/ Jobi Riccio', '2031-12-11 20:00:00', $venue_a, 'ticketmaster' );

		$this->assertNull( $this->check( 'Shakey Graves at Royale', '2031-12-11 19:00', $venue_b, 'Royale' ) );
	}

	public function test_generic_title_at_two_venues_is_distinct(): void {
		$venue_x = $this->venue( 'Lizard Lounge', 'Cambridge', 'MA' );
		$venue_y = $this->venue( 'Plough and Stars', 'Cambridge', 'MA' );
		$this->event( 'Open Mic at Lizard Lounge', '2031-11-28 20:00:00', $venue_x, 'universal_web_scraper' );

		$this->assertNull( $this->check( 'Open Mic at Plough and Stars', '2031-11-28 20:00', $venue_y, 'Plough and Stars', 'ticketmaster' ) );
	}

	public function test_same_headliner_same_day_different_city_is_distinct(): void {
		$northampton = $this->venue( 'The Iron Horse', 'Northampton', 'MA', '20 Center St' );
		$springfield = $this->venue( 'Iron Horse Music Hall', 'Springfield', 'MA' );
		$this->event( 'Fantastic Cat at The Iron Horse', '2031-12-30 19:00:00', $northampton, 'universal_web_scraper' );

		$this->assertNull( $this->check( 'Fantastic Cat at Iron Horse Music Hall', '2031-12-30 19:00', $springfield, 'Iron Horse Music Hall' ) );
	}

	/**
	 * A one-word headliner at a name-variant venue needs the start time;
	 * the cross-source fallback is reserved for the same venue term.
	 */
	public function test_short_headliner_at_variant_venue_needs_start_time(): void {
		$canonical = $this->venue( 'The Iron Horse', 'Northampton', 'MA', '20 Center St' );
		$variant   = $this->venue( 'Iron Horse Music Hall', 'Northampton', 'MA' );
		$post_id   = $this->event( 'LuxDeluxe at The Iron Horse', '2031-11-27 20:00:00', $canonical, 'ticketmaster' );

		$this->assertNull( $this->check( 'Luxdeluxe at Iron Horse Music Hall', '2031-11-27 22:30', $variant, 'Iron Horse Music Hall' ) );

		$result = $this->check( 'Luxdeluxe at Iron Horse Music Hall', '2031-11-27 20:00', $variant, 'Iron Horse Music Hall' );
		$this->assertSame( $post_id, (int) $result['match']['post_id'] );
	}

	// ---------------------------------------------------------------------
	// headlinerKey()
	// ---------------------------------------------------------------------

	public function test_headliner_key_reduces_listing_titles_to_the_headliner(): void {
		$this->assertSame( 'hunxho', EventDuplicateStrategy::headlinerKey( 'Hunxho - 2Night Only Tour' ) );
		$this->assertSame( 'street dogs', EventDuplicateStrategy::headlinerKey( 'Street Dogs: Wreck The Halls' ) );
		$this->assertSame( 'street dogs', EventDuplicateStrategy::headlinerKey( 'Street Dogs with Gang Green & Liberty And Justice at Paradise Rock Club', array( 'Paradise Rock Club' ) ) );
		$this->assertSame( 'fantastic cat', EventDuplicateStrategy::headlinerKey( 'Fantastic Cat at The Iron Horse (2 Shows)', array( 'The Iron Horse' ) ) );
		$this->assertSame( 'bela fleck', EventDuplicateStrategy::headlinerKey( 'Béla Fleck' ) );
		$this->assertSame( 'ronnie earl', EventDuplicateStrategy::headlinerKey( 'Ronnie Earl and The Broadcasters' ) );
		$this->assertSame( 'john nemeth', EventDuplicateStrategy::headlinerKey( "John Németh at Jimmy's Jazz & Blues Club", array( "Jimmy's Jazz and Blues Club" ) ) );
	}

	public function test_headliner_key_keeps_set_subtitles_and_evening_with(): void {
		$this->assertSame( 'cyrille aimee late show', EventDuplicateStrategy::headlinerKey( 'Cyrille Aimee - Late Show' ) );
		$this->assertSame( 'evening with bob', EventDuplicateStrategy::headlinerKey( 'An Evening with Bob' ) );
		$this->assertSame( 'night at the museum', EventDuplicateStrategy::headlinerKey( 'Night at the Museum', array( 'Museum Bar' ) ) );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/** @return int[] */
	private function venueTermIds(): array {
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
		$existing = get_term_by( 'name', $name, 'venue' );
		$args     = $existing ? array( 'slug' => sanitize_title( $name . '-' . $city . '-' . uniqid() ) ) : array();
		$term     = wp_insert_term( $name, 'venue', $args );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, '_venue_city', $city );
		update_term_meta( $term_id, '_venue_state', $state );
		update_term_meta( $term_id, '_venue_country', 'US' );
		if ( '' !== $address ) {
			update_term_meta( $term_id, '_venue_address', $address );
		}

		return $term_id;
	}

	private function event( string $title, string $start, int $venue_id, string $source ): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		EventDatesTable::upsert( $post_id, $start );
		update_post_meta( $post_id, '_datamachine_event_source', $source );

		return (int) $post_id;
	}

	private function check( string $title, string $start, int $venue_id, string $venue_name, string $source = 'universal_web_scraper' ): ?array {
		return EventDuplicateStrategy::check(
			array(
				'title'   => $title,
				'context' => array(
					'venue'         => $venue_name,
					'startDate'     => $start,
					'ticketUrl'     => '',
					'city'          => (string) get_term_meta( $venue_id, '_venue_city', true ),
					'state'         => (string) get_term_meta( $venue_id, '_venue_state', true ),
					'venue_term_id' => $venue_id,
					'source'        => $source,
				),
			)
		);
	}
}
