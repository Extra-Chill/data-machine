<?php
/**
 * Event Details prose section tests (issue #830).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Blocks\EventDetails\ProseSections;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class EventProseSectionsTest extends WP_UnitTestCase {

	/**
	 * Queries captured via the wpdb `query` filter during the current count window.
	 *
	 * @var array
	 */
	private $captured_queries = array();

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		// Production registers `artist` network-wide (shared with the artist
		// platform); standalone installs degrade gracefully without it. Register
		// the shared shape here so the tour/artist-city sections are exercised.
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', Event_Post_Type::POST_TYPE );
		}
	}

	public function tearDown(): void {
		remove_filter( 'query', array( $this, 'capture_query' ), 9999 );
		$this->captured_queries = array();

		$GLOBALS['post'] = null;
		parent::tearDown();
	}

	private function seed_venue( string $name, string $city = '', string $state = '' ): int {
		$term_id = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'venue',
				'name'     => $name,
			)
		);

		$meta = array();
		if ( '' !== $city ) {
			$meta['city'] = $city;
		}
		if ( '' !== $state ) {
			$meta['state'] = $state;
		}
		// Seed meta directly: VenueProfileMutations (behind update_venue_meta)
		// refuses to run inside an open SQL transaction, which every
		// WP_UnitTestCase test is, so it would silently write nothing.
		foreach ( $meta as $field => $value ) {
			update_term_meta( $term_id, Venue_Taxonomy::$meta_fields[ $field ], $value );
		}

		return $term_id;
	}

	private function seed_artist( string $name ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'artist',
				'name'     => $name,
			)
		);
	}

	private function seed_event( string $title, string $start, int $venue_id = 0, int $artist_id = 0 ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $post_id, $start, null, 'publish' );

		if ( $venue_id > 0 ) {
			wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		}
		if ( $artist_id > 0 ) {
			wp_set_object_terms( $post_id, array( $artist_id ), 'artist' );
		}

		return $post_id;
	}

	/**
	 * Render the Event Details template exactly as WordPress does.
	 *
	 * @param int   $post_id    Event post the template renders against.
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	private function render_block_template( int $post_id, array $attributes = array() ): string {
		$GLOBALS['post'] = get_post( $post_id );

		$attributes = array_merge(
			array(
				'startDate' => '2027-03-01',
				'startTime' => '20:00',
			),
			$attributes
		);

		ob_start();
		include DATA_MACHINE_EVENTS_PATH . 'inc/Blocks/EventDetails/render.php';

		return (string) ob_get_clean();
	}

	private function start_query_capture(): void {
		$this->captured_queries = array();
		add_filter( 'query', array( $this, 'capture_query' ), 9999 );
	}

	private function stop_query_capture(): void {
		remove_filter( 'query', array( $this, 'capture_query' ), 9999 );
	}

	public function capture_query( $sql ) {
		$this->captured_queries[] = (string) $sql;

		return $sql;
	}

	private function captured_query_count(): int {
		return count( $this->captured_queries );
	}

	public function test_tour_section_renders_substantial_paragraph_when_data_exists(): void {
		$venue_id  = $this->seed_venue( 'The Royal American' );
		$artist_id = $this->seed_artist( 'Phish' );
		$post_id   = $this->seed_event( 'Phish at The Royal American', '2027-07-21 19:00:00', $venue_id, $artist_id );
		$tour_a    = $this->seed_event( 'Phish in Columbia', '2027-07-23 19:00:00', $venue_id, $artist_id );
		$tour_b    = $this->seed_event( 'Phish in Atlanta', '2027-07-25 19:00:00', $venue_id, $artist_id );

		$html = ProseSections::render( $post_id );

		$this->assertStringContainsString( 'event-prose--tour', $html );
		$this->assertStringContainsString( '<h2 class="event-prose-heading">', $html );
		$this->assertStringContainsString( '<p>', $html );

		$this->assertStringContainsString( get_permalink( $tour_a ), $html );
		$this->assertStringContainsString( get_permalink( $tour_b ), $html );
		$this->assertStringContainsString( 'See all upcoming Phish shows.', $html );

		// The practical ad-insertion unit from #830: a <p> with >100 chars of text.
		$paragraphs = array();
		preg_match_all( '/<p>(.*?)<\/p>/s', $html, $paragraphs );
		$substantial = array_filter(
			$paragraphs[1],
			static function ( $p ) {
				return strlen( wp_strip_all_tags( $p ) ) > 100;
			}
		);
		$this->assertNotEmpty( $substantial, 'Prose sections must produce substantial <p> paragraphs.' );
	}

	public function test_prose_html_appears_exactly_once_inside_block_output(): void {
		$venue_id  = $this->seed_venue( 'The Royal American', 'Charleston', 'SC' );
		$artist_id = $this->seed_artist( 'Coast' );
		$post_id   = $this->seed_event( 'Coast at The Royal American', '2027-04-10 21:00:00', $venue_id, $artist_id );
		$this->seed_event( 'Coast returns to The Royal American', '2027-04-24 21:00:00', $venue_id, $artist_id );

		$html  = $this->render_block_template( $post_id );
		$prose = ProseSections::render( $post_id );

		$this->assertNotSame( '', $prose );
		$this->assertSame( 1, substr_count( $html, $prose ), 'The prose HTML must appear exactly once in block output.' );
		$this->assertStringContainsString( '<div class="event-details-prose">', $html );
	}

	public function test_each_section_absent_when_its_data_is_missing(): void {
		// Current event has a venue but no other venue events, no city meta,
		// no artist term at all.
		$venue_id = $this->seed_venue( 'Lonely Room' );
		$post_id  = $this->seed_event( 'Solo show at Lonely Room', '2027-08-01 20:00:00', $venue_id );

		$this->assertSame( '', ProseSections::render( $post_id ) );
	}

	public function test_no_empty_headings_or_orphan_wrappers_when_all_slices_empty(): void {
		$venue_id  = $this->seed_venue( 'Quiet Hall' );
		$artist_id = $this->seed_artist( 'Sparse Act' );
		$post_id   = $this->seed_event( 'Sparse Act at Quiet Hall', '2027-08-05 20:00:00', $venue_id, $artist_id );

		// No other events anywhere: tour, venue-past, and
		// artist-city slices are all empty. Nothing may render at all —
		// no wrapper div, no heading, no filler text.
		$html = $this->render_block_template( $post_id );

		$this->assertStringNotContainsString( 'event-details-prose', $html );
		$this->assertStringNotContainsString( 'event-prose-section', $html );
		$this->assertStringNotContainsString( 'event-prose-heading', $html );
		$this->assertStringNotContainsString( 'No related shows found', $html );
	}

	public function test_venue_context_renders_and_upcoming_venue_shows_are_not_duplicated(): void {
		$venue_id = $this->seed_venue( 'Charleston Pour House', 'Charleston', 'SC' );
		$post_id  = $this->seed_event( 'Tonight at the Pour House', '2027-09-01 20:00:00', $venue_id );

		$other_upcoming = $this->seed_event( 'Another band at the Pour House', '2027-09-08 20:00:00', $venue_id );
		$past_show      = $this->seed_event( 'A show that already happened', '2026-01-15 20:00:00', $venue_id );

		$html = ProseSections::render( $post_id );

		// Upcoming shows at the venue are the theme's "More at {venue}" cards;
		// the prose must not repeat that list.
		$this->assertStringNotContainsString( 'event-prose--venue-upcoming', $html );
		$this->assertStringNotContainsString( get_permalink( $other_upcoming ), $html );
		$this->assertStringContainsString( 'event-prose--venue-context', $html );
		$this->assertStringContainsString( 'is located in Charleston, SC.', $html );
		$this->assertStringContainsString( 'Recent shows at Charleston Pour House include', $html );
		$this->assertStringContainsString( get_permalink( $past_show ), $html );
		$this->assertStringNotContainsString( 'event-prose--tour', $html, 'No artist term means no tour section.' );
		$this->assertStringNotContainsString( 'event-prose--artist-city', $html, 'No artist term means no artist-city section.' );
	}

	public function test_artist_city_section_matches_most_recent_past_show_in_city(): void {
		$home_city_venue  = $this->seed_venue( 'Charleston Music Hall', 'Charleston', 'SC' );
		$other_city_venue = $this->seed_venue( 'Atlanta Auditorium', 'Atlanta', 'GA' );
		$artist_id        = $this->seed_artist( 'The Gaskets' );

		$post_id = $this->seed_event( 'The Gaskets at Charleston Music Hall', '2027-10-01 20:00:00', $home_city_venue, $artist_id );

		// Two past shows: an older one in the same city, a newer one elsewhere.
		$past_in_city    = $this->seed_event( 'The Gaskets past Charleston show', '2026-03-01 20:00:00', $home_city_venue, $artist_id );
		$past_other_city = $this->seed_event( 'The Gaskets past Atlanta show', '2026-08-01 20:00:00', $other_city_venue, $artist_id );

		$html = ProseSections::render( $post_id );

		$this->assertStringContainsString( 'event-prose--artist-city', $html );
		$this->assertStringContainsString( 'has a history in Charleston', $html );
		// The most recent in-city show is the March one, not the newer Atlanta one.
		$this->assertStringContainsString( get_permalink( $past_in_city ), $html );
		$this->assertStringNotContainsString( get_permalink( $past_other_city ), $html );
	}

	public function test_warm_cache_hit_stays_within_query_budget_and_output_is_stable(): void {
		$venue_id  = $this->seed_venue( 'The Granada', 'Lawrence', 'KS' );
		$artist_id = $this->seed_artist( 'Dead Cover Band' );
		$post_id   = $this->seed_event( 'Dead Cover Band at The Granada', '2027-11-01 20:00:00', $venue_id, $artist_id );
		$this->seed_event( 'Dead Cover Band tomorrow night', '2027-11-02 20:00:00', $venue_id, $artist_id );

		// Cold render: builds the payload. Budget is 3 bounded section queries
		// plus per-row date/term point lookups; allow generous headroom for
		// cached-post hydration but keep the ceiling explicit.
		$this->start_query_capture();
		$first = ProseSections::render( $post_id );
		$cold  = $this->captured_query_count();
		$this->stop_query_capture();

		// Warm render: payload comes from the generation-keyed transient;
		// at most a couple of option-table reads, zero section queries.
		$this->start_query_capture();
		$second = ProseSections::render( $post_id );
		$warm   = $this->captured_query_count();
		$this->stop_query_capture();

		$this->assertLessThanOrEqual( 30, $cold, 'Cold render exceeded the documented query budget.' );
		$this->assertLessThanOrEqual( 5, $warm, 'Warm render must be served from the transient cache.' );
		$this->assertSame( $first, $second, 'Cached and uncached renders must be identical.' );
	}

	public function test_non_event_post_renders_nothing(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( '', ProseSections::render( $post_id ) );
	}
}
