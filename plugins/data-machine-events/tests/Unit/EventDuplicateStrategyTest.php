<?php
/**
 * EventDuplicateStrategy Tests
 *
 * Covers the address-aware venue resolution introduced for issue #252
 * and the 2-hour time-window guard added to the Strategy 3 term-id
 * short-circuit so early/late shows at multi-room venues are not
 * falsely merged.
 *
 * The bug: when an incoming venue string differs from the canonical
 * taxonomy term name but the address matches (e.g. "Monks Jazz" vs
 * term "Monks", "Humphreys Backstage Live" vs term "Humphreys Concerts
 * By the Bay"), the old name-only cascade in resolveVenueTerm() failed
 * to find the venue term and Strategy 3's venue-name string compare
 * then vetoed the duplicate match — producing a new dupe post.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.32.3
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;
use function DataMachineEvents\Core\datamachine_normalize_ticket_url;

class EventDuplicateStrategyTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		// Ensure the event_dates and post_identity tables exist for
		// integration tests that drive findByExactTitle end-to-end.
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		if ( class_exists( '\\DataMachine\\Core\\Database\\PostIdentityIndex\\PostIdentityIndex' ) ) {
			( new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex() )->create_table();
		}
	}

	// ---------------------------------------------------------------------
	// resolveVenueTerm() — address-first cascade
	// ---------------------------------------------------------------------

	/**
	 * Regression guard: pure name-only resolution still works when no
	 * address is supplied (the legacy path).
	 */
	public function test_name_only_resolution_still_works(): void {
		$method = new \ReflectionMethod( EventDuplicateStrategy::class, 'resolveVenueTerm' );
		$method->setAccessible( true );

		$venue_name = 'NameOnly Venue ' . uniqid();
		$term       = wp_insert_term( $venue_name, 'venue' );
		$this->assertNotWPError( $term );

		$resolved = $method->invoke( null, $venue_name, '', '' );

		$this->assertInstanceOf( \WP_Term::class, $resolved );
		$this->assertSame( $term['term_id'], $resolved->term_id );

		wp_delete_term( $term['term_id'], 'venue' );
	}

	/**
	 * Address-first resolution: when the incoming venue string does NOT
	 * match the canonical term name but the address does match, the
	 * resolver returns the term anyway. This is the core fix for #252.
	 */
	public function test_address_match_resolves_venue_when_name_does_not(): void {
		$method = new \ReflectionMethod( EventDuplicateStrategy::class, 'resolveVenueTerm' );
		$method->setAccessible( true );

		// Canonical term: "Monks"
		$term = wp_insert_term( 'Monks ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		$term_id = $term['term_id'];

		update_term_meta( $term_id, '_venue_address', '123 Main St' );
		update_term_meta( $term_id, '_venue_city', 'Athens' );

		// Incoming venue string differs from the canonical term name —
		// no name/slug/normalized-name lookup should succeed.
		$incoming_venue = 'Monks Jazz';

		// Without address: resolver should fail (name doesn't match).
		$resolved_no_addr = $method->invoke( null, $incoming_venue, '', '' );
		$this->assertNull(
			$resolved_no_addr,
			'Name-only cascade must NOT match when the incoming venue string differs from the canonical term name.'
		);

		// With matching address: resolver should return the canonical term.
		$resolved_with_addr = $method->invoke( null, $incoming_venue, '123 Main St', 'Athens' );
		$this->assertInstanceOf( \WP_Term::class, $resolved_with_addr );
		$this->assertSame(
			$term_id,
			$resolved_with_addr->term_id,
			'Address+city must resolve to the canonical venue term even when the incoming venue string differs from the term name.'
		);

		wp_delete_term( $term_id, 'venue' );
	}

	/**
	 * Public accessor on Venue_Taxonomy returns the WP_Term (not just an
	 * ID), mirroring find_venue_by_normalized_name_public so callers can
	 * use the two interchangeably.
	 */
	public function test_find_venue_by_address_public_returns_wp_term(): void {
		$term = wp_insert_term( 'Humphreys ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		$term_id = $term['term_id'];

		update_term_meta( $term_id, '_venue_address', '2241 Shelter Island Dr' );
		update_term_meta( $term_id, '_venue_city', 'San Diego' );

		$result = Venue_Taxonomy::find_venue_by_address_public( '2241 Shelter Island Dr', 'San Diego' );

		$this->assertInstanceOf( \WP_Term::class, $result );
		$this->assertSame( $term_id, $result->term_id );

		// Missing address → null.
		$missing = Venue_Taxonomy::find_venue_by_address_public( '', '' );
		$this->assertNull( $missing );

		// Non-matching address → null.
		$miss = Venue_Taxonomy::find_venue_by_address_public( 'nowhere', 'nope' );
		$this->assertNull( $miss );

		wp_delete_term( $term_id, 'venue' );
	}

	public function test_geographic_conflict_cannot_fall_through_to_name_only_duplicate_match(): void {
		$venue_name = 'Cross City Duplicate Guard ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Same Night Show',
			'2026-08-15 20:00:00',
			$venue_name
		);
		update_term_meta( $term_id, '_venue_address', '100 Main Street' );
		update_term_meta( $term_id, '_venue_city', 'Charleston' );
		update_term_meta( $term_id, '_venue_state', 'SC' );
		update_term_meta( $term_id, '_venue_country', 'US' );

		$result = EventDuplicateStrategy::check(
			array(
				'title'   => 'Same Night Show',
				'context' => array(
					'venue'     => $venue_name,
					'startDate' => '2026-08-15T20:30:00',
					'ticketUrl' => '',
					'address'   => '200 Main Street',
					'city'      => 'Atlanta',
					'state'     => 'GA',
					'country'   => 'US',
				),
			)
		);

		$this->assertNull( $result, 'Conflicting geography must block all name-only duplicate fallbacks.' );
		$this->cleanup( $term_id, $existing_post_id );
	}

	public function test_equivalent_geography_forms_still_allow_duplicate_match(): void {
		$venue_name = 'Equivalent Geography Venue ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Equivalent Geography Show',
			'2026-08-16 20:00:00',
			$venue_name
		);
		update_term_meta( $term_id, '_venue_address', '300 King Street' );
		update_term_meta( $term_id, '_venue_city', 'Charleston' );
		update_term_meta( $term_id, '_venue_state', 'SC' );
		update_term_meta( $term_id, '_venue_country', 'US' );

		$result = EventDuplicateStrategy::check(
			array(
				'title'   => 'Equivalent Geography Show',
				'context' => array(
					'venue'     => $venue_name,
					'startDate' => '2026-08-16T20:30:00',
					'ticketUrl' => '',
					'address'   => '300 King St.',
					'city'      => 'Charleston',
					'state'     => 'South Carolina',
					'country'   => 'USA',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( $existing_post_id, $result['match']['post_id'] );
		$this->cleanup( $term_id, $existing_post_id );
	}

	// ---------------------------------------------------------------------
	// findByExactTitle() — term-id short-circuit + 2-hour time-window guard
	// ---------------------------------------------------------------------

	/**
	 * Happy path: same title hash, same venue term, same date, times
	 * within the 2-hour window → term_id short-circuit fires and returns
	 * a duplicate via the new `exact_title_venue_term_id_match` reason.
	 *
	 * This is the K Flatt & Friends prod repro from #252 in test form:
	 * incoming venue string ("Monk's Jazz") differs from canonical term
	 * ("Monks") but address matches, and the candidate post is tagged
	 * with that canonical term.
	 */
	public function test_strategy3_term_id_match_returns_duplicate_within_time_window(): void {
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'K Flatt & Friends',
			'2026-05-19 21:00:00'
		);

		$venue_term = get_term( $term_id, 'venue' );
		$this->assertInstanceOf( \WP_Term::class, $venue_term );

		$method = new \ReflectionMethod( EventDuplicateStrategy::class, 'findByExactTitle' );
		$method->setAccessible( true );

		// Incoming: same title, same date, time within 2 hours (21:30).
		$result = $method->invoke(
			null,
			'K Flatt & Friends',
			"Monk's Jazz", // differs from canonical term name "Monks"
			'2026-05-19',
			'high',
			$venue_term,
			'2026-05-19T21:30:00'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( $existing_post_id, $result['match']['post_id'] );
		$this->assertStringContainsString( 'exact_title_venue_term_id_match', $result['reason'] );

		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * Time-window guard: same title hash, same venue term, same date,
	 * but start times 3+ hours apart (e.g. early/late shows at a
	 * multi-room venue) → term_id short-circuit does NOT fire, and
	 * because the incoming venue string differs from the candidate's
	 * stored venue name, the fallback name-compare also fails. Result:
	 * null (treated as a distinct event).
	 *
	 * This is the regression the orchestrator flagged: without the
	 * guard, Strategy 3's widened specificity could falsely merge two
	 * genuinely distinct shows that happen to share a title hash at the
	 * same venue complex on the same day.
	 */
	public function test_strategy3_term_id_match_skipped_outside_time_window(): void {
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Early Show', // same title hash will be used for the "late" lookup
			'2026-05-19 18:00:00' // 6pm early show
		);

		$venue_term = get_term( $term_id, 'venue' );
		$this->assertInstanceOf( \WP_Term::class, $venue_term );

		$method = new \ReflectionMethod( EventDuplicateStrategy::class, 'findByExactTitle' );
		$method->setAccessible( true );

		// Incoming: same title, same date, but late show at 22:00 —
		// 4 hours apart, outside the 2-hour window. The bypass must
		// skip; the name-compare fallback can't match because the
		// incoming venue string ("Different Venue Name") doesn't
		// normalize-match the candidate's term name. Expect null.
		$result = $method->invoke(
			null,
			'Early Show',
			'Different Venue Name', // wouldn't venuesMatch the seeded term
			'2026-05-19',
			'high',
			$venue_term,
			'2026-05-19T22:00:00' // 4 hours after the seeded 18:00
		);

		$this->assertNull(
			$result,
			'Strategy 3 term_id bypass must NOT fire when the two events are outside the 2-hour window — early/late shows at a multi-room venue are distinct events.'
		);

		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * Belt-and-suspenders: when no $startDate is passed (legacy
	 * call shape), the time-window guard is skipped and the term_id
	 * bypass still fires. Documents the back-compat behavior so future
	 * refactors don't accidentally start failing legacy callers.
	 */
	public function test_strategy3_term_id_match_fires_when_no_start_date_provided(): void {
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Legacy Caller Title',
			'2026-05-19 18:00:00'
		);

		$venue_term = get_term( $term_id, 'venue' );

		$method = new \ReflectionMethod( EventDuplicateStrategy::class, 'findByExactTitle' );
		$method->setAccessible( true );

		// Omit $startDate (defaults to '').
		$result = $method->invoke(
			null,
			'Legacy Caller Title',
			"Some Other Spelling",
			'2026-05-19',
			'high',
			$venue_term
			// no $startDate
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( $existing_post_id, $result['match']['post_id'] );

		$this->cleanup( $term_id, $existing_post_id );
	}

	// ---------------------------------------------------------------------
	// check() — date-aware end-to-end cascade tests (#423)
	// ---------------------------------------------------------------------

	/**
	 * Recurring series: same title, same venue, DIFFERENT date → NOT a
	 * duplicate. The indexed strategy scopes every query by date_only,
	 * so an event on 2026-04-22 is invisible to a lookup for 2026-05-06.
	 *
	 * This is the core behavior the legacy findExistingEvent() cascade
	 * provided and that the indexed strategy must preserve now that the
	 * legacy path is deleted. See #423, #1108.
	 */
	public function test_recurring_series_same_title_different_date_not_duplicate(): void {
		$venue_name = 'Recurring Venue ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Barn Jam',
			'2026-04-22 21:00:00',
			$venue_name
		);

		// Same title, same venue, different date.
		$result = EventDuplicateStrategy::check( array(
			'title'   => 'Barn Jam',
			'context' => array(
				'venue'     => $venue_name,
				'startDate' => '2026-05-06',
				'ticketUrl' => '',
			),
		) );

		$this->assertNull(
			$result,
			'Recurring series (same title, different date) must NOT be a duplicate.'
		);

		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * Same title + same venue + same date, start times within the 2-hour
	 * window → IS a duplicate.
	 *
	 * The seeded event starts at 21:00; the incoming event starts at
	 * 22:30 — 1.5 hours apart, well within the 2-hour tolerance.
	 */
	public function test_same_title_venue_date_within_2h_is_duplicate(): void {
		$venue_name = 'Dupe Venue ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Barn Jam',
			'2026-04-22 21:00:00',
			$venue_name
		);

		$result = EventDuplicateStrategy::check( array(
			'title'   => 'Barn Jam',
			'context' => array(
				'venue'     => $venue_name,
				'startDate' => '2026-04-22T22:30:00',
				'ticketUrl' => '',
			),
		) );

		$this->assertIsArray( $result );
		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( $existing_post_id, $result['match']['post_id'] );

		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * Exact ticket-URL match on the same date → IS a duplicate.
	 * Same ticket URL on a different date → NOT a duplicate.
	 *
	 * Ticket URLs are stable platform identifiers; same URL + same date
	 * is definitively the same event. A different date means it's a
	 * different occurrence (e.g. a multi-night run using the same
	 * ticketing link).
	 */
	public function test_exact_ticket_url_match_dedups_same_date_only(): void {
		$venue_name = 'Ticket Venue ' . uniqid();
		$ticket_url = 'https://www.ticketmaster.com/event/12345ABCD';

		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Concert Night',
			'2026-04-22 20:00:00',
			$venue_name,
			$ticket_url
		);

		// Same ticket URL + same date → duplicate.
		$result_same = EventDuplicateStrategy::check( array(
			'title'   => 'Concert Night',
			'context' => array(
				'venue'     => $venue_name,
				'startDate' => '2026-04-22',
				'ticketUrl' => $ticket_url,
			),
		) );

		$this->assertIsArray( $result_same );
		$this->assertSame( 'duplicate', $result_same['verdict'] );
		$this->assertSame( $existing_post_id, $result_same['match']['post_id'] );

		// Same ticket URL + DIFFERENT date → NOT a duplicate.
		$result_diff = EventDuplicateStrategy::check( array(
			'title'   => 'Concert Night',
			'context' => array(
				'venue'     => $venue_name,
				'startDate' => '2026-04-23',
				'ticketUrl' => $ticket_url,
			),
		) );

		$this->assertNull(
			$result_diff,
			'Same ticket URL on a different date must NOT be a duplicate.'
		);

		$this->cleanup( $term_id, $existing_post_id );
	}

	/**
	 * Venue + date + fuzzy title match → IS a duplicate.
	 *
	 * The incoming title is a close variant of the stored title; the
	 * SimilarityEngine's titlesMatch() should accept it. Both share the
	 * same venue and date.
	 */
	public function test_venue_date_fuzzy_title_dedups(): void {
		$venue_name = 'Fuzzy Venue ' . uniqid();
		[ $term_id, $existing_post_id ] = $this->seedVenueWithEvent(
			'Phish at the Pour House',
			'2026-07-04 20:00:00',
			$venue_name
		);

		// Incoming title is a fuzzy variant — same core identity.
		$result = EventDuplicateStrategy::check( array(
			'title'   => 'Phish at The Pour House',
			'context' => array(
				'venue'     => $venue_name,
				'startDate' => '2026-07-04T21:00:00',
				'ticketUrl' => '',
			),
		) );

		$this->assertIsArray( $result );
		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( $existing_post_id, $result['match']['post_id'] );

		$this->cleanup( $term_id, $existing_post_id );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Create a venue term (with address meta) + an event post tagged
	 * with that term + identity index row + event dates row. Returns
	 * [ $term_id, $post_id ] for use + cleanup.
	 *
	 * @param string $title          Event title.
	 * @param string $start_datetime MySQL datetime (e.g. '2026-05-19 21:00:00').
	 * @param string $venue_name     Optional venue term name (defaults to 'Monks <uniqid>').
	 * @param string $ticket_url     Optional ticket URL to seed in postmeta + index.
	 * @return array{0:int,1:int}
	 */
	private function seedVenueWithEvent( string $title, string $start_datetime, string $venue_name = '', string $ticket_url = '' ): array {
		if ( '' === $venue_name ) {
			$venue_name = 'Monks ' . uniqid();
		}
		$term = wp_insert_term( $venue_name, 'venue' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];

		update_term_meta( $term_id, '_venue_address', '123 Main St' );
		update_term_meta( $term_id, '_venue_city', 'Athens' );

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		wp_set_object_terms( $post_id, array( $term_id ), 'venue' );

		// Seed the event-dates row so the time-window guard has a
		// candidate datetime to compare against.
		EventDatesTable::upsert( $post_id, $start_datetime );

		// Seed ticket URL in postmeta (normalized, matching production
		// event-dates-sync behavior) so canonical-identity comparison
		// works in the ticket-URL strategy.
		$normalized_ticket_url = '';
		if ( '' !== $ticket_url ) {
			$normalized_ticket_url = datamachine_normalize_ticket_url( $ticket_url );
			update_post_meta( $post_id, EVENT_TICKET_URL_META_KEY, $normalized_ticket_url );
		}

		// Seed the identity-index row so findByExactTitle's
		// find_by_date_and_title_hash() lookup finds this post.
		$index      = new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex();
		$date_only  = substr( $start_datetime, 0, 10 );
		$title_hash = EventDuplicateStrategy::computeTitleHash( $title );
		$index_fields = array(
			'post_type'     => 'data_machine_events',
			'event_date'    => $date_only,
			'venue_term_id' => $term_id,
			'title_hash'    => $title_hash,
		);
		if ( '' !== $normalized_ticket_url ) {
			$index_fields['ticket_url'] = $normalized_ticket_url;
		}
		$index->upsert( $post_id, $index_fields );

		return array( $term_id, $post_id );
	}

	/**
	 * Clean up seeded fixtures.
	 *
	 * @param int $term_id Venue term ID.
	 * @param int $post_id Event post ID.
	 */
	private function cleanup( int $term_id, int $post_id ): void {
		if ( class_exists( '\\DataMachine\\Core\\Database\\PostIdentityIndex\\PostIdentityIndex' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'datamachine_post_identity';
			// phpcs:ignore WordPress.DB
			$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB
		$wpdb->delete( EventDatesTable::table_name(), array( 'post_id' => $post_id ), array( '%d' ) );

		wp_delete_post( $post_id, true );
		wp_delete_term( $term_id, 'venue' );
	}
}
