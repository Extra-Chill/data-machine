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

	public function test_month_intersects_explicit_date_range(): void {
		$month_start = new DateTimeImmutable( 'first day of +2 months 00:00:00' );
		$outside     = $month_start->modify( '+4 days' );
		$inside      = $month_start->modify( '+9 days' );
		$this->seed_event( 'Outside explicit range', $outside->format( 'Y-m-d 20:00:00' ), $outside->format( 'Y-m-d 22:00:00' ) );
		$inside_id = $this->seed_event( 'Inside explicit range', $inside->format( 'Y-m-d 20:00:00' ), $inside->format( 'Y-m-d 22:00:00' ) );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'month'        => $month_start->format( 'Y-m' ),
				'date_start'   => $inside->format( 'Y-m-d' ),
				'date_end'     => $inside->format( 'Y-m-d' ),
				'include_html' => false,
			)
		);

		$this->assertSame( 1, $result['event_count'] );
		$this->assertSame( $inside->format( 'Y-m-d' ), $result['date_boundaries']['start_date'] );
		$this->assertSame( $inside->format( 'Y-m-d' ), $result['date_boundaries']['end_date'] );
		$this->assertSame( $inside_id, $result['paged_date_groups'][0]['events'][0]['post_id'] );
	}

	public function test_month_intersects_resolved_scope_and_can_be_empty(): void {
		$today         = new DateTimeImmutable( current_time( 'Y-m-d' ) . ' 12:00:00' );
		$tomorrow      = $today->modify( '+1 day' );
		$today_id      = $this->seed_event( 'Today scoped event', $today->format( 'Y-m-d 12:00:00' ), $today->format( 'Y-m-d 14:00:00' ) );
		$future_id     = $this->seed_event( 'Tomorrow unscoped event', $tomorrow->format( 'Y-m-d 12:00:00' ), $tomorrow->format( 'Y-m-d 14:00:00' ) );
		$current_month = $today->format( 'Y-m' );

		$scoped = $this->abilities->executeGetCalendarPage(
			array(
				'month'        => $current_month,
				'scope'        => 'today',
				'date_start'   => $today->format( 'Y-m-d' ),
				'date_end'     => $today->format( 'Y-m-d' ),
				'include_html' => false,
			)
		);
		$scoped_ids = array_column( $scoped['paged_date_groups'][0]['events'], 'post_id' );
		$this->assertSame( $today->format( 'Y-m-d' ), $scoped['date_boundaries']['start_date'] );
		$this->assertSame( $today->format( 'Y-m-d' ), $scoped['date_boundaries']['end_date'] );
		$this->assertContains( $today_id, $scoped_ids );
		$this->assertNotContains( $future_id, $scoped_ids );

		$empty = $this->abilities->executeGetCalendarPage(
			array(
				'month'        => $today->modify( '+2 months' )->format( 'Y-m' ),
				'scope'        => 'today',
				'include_html' => false,
			)
		);
		$this->assertSame( 0, $empty['event_count'] );
		$this->assertSame( array(), $empty['paged_date_groups'] );
		$this->assertGreaterThan( $empty['date_boundaries']['end_date'], $empty['date_boundaries']['start_date'] );
	}
}
