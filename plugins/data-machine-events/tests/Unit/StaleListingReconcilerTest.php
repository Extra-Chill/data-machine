<?php
/**
 * Stale listing reconciler tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\StaleListingReconciler;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class StaleListingReconcilerTest extends WP_UnitTestCase {

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
	}

	public function test_unlisted_flow_event_is_a_candidate(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 31;
		$stale    = $this->seedFlowEvent( 'Stale Solo Act', '+10 days 20:00', $venue_id, $flow_id );
		$listed   = $this->seedFlowEvent( 'Fresh Replacement Act', '+11 days 20:00', $venue_id, $flow_id );

		$candidates = $this->findCandidates(
			$flow_id,
			$venue_id,
			array(
				$this->sourceEvent( 'Fresh Replacement Act', '+11 days 20:00' ),
			)
		);

		$this->assertNotWPError( $candidates );
		$this->assertCount( 1, $candidates );
		$this->assertSame( $stale, $candidates[0]['post_id'] );
		$this->assertNotContains( $listed, array_column( $candidates, 'post_id' ) );
		$this->assertArrayHasKey( 'candidate_id', $candidates[0] );
		$this->assertNotEmpty( $candidates[0]['candidate_id'] );
	}

	public function test_same_date_fuzzy_title_match_is_not_a_candidate(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 32;
		$this->seedFlowEvent( 'The Fuzzy Kicks', '+12 days 20:00', $venue_id, $flow_id );

		$candidates = $this->findCandidates(
			$flow_id,
			$venue_id,
			array(
				// Case and padding differ but the title contract still matches.
				$this->sourceEvent( '  fuzzy kicks ', '+12 days 20:00' ),
			)
		);

		$this->assertNotWPError( $candidates );
		$this->assertSame( array(), $candidates );
	}

	public function test_same_date_title_match_ignores_slot_time_within_window(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 33;
		$this->seedFlowEvent( 'Midnight Crossing Band', '+13 days 23:00', $venue_id, $flow_id );

		// Source lists the same act just past midnight the following day.
		$candidates = $this->findCandidates(
			$flow_id,
			$venue_id,
			array(
				$this->sourceEvent( 'Midnight Crossing Band', '+14 days 00:30' ),
			)
		);

		$this->assertNotWPError( $candidates );
		$this->assertSame( array(), $candidates );
	}

	public function test_cross_date_title_match_beyond_time_window_is_a_candidate(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 47;
		$this->seedFlowEvent( 'Two Night Stand Band', '+13 days 21:00', $venue_id, $flow_id );

		// Same title on a different date more than the time window away:
		// a different show, so the published event stays a candidate.
		$candidates = $this->findCandidates(
			$flow_id,
			$venue_id,
			array(
				$this->sourceEvent( 'Two Night Stand Band', '+14 days 20:00' ),
			)
		);

		$this->assertNotWPError( $candidates );
		$this->assertCount( 1, $candidates );
	}

	public function test_event_with_different_flow_id_is_never_a_candidate(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 34;
		$stale    = $this->seedFlowEvent( 'Owned Stale Act', '+14 days 20:00', $venue_id, $flow_id );
		$this->seedFlowEvent( 'Foreign Unlisted Act', '+15 days 20:00', $venue_id, 999 );
		$this->seedFlowEvent( 'Manual Unlisted Act', '+16 days 20:00', $venue_id, 0 );

		$candidates = $this->findCandidates(
			$flow_id,
			$venue_id,
			array(
				$this->sourceEvent( 'Unrelated Source Act', '+14 days 20:00' ),
			)
		);

		$this->assertNotWPError( $candidates );
		$this->assertCount( 1, $candidates );
		$this->assertSame( $stale, $candidates[0]['post_id'] );
	}

	public function test_guard_rail_aborts_when_source_coverage_is_below_threshold(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 35;
		$this->seedFlowEvent( 'Guard Rail Act One', '+17 days 20:00', $venue_id, $flow_id );
		$this->seedFlowEvent( 'Guard Rail Act Two', '+18 days 20:00', $venue_id, $flow_id );
		$this->seedFlowEvent( 'Guard Rail Act Three', '+19 days 20:00', $venue_id, $flow_id );

		// One extracted event < ceil(3 * 0.5) = 2: presumed bad scrape.
		$candidates = $this->findCandidates(
			$flow_id,
			$venue_id,
			array(
				$this->sourceEvent( 'Guard Rail Act One', '+17 days 20:00' ),
			)
		);

		$this->assertWPError( $candidates );
		$this->assertSame( 'stale_listings_low_source_coverage', $candidates->get_error_code() );
	}

	public function test_guard_rail_aborts_on_empty_source_extraction(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 36;
		$this->seedFlowEvent( 'Empty Source Act', '+20 days 20:00', $venue_id, $flow_id );

		$candidates = $this->findCandidates( $flow_id, $venue_id, array() );

		$this->assertWPError( $candidates );
		$this->assertSame( 'stale_listings_source_empty', $candidates->get_error_code() );
	}

	public function test_no_flow_events_returns_empty_candidates_without_error(): void {
		$venue_id = $this->createVenue();

		$candidates = $this->findCandidates(
			40,
			$venue_id,
			array(
				$this->sourceEvent( 'Whatever Act', '+21 days 20:00' ),
			)
		);

		$this->assertNotWPError( $candidates );
		$this->assertSame( array(), $candidates );
	}

	public function test_apply_trashes_candidate_and_writes_audit_meta(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 41;
		$stale    = $this->seedFlowEvent( 'Retire Me Act', '+22 days 20:00', $venue_id, $flow_id );
		$kept     = $this->seedFlowEvent( 'Keep Me Act', '+23 days 20:00', $venue_id, $flow_id );

		$reconciler = new StaleListingReconciler(
			fn() => $this->flowConfig( $flow_id, $venue_id ),
			null
		);
		$source_events = array(
			$this->sourceEvent( 'Keep Me Act', '+23 days 20:00' ),
		);
		$candidates = $reconciler->findStaleCandidates( $flow_id, $venue_id, $source_events, 400 );

		$this->assertNotWPError( $candidates );
		$this->assertCount( 1, $candidates );

		$result = $reconciler->retireCandidates( $candidates );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'trashed' => 1, 'failed' => 0 ), $result );
		$this->assertSame( 'trash', get_post_status( $stale ) );
		$this->assertSame( 'publish', get_post_status( $kept ) );
		$this->assertSame( StaleListingReconciler::RETIRE_REASON_SOURCE_UNLISTED, get_post_meta( $stale, StaleListingReconciler::RETIRED_REASON_META, true ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) get_post_meta( $stale, StaleListingReconciler::RETIRED_AT_META, true ) );
	}

	public function test_venue_pinned_config_requires_fixed_venue_term(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 42;

		$reconciler = new StaleListingReconciler(
			fn() => $this->flowConfig( $flow_id, $venue_id, array( 'venue' => '' ) ),
			null
		);
		$result = $reconciler->loadVenuePinnedScraperConfig( $flow_id );

		$this->assertWPError( $result );
		$this->assertSame( 'stale_listings_venue_not_pinned', $result->get_error_code() );
	}

	public function test_venue_pinned_config_rejects_non_scraper_handler(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 43;

		$reconciler = new StaleListingReconciler(
			fn() => $this->flowConfig( $flow_id, $venue_id, array(), array( 'some_other_handler' ) ),
			null
		);
		$result = $reconciler->loadVenuePinnedScraperConfig( $flow_id );

		$this->assertWPError( $result );
		$this->assertSame( 'stale_listings_handler_not_supported', $result->get_error_code() );
	}

	public function test_venue_pinned_config_resolves_source_url_and_venue(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 44;

		$reconciler = new StaleListingReconciler(
			fn() => $this->flowConfig( $flow_id, $venue_id ),
			null
		);
		$result = $reconciler->loadVenuePinnedScraperConfig( $flow_id );

		$this->assertNotWPError( $result );
		$this->assertSame( 'https://venue.example.com/schedule', $result['source_url'] );
		$this->assertSame( $venue_id, $result['venue_term_id'] );
	}

	public function test_candidate_ids_are_stable_across_runs(): void {
		$venue_id = $this->createVenue();
		$flow_id  = 45;
		$this->seedFlowEvent( 'Stable Id Act', '+24 days 20:00', $venue_id, $flow_id );

		$source_events = array(
			$this->sourceEvent( 'Unrelated Listed Act', '+24 days 20:00' ),
		);

		$reconciler = new StaleListingReconciler(
			fn() => $this->flowConfig( $flow_id, $venue_id ),
			fn() => $source_events
		);

		$first  = $reconciler->findStaleCandidates( $flow_id, $venue_id, $source_events, 400 );
		$second = $reconciler->findStaleCandidates( $flow_id, $venue_id, $source_events, 400 );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertCount( 1, $first );
		$this->assertCount( 1, $second );
		$this->assertSame( $first[0]['candidate_id'], $second[0]['candidate_id'] );
	}

	private function findCandidates( int $flow_id, int $venue_id, array $source_events ) {
		$reconciler = new StaleListingReconciler(
			fn() => $this->flowConfig( $flow_id, $venue_id ),
			fn() => $source_events
		);

		return $reconciler->findStaleCandidates( $flow_id, $venue_id, $source_events, 400 );
	}

	private function flowConfig( int $flow_id, int $venue_id, array $handler_overrides = [], array $handler_slugs = array( 'universal_web_scraper' ) ): array {
		return array(
			$flow_id . '_step_fetch' => array(
				'flow_step_id'  => $flow_id . '_step_fetch',
				'step_type'     => 'event_import',
				'enabled'       => true,
				'handler_slugs' => $handler_slugs,
				'handler_configs' => array(
					'universal_web_scraper' => array_merge(
						array(
							'source_url' => 'https://venue.example.com/schedule',
							'venue'      => (string) $venue_id,
						),
						$handler_overrides
					),
				),
			),
		);
	}

	private function sourceEvent( string $title, string $when ): array {
		return array(
			'title'     => $title,
			'startDate' => gmdate( 'Y-m-d', strtotime( $when ) ),
			'startTime' => gmdate( 'H:i', strtotime( $when ) ),
		);
	}

	private function seedFlowEvent( string $title, string $when, int $venue_id, int $flow_id ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		update_post_meta( $post_id, '_datamachine_post_flow_id', (string) $flow_id );
		EventDatesTable::upsert( $post_id, gmdate( 'Y-m-d H:i:s', strtotime( $when ) ), null, 'publish' );

		return $post_id;
	}

	private function createVenue(): int {
		$venue = wp_insert_term( 'Stale listings venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );

		return (int) $venue['term_id'];
	}
}
