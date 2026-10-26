<?php
/**
 * EventUpsert fallback dedupe tests.
 *
 * Covers the title-contract + venue + start_datetime collapse rule used by
 * EventUpsert::findExistingEventViaAbility() once source-identity matching
 * misses. Split out of EventUpsertTest to keep each file under the
 * structural line-count threshold.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

require_once __DIR__ . '/EventUpsertTestCase.php';

class EventUpsertFallbackDedupeTest extends EventUpsertTestCase {

	public function test_barn_jam_upsert_updates_existing_event_without_new_venue(): void {
		$first = $this->invoke_upsert( array(
			'title'     => 'Barn Jam',
			'venue'     => 'Awendaw Green',
			'startDate' => '2026-10-28',
			'startTime' => '17:50',
		) );
		$this->assertTrue( $first['success'] ?? false, wp_json_encode( $first ) );
		$post_id = $first['data']['post_id'];
		$venues  = wp_get_post_terms( $post_id, 'venue' );
		$this->assertCount( 1, $venues );
		update_term_meta( $venues[0]->term_id, '_venue_address', '4853 Highway 17, Awendaw, SC 29429' );
		update_term_meta( $venues[0]->term_id, '_venue_city', 'Awendaw' );
		$before = wp_count_terms( array(
			'taxonomy'   => 'venue',
			'hide_empty' => false,
		) );
		$second = $this->invoke_upsert( array(
			'title'        => 'Barn Jam at Sewee Outpost',
			'venue'        => 'Sewee Outpost',
			'startDate'    => '2026-10-28',
			'startTime'    => '17:50',
			'venueAddress' => '4853 Highway 17',
			'venueCity'    => 'Awendaw',
		) );
		$this->assertTrue( $second['success'] ?? false, wp_json_encode( $second ) );
		$this->assertSame( $post_id, $second['data']['post_id'] );
		$this->assertContains( $second['data']['action'], array( 'updated', 'no_change' ) );
		$this->assertSame( $before, wp_count_terms( array(
			'taxonomy'   => 'venue',
			'hide_empty' => false,
		) ) );
		$this->assertSame( array( $venues[0]->term_id ), wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
	}

	public function test_fallback_reuses_legacy_event_missing_source_identity(): void {
		$title    = 'Legacy Dinghy Band ' . uniqid();
		$venue    = 'Fallback Venue A ' . uniqid();
		$occasion = array(
			'title'     => $title,
			'venue'     => $venue,
			'startDate' => '2026-10-21',
			'startTime' => '18:00',
		);

		$first  = $this->invoke_upsert( $occasion );
		$second = $this->invoke_upsert(
			array_merge(
				$occasion,
				array(
					'source_type'     => 'universal_web_scraper',
					'item_identifier' => 'scraper-' . uniqid(),
				)
			)
		);

		$this->assertTrue( $first['success'] ?? false, wp_json_encode( $first ) );
		$this->assertTrue( $second['success'] ?? false, wp_json_encode( $second ) );
		$this->assertSame( $first['data']['post_id'], $second['data']['post_id'] );
		$this->assertSame( 'created', $first['data']['action'] );
		$this->assertContains( $second['data']['action'], array( 'updated', 'no_change' ) );
		$this->assertSame( 1, $this->countEventsWithTitle( $title ) );
	}

	public function test_fallback_collapses_same_flow_with_differing_source_identities(): void {
		$title    = 'Dual Catalog Headliner ' . uniqid();
		$venue    = 'Fallback Venue B ' . uniqid();
		$occasion = array(
			'title'       => $title,
			'venue'       => $venue,
			'startDate'   => '2026-09-23',
			'startTime'   => '19:30',
			'source_type' => 'ticketmaster',
		);

		$first  = $this->invoke_upsert( array_merge( $occasion, array( 'item_identifier' => 'vvG1HZ-' . uniqid() ) ) );
		$second = $this->invoke_upsert( array_merge( $occasion, array( 'item_identifier' => 'Z7r9jZ-' . uniqid() ) ) );

		$this->assertTrue( $first['success'] ?? false, wp_json_encode( $first ) );
		$this->assertTrue( $second['success'] ?? false, wp_json_encode( $second ) );
		$this->assertSame( $first['data']['post_id'], $second['data']['post_id'] );
		$this->assertSame( 1, $this->countEventsWithTitle( $title ) );
	}

	public function test_fallback_collapses_cross_flow_identity_miss(): void {
		$title    = 'Cross Flow Headliner ' . uniqid();
		$venue    = 'Fallback Venue C ' . uniqid();
		$occasion = array(
			'title'     => $title,
			'venue'     => $venue,
			'startDate' => '2026-11-11',
			'startTime' => '19:00',
		);

		$first  = $this->invoke_upsert(
			array_merge( $occasion, array(
				'source_type'     => 'ticketmaster',
				'item_identifier' => 'tm-' . uniqid(),
			) )
		);
		$second = $this->invoke_upsert(
			array_merge( $occasion, array(
				'source_type'     => 'ticketweb',
				'item_identifier' => 'tw-' . uniqid(),
			) )
		);

		$this->assertTrue( $first['success'] ?? false, wp_json_encode( $first ) );
		$this->assertTrue( $second['success'] ?? false, wp_json_encode( $second ) );
		$this->assertSame( $first['data']['post_id'], $second['data']['post_id'] );
		$this->assertSame( 1, $this->countEventsWithTitle( $title ) );
	}

	public function test_fallback_does_not_collapse_same_title_datetime_at_different_venues(): void {
		$title    = 'Touring Act Same Night ' . uniqid();
		$venue_a  = 'Distinct Venue Alpha ' . uniqid();
		$venue_b  = 'Distinct Venue Beta ' . uniqid();
		$occasion = array(
			'title'     => $title,
			'startDate' => '2026-12-12',
			'startTime' => '15:00',
		);

		$first  = $this->invoke_upsert( array_merge( $occasion, array( 'venue' => $venue_a ) ) );
		$second = $this->invoke_upsert( array_merge( $occasion, array( 'venue' => $venue_b ) ) );

		$this->assertTrue( $first['success'] ?? false, wp_json_encode( $first ) );
		$this->assertTrue( $second['success'] ?? false, wp_json_encode( $second ) );
		$this->assertSame( 'created', $first['data']['action'] );
		$this->assertSame( 'created', $second['data']['action'] );
		$this->assertNotSame( $first['data']['post_id'], $second['data']['post_id'] );
		$this->assertSame( 2, $this->countEventsWithTitle( $title ) );
	}
}
