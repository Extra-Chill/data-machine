<?php
/**
 * Calendar ability tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DateTimeImmutable;
use WP_UnitTestCase;
use DataMachineEvents\Abilities\CalendarAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;

class CalendarAbilitiesTest extends WP_UnitTestCase {

	private CalendarAbilities $abilities;

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

		$this->abilities = new CalendarAbilities();
		delete_transient( 'data-machine_cal_counts' );
	}

	private function seed_event( string $title, string $start, string $end, int $venue_id = 0 ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		EventDatesTable::upsert( $post_id, $start, $end, 'publish' );
		if ( $venue_id ) {
			wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		}

		return $post_id;
	}

	public function test_past_mode_returns_only_completed_events_with_chronological_boundaries(): void {
		$now         = new DateTimeImmutable( current_time( 'mysql' ) );
		$older_past  = $now->modify( '-4 days' );
		$recent_past = $now->modify( '-1 day' );
		$future      = $now->modify( '+4 days' );

		$this->seed_event( 'Older past event', $older_past->format( 'Y-m-d 20:00:00' ), $older_past->format( 'Y-m-d 23:00:00' ) );
		$recent_id = $this->seed_event( 'Recent past event', $recent_past->format( 'Y-m-d 20:00:00' ), $recent_past->format( 'Y-m-d 23:00:00' ) );
		$this->seed_event( 'Future event', $future->format( 'Y-m-d 20:00:00' ), $future->format( 'Y-m-d 23:00:00' ) );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'past'         => true,
				'include_html' => false,
			)
		);

		$this->assertSame( 2, $result['total_event_count'] );
		$this->assertSame( 2, $result['event_count'] );
		$this->assertSame( $recent_past->format( 'Y-m-d' ), $result['paged_date_groups'][0]['date'] );
		$this->assertSame( $recent_id, $result['paged_date_groups'][0]['events'][0]['post_id'] );
	}

	public function test_past_mode_applies_completed_scope_to_taxonomy_buckets(): void {
		$term = wp_insert_term( 'Past calendar venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		$venue_id = (int) $term['term_id'];
		$now      = new DateTimeImmutable( current_time( 'mysql' ) );
		$past     = $now->modify( '-2 days' );
		$future   = $now->modify( '+2 days' );

		$past_id = $this->seed_event( 'Venue past event', $past->format( 'Y-m-d 20:00:00' ), $past->format( 'Y-m-d 23:00:00' ), $venue_id );
		$this->seed_event( 'Venue future event', $future->format( 'Y-m-d 20:00:00' ), $future->format( 'Y-m-d 23:00:00' ), $venue_id );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'past'               => true,
				'archive_taxonomy'   => 'venue',
				'archive_term_id'    => $venue_id,
				'include_html'       => false,
			)
		);

		$this->assertSame( 1, $result['total_event_count'] );
		$this->assertSame( 1, $result['event_count'] );
		$this->assertSame( $past_id, $result['paged_date_groups'][0]['events'][0]['post_id'] );
	}
}
