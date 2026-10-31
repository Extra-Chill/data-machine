<?php
/**
 * PreAIEventDedupGate Tests
 *
 * Covers the changed-revision pass-through added for issue #796: an
 * incoming packet that duplicate-matches an existing post but differs
 * from it (edited title or shifted start time) must proceed to AI +
 * upsert instead of being skipped as completed_no_items.
 *
 * The production trigger is an ICS recurring series where the venue
 * edits individual occurrences (Google Calendar publishes them as
 * separate VEVENTs carrying RECURRENCE-ID). The gate skip froze those
 * edits behind the stale placeholder post. The same evidence-based
 * pass-through applies to any source; presence-based source carving
 * remains limited to the existing Ticketmaster branch.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\EngineData;
use WP_UnitTestCase;
use DataMachineEvents\Core\DuplicateDetection\PreAIEventDedupGate;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;

class PreAIEventDedupGateTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The #796 production shape: the placeholder post exists, and the
	 * feed now delivers the same occurrence with an appended lineup
	 * title. Fuzzy matching still identifies the post; the gate must
	 * let the changed revision through so upsert updates it in place.
	 */
	public function test_changed_title_proceeds_to_upsert(): void {
		$venue_name = 'Starlight Gate Changed Title ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Burgundy: Extra Chill Wednesdays',
			'2026-09-09 20:00:00',
			$venue_name
		);

		$result = PreAIEventDedupGate::check(
			null,
			$this->engine(
				'Burgundy: Extra Chill Wednesdays ft. Chris Wilcox',
				$venue_name,
				'2026-09-09',
				'21:00',
				'America/New_York'
			),
			array(),
			994789
		);

		$this->assertNull(
			$result,
			'A changed title must reach AI + upsert instead of being skipped as a duplicate.'
		);
		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * The series-wide 8→9 PM shift from #796: same title, shifted
	 * start. The 2-hour window still fuzzy-matches the stale post, but
	 * the differing start time is a revision that must reach upsert.
	 */
	public function test_changed_start_time_proceeds_to_upsert(): void {
		$venue_name = 'Starlight Gate Changed Start ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Burgundy: Extra Chill Wednesdays',
			'2026-09-16 20:00:00',
			$venue_name
		);

		$result = PreAIEventDedupGate::check(
			null,
			$this->engine(
				'Burgundy: Extra Chill Wednesdays',
				$venue_name,
				'2026-09-16',
				'21:00',
				'America/New_York'
			),
			array(),
			994790
		);

		$this->assertNull(
			$result,
			'A shifted start time must reach AI + upsert instead of being skipped.'
		);
		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * A title-only edit with an unchanged start is still a revision.
	 */
	public function test_title_only_edit_proceeds_to_upsert(): void {
		$venue_name = 'Starlight Gate Title Only ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Original Show Title',
			'2026-09-23 21:00:00',
			$venue_name
		);

		$result = PreAIEventDedupGate::check(
			null,
			$this->engine(
				'Renamed Show Title',
				$venue_name,
				'2026-09-23',
				'21:00',
				'America/New_York'
			),
			array(),
			994791
		);

		$this->assertNull( $result, 'A title-only edit must reach upsert.' );
		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * A true unchanged duplicate — same normalized title, same start —
	 * must still skip so the AI step is not burned for re-delivered
	 * content.
	 */
	public function test_unchanged_duplicate_still_skips(): void {
		$venue_name = 'Starlight Gate Unchanged ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Burgundy: Extra Chill Wednesdays',
			'2026-09-30 21:00:00',
			$venue_name
		);

		$result = PreAIEventDedupGate::check(
			null,
			$this->engine(
				'Burgundy: Extra Chill Wednesdays',
				$venue_name,
				'2026-09-30',
				'21:00',
				'America/New_York'
			),
			array(),
			994792
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['skip'] );
		$this->assertStringContainsString( (string) $existing_post_id, $result['reason'] );
		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * Cosmetic title differences (case, articles, whitespace) are not
	 * revisions: normalizeBasic must treat them as the same title so
	 * byte-equal content keeps skipping.
	 */
	public function test_cosmetic_title_difference_still_skips(): void {
		$venue_name = 'Starlight Gate Cosmetic ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'The Weekly Showcase',
			'2026-10-07 21:00:00',
			$venue_name
		);

		$result = PreAIEventDedupGate::check(
			null,
			$this->engine(
				'  the   WEEKLY   showcase  ',
				$venue_name,
				'2026-10-07',
				'21:00',
				'America/New_York'
			),
			array(),
			994793
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['skip'], 'Cosmetic title differences must not burn the AI step.' );
		$this->cleanup( $term_id, $existing_post_id );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function engine( string $title, string $venue, string $start_date, string $start_time, string $timezone ): EngineData {
		return new EngineData(
			array(
				'title'         => $title,
				'venue'         => $venue,
				'startDate'     => $start_date,
				'startTime'     => $start_time,
				'venueTimezone' => $timezone,
				'flow_config'   => array(
					'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
				),
			),
			null
		);
	}

	/**
	 * Create a venue term + event post tagged with that term + event
	 * dates row. Returns [ $term_id, $post_id ] for use + cleanup.
	 *
	 * @param string $title          Event title.
	 * @param string $start_datetime MySQL datetime (e.g. '2026-09-09 20:00:00').
	 * @param string $venue_name     Venue term name.
	 * @return array{0:int,1:int}
	 */
	private function seedVenueWithEvent( string $title, string $start_datetime, string $venue_name ): array {
		$term = wp_insert_term( $venue_name, 'venue' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		wp_set_object_terms( $post_id, array( $term_id ), 'venue' );

		EventDatesTable::upsert( $post_id, $start_datetime );

		return array( $term_id, $post_id );
	}

	/**
	 * Clean up seeded fixtures.
	 *
	 * @param int $term_id Venue term ID.
	 * @param int $post_id Event post ID.
	 */
	private function cleanup( int $term_id, int $post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB
		$wpdb->delete( EventDatesTable::table_name(), array( 'post_id' => $post_id ), array( '%d' ) );

		wp_delete_post( $post_id, true );
		wp_delete_term( $term_id, 'venue' );
	}
}
