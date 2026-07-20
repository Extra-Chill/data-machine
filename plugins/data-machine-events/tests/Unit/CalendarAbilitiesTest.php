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
		if ( ! taxonomy_exists( 'calendar_test_region' ) ) {
			register_taxonomy( 'calendar_test_region', Event_Post_Type::POST_TYPE );
		}
		if ( ! taxonomy_exists( 'calendar_test_style' ) ) {
			register_taxonomy( 'calendar_test_style', Event_Post_Type::POST_TYPE );
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}

		$this->abilities = new CalendarAbilities();
		delete_transient( 'data-machine_cal_counts' );
	}

	private function seed_event( string $title, string $start, string $end, int $venue_id = 0, array $terms = array() ): int {
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
		foreach ( $terms as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, (array) $term_ids, $taxonomy );
		}

		return $post_id;
	}

	private function seed_venue( string $name, string $coordinates ): int {
		$term = wp_insert_term( $name . ' ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		$venue_id = (int) $term['term_id'];
		add_term_meta( $venue_id, '_venue_coordinates', $coordinates, true );

		return $venue_id;
	}

	private function result_post_ids( array $result ): array {
		$post_ids = array();
		foreach ( $result['paged_date_groups'] as $date_group ) {
			$post_ids = array_merge( $post_ids, array_column( $date_group['events'], 'post_id' ) );
		}

		return $post_ids;
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

	public function test_upcoming_boundaries_exclude_past_dates_from_ongoing_multi_day_events(): void {
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ) . ' 12:00:00' );
		$start = $today->modify( '-3 days' );
		$end   = $today->modify( '+2 days' );

		$event_id = $this->seed_event( 'Ongoing multi-day event', $start->format( 'Y-m-d 20:00:00' ), $end->format( 'Y-m-d 22:00:00' ) );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'include_html' => false,
			)
		);

		$this->assertSame( 1, $result['total_event_count'] );
		$this->assertSame( 1, $result['event_count'] );
		$this->assertSame( $today->format( 'Y-m-d' ), $result['date_boundaries']['start_date'] );
		$this->assertSame( $end->format( 'Y-m-d' ), $result['date_boundaries']['end_date'] );
		$this->assertSame( $today->format( 'Y-m-d' ), $result['paged_date_groups'][0]['date'] );
		$this->assertSame( array( $event_id ), $this->result_post_ids( $result ) );
	}

	public function test_taxonomy_filtered_upcoming_boundaries_include_ongoing_multi_day_events(): void {
		$term = wp_insert_term( 'Ongoing calendar venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		$venue_id = (int) $term['term_id'];
		$today    = new DateTimeImmutable( current_time( 'Y-m-d' ) . ' 12:00:00' );
		$start    = $today->modify( '-3 days' );
		$end      = $today->modify( '+2 days' );

		$event_id = $this->seed_event( 'Taxonomy-filtered ongoing event', $start->format( 'Y-m-d 20:00:00' ), $end->format( 'Y-m-d 22:00:00' ), $venue_id );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'archive_taxonomy' => 'venue',
				'archive_term_id'  => $venue_id,
				'include_html'     => false,
			)
		);

		$this->assertSame( 1, $result['total_event_count'] );
		$this->assertSame( 1, $result['event_count'] );
		$this->assertSame( $today->format( 'Y-m-d' ), $result['date_boundaries']['start_date'] );
		$this->assertSame( $end->format( 'Y-m-d' ), $result['date_boundaries']['end_date'] );
		$this->assertSame( $today->format( 'Y-m-d' ), $result['paged_date_groups'][0]['date'] );
		$this->assertSame( array( $event_id ), $this->result_post_ids( $result ) );
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

	public function test_search_constrains_totals_boundaries_and_repeated_date_rows(): void {
		$date       = new DateTimeImmutable( '+7 days' );
		$search     = 'needle-' . uniqid();
		$first_id   = $this->seed_event( "{$search} first", $date->format( 'Y-m-d 18:00:00' ), $date->format( 'Y-m-d 20:00:00' ) );
		$second_id  = $this->seed_event( "{$search} second", $date->format( 'Y-m-d 21:00:00' ), $date->format( 'Y-m-d 23:00:00' ) );
		$other_date = $date->modify( '+1 day' );
		$this->seed_event( 'Unrelated search event', $other_date->format( 'Y-m-d 20:00:00' ), $other_date->format( 'Y-m-d 22:00:00' ) );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'event_search' => $search,
				'include_html' => false,
			)
		);

		$this->assertSame( 2, $result['total_event_count'] );
		$this->assertSame( 2, $result['event_count'] );
		$this->assertSame( $date->format( 'Y-m-d' ), $result['date_boundaries']['start_date'] );
		$this->assertSame( $date->format( 'Y-m-d' ), $result['date_boundaries']['end_date'] );
		$this->assertEqualsCanonicalizing( array( $first_id, $second_id ), $this->result_post_ids( $result ) );
	}

	public function test_search_with_no_matches_returns_empty_boundaries(): void {
		$date = new DateTimeImmutable( '+8 days' );
		$this->seed_event( 'Existing event', $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ) );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'event_search' => 'absent-' . uniqid(),
				'include_html' => false,
			)
		);

		$this->assertSame( 0, $result['total_event_count'] );
		$this->assertSame( 0, $result['event_count'] );
		$this->assertSame( 0, $result['max_pages'] );
		$this->assertSame( array( 'start_date' => '', 'end_date' => '' ), $result['date_boundaries'] );
		$this->assertSame( array(), $result['paged_date_groups'] );
	}

	public function test_geo_constrains_totals_boundaries_and_rows(): void {
		$near_venue = $this->seed_venue( 'Nearby venue', '32.7765,-79.9311' );
		$far_venue  = $this->seed_venue( 'Far venue', '40.7128,-74.0060' );
		$near_date  = new DateTimeImmutable( '+9 days' );
		$far_date   = $near_date->modify( '+1 day' );
		$near_id    = $this->seed_event( 'Nearby event', $near_date->format( 'Y-m-d 20:00:00' ), $near_date->format( 'Y-m-d 22:00:00' ), $near_venue );
		$this->seed_event( 'Far event', $far_date->format( 'Y-m-d 20:00:00' ), $far_date->format( 'Y-m-d 22:00:00' ), $far_venue );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'geo_lat'         => '32.7765',
				'geo_lng'         => '-79.9311',
				'geo_radius'      => 10,
				'geo_radius_unit' => 'mi',
				'include_html'    => false,
			)
		);

		$this->assertSame( 1, $result['total_event_count'] );
		$this->assertSame( 1, $result['event_count'] );
		$this->assertSame( $near_date->format( 'Y-m-d' ), $result['date_boundaries']['start_date'] );
		$this->assertSame( array( $near_id ), $this->result_post_ids( $result ) );
	}

	public function test_combined_search_geo_taxonomy_archive_and_date_constraints_stay_aligned(): void {
		$near_venue  = $this->seed_venue( 'Combined nearby venue', '32.7765,-79.9311' );
		$far_venue   = $this->seed_venue( 'Combined far venue', '40.7128,-74.0060' );
		$region      = wp_insert_term( 'Combined region ' . uniqid(), 'calendar_test_region' );
		$other       = wp_insert_term( 'Other region ' . uniqid(), 'calendar_test_region' );
		$style       = wp_insert_term( 'Combined style ' . uniqid(), 'calendar_test_style' );
		$other_style = wp_insert_term( 'Other style ' . uniqid(), 'calendar_test_style' );
		$this->assertNotWPError( $region );
		$this->assertNotWPError( $other );
		$this->assertNotWPError( $style );
		$this->assertNotWPError( $other_style );

		$date   = new DateTimeImmutable( '+10 days' );
		$search = 'combined-' . uniqid();
		$terms  = array(
			'calendar_test_region' => (int) $region['term_id'],
			'calendar_test_style'  => (int) $style['term_id'],
		);
		$match_id = $this->seed_event( "{$search} match", $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ), $near_venue, $terms );
		$this->seed_event( "{$search} far", $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ), $far_venue, $terms );
		$this->seed_event( "{$search} wrong archive", $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ), $near_venue, array_merge( $terms, array( 'calendar_test_region' => (int) $other['term_id'] ) ) );
		$this->seed_event( "{$search} wrong filter", $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ), $near_venue, array_merge( $terms, array( 'calendar_test_style' => (int) $other_style['term_id'] ) ) );
		$this->seed_event( 'Wrong search', $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ), $near_venue, $terms );
		$outside = $date->modify( '+1 day' );
		$this->seed_event( "{$search} outside date", $outside->format( 'Y-m-d 20:00:00' ), $outside->format( 'Y-m-d 22:00:00' ), $near_venue, $terms );

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'event_search'     => $search,
				'geo_lat'          => '32.7765',
				'geo_lng'          => '-79.9311',
				'geo_radius'       => 10,
				'geo_radius_unit'  => 'mi',
				'archive_taxonomy' => 'calendar_test_region',
				'archive_term_id'  => (int) $region['term_id'],
				'tax_filter'       => array( 'calendar_test_style' => array( (int) $style['term_id'] ) ),
				'date_start'       => $date->format( 'Y-m-d' ),
				'date_end'         => $date->format( 'Y-m-d' ),
				'include_html'     => false,
			)
		);

		$this->assertSame( 1, $result['total_event_count'] );
		$this->assertSame( 1, $result['event_count'] );
		$this->assertSame( array( $match_id ), $this->result_post_ids( $result ) );
		$this->assertSame( $date->format( 'Y-m-d' ), $result['date_boundaries']['start_date'] );
		$this->assertSame( $date->format( 'Y-m-d' ), $result['date_boundaries']['end_date'] );
	}

	public function test_search_pagination_boundaries_do_not_include_unmatched_dates(): void {
		$global_start = new DateTimeImmutable( '+12 days' );
		$search_start = $global_start->modify( '+10 days' );
		$search       = 'paged-' . uniqid();

		for ( $day = 0; $day < 5; ++$day ) {
			$date = $global_start->modify( "+{$day} days" );
			for ( $event = 0; $event < 4; ++$event ) {
				$this->seed_event( "Global {$day}-{$event}", $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ) );
			}
		}

		$last_date_ids = array();
		for ( $day = 0; $day < 6; ++$day ) {
			$date = $search_start->modify( "+{$day} days" );
			for ( $event = 0; $event < 4; ++$event ) {
				$post_id = $this->seed_event( "{$search} {$day}-{$event}", $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ) );
				if ( 5 === $day ) {
					$last_date_ids[] = $post_id;
				}
			}
		}

		$result = $this->abilities->executeGetCalendarPage(
			array(
				'event_search' => $search,
				'paged'        => 2,
				'include_html' => false,
			)
		);

		$last_date = $search_start->modify( '+5 days' )->format( 'Y-m-d' );
		$this->assertSame( 24, $result['total_event_count'] );
		$this->assertSame( 2, $result['max_pages'] );
		$this->assertSame( 2, $result['current_page'] );
		$this->assertSame( $last_date, $result['date_boundaries']['start_date'] );
		$this->assertSame( $last_date, $result['date_boundaries']['end_date'] );
		$this->assertSame( 4, $result['event_count'] );
		$this->assertEqualsCanonicalizing( $last_date_ids, $this->result_post_ids( $result ) );
	}

	public function test_scope_tokens_isolate_warm_boundary_caches_and_rows(): void {
		$first_date = new DateTimeImmutable( '+30 days' );
		$second_date = $first_date->modify( '+1 day' );
		$first_id = $this->seed_event( 'First scoped event', $first_date->format( 'Y-m-d 20:00:00' ), $first_date->format( 'Y-m-d 22:00:00' ) );
		$second_id = $this->seed_event( 'Second scoped event', $second_date->format( 'Y-m-d 20:00:00' ), $second_date->format( 'Y-m-d 22:00:00' ) );

		$filter = static function ( array $query_args, array $input ) use ( $first_id, $second_id ): array {
			$allowed = array(
				'calendar-scope-a' => $first_id,
				'calendar-scope-b' => $second_id,
			);
			if ( isset( $allowed[ $input['scope_token'] ?? '' ] ) ) {
				$query_args['post__in'] = array( $allowed[ $input['scope_token'] ] );
			}
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		try {
			$first = $this->abilities->executeGetCalendarPage(
				array( 'scope_token' => 'calendar-scope-a', 'include_html' => false )
			);
			$second = $this->abilities->executeGetCalendarPage(
				array( 'scope_token' => 'calendar-scope-b', 'include_html' => false )
			);
			$first_warm = $this->abilities->executeGetCalendarPage(
				array( 'scope_token' => 'calendar-scope-a', 'include_html' => false )
			);
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $filter, 10 );
		}

		$this->assertSame( 1, $first['total_event_count'] );
		$this->assertSame( 1, $second['total_event_count'] );
		$this->assertSame( $first_date->format( 'Y-m-d' ), $first['date_boundaries']['start_date'] );
		$this->assertSame( $second_date->format( 'Y-m-d' ), $second['date_boundaries']['start_date'] );
		$this->assertSame( array( $first_id ), $this->result_post_ids( $first ) );
		$this->assertSame( array( $second_id ), $this->result_post_ids( $second ) );
		$this->assertSame( $first['total_event_count'], $first_warm['total_event_count'] );
		$this->assertSame( $first['date_boundaries'], $first_warm['date_boundaries'] );
		$this->assertSame( $this->result_post_ids( $first ), $this->result_post_ids( $first_warm ) );
	}

	public function test_scope_token_keeps_pagination_counter_and_deferred_dates_aligned(): void {
		$start       = new DateTimeImmutable( '+45 days' );
		$allowed_ids = array();
		for ( $day = 0; $day < 6; ++$day ) {
			$date = $start->modify( "+{$day} days" );
			for ( $event = 0; $event < 10; ++$event ) {
				$allowed_ids[] = $this->seed_event( "Scoped {$day}-{$event}", $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ) );
			}
		}
		$unscoped = $start->modify( '-1 day' );
		$this->seed_event( 'Unscoped earlier event', $unscoped->format( 'Y-m-d 20:00:00' ), $unscoped->format( 'Y-m-d 22:00:00' ) );

		$filter = static function ( array $query_args, array $input ) use ( $allowed_ids ): array {
			if ( 'pagination-scope' === ( $input['scope_token'] ?? '' ) ) {
				$query_args['post__in'] = $allowed_ids;
			}
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		try {
			$result = $this->abilities->executeGetCalendarPage(
				array(
					'scope_token' => 'pagination-scope',
					'progressive' => true,
					'include_html' => true,
				)
			);
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $filter, 10 );
		}

		$expected_deferred = array();
		for ( $day = 1; $day < 5; ++$day ) {
			$expected_deferred[] = $start->modify( "+{$day} days" )->format( 'Y-m-d' );
		}
		$this->assertSame( 60, $result['total_event_count'] );
		$this->assertSame( 2, $result['max_pages'] );
		$this->assertSame( $start->format( 'Y-m-d' ), $result['date_boundaries']['start_date'] );
		$this->assertSame( $start->modify( '+4 days' )->format( 'Y-m-d' ), $result['date_boundaries']['end_date'] );
		$this->assertSame( 10, $result['event_count'] );
		$this->assertSame( $expected_deferred, $result['deferred_dates'] );
		$this->assertStringContainsString( '10 of 60 Events', $result['html']['counter'] );
	}

	public function test_scope_token_preserves_multi_day_boundary_expansion(): void {
		$start = new DateTimeImmutable( '+60 days' );
		$end   = $start->modify( '+2 days' );
		$allowed_id = $this->seed_event( 'Scoped multi-day event', $start->format( 'Y-m-d 20:00:00' ), $end->format( 'Y-m-d 22:00:00' ) );
		$this->seed_event( 'Unscoped middle event', $start->modify( '+1 day' )->format( 'Y-m-d 20:00:00' ), $start->modify( '+1 day' )->format( 'Y-m-d 22:00:00' ) );

		$filter = static function ( array $query_args, array $input ) use ( $allowed_id ): array {
			if ( 'multi-day-scope' === ( $input['scope_token'] ?? '' ) ) {
				$query_args['post__in'] = array( $allowed_id );
			}
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		try {
			$result = $this->abilities->executeGetCalendarPage(
				array( 'scope_token' => 'multi-day-scope', 'include_html' => false )
			);
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $filter, 10 );
		}

		$this->assertSame( 1, $result['total_event_count'] );
		$this->assertSame( $start->format( 'Y-m-d' ), $result['date_boundaries']['start_date'] );
		$this->assertSame( $end->format( 'Y-m-d' ), $result['date_boundaries']['end_date'] );
		$this->assertSame( array( $allowed_id ), $this->result_post_ids( $result ) );
	}

	public function test_search_geo_and_consumer_scope_constraints_stay_aligned(): void {
		$venue  = $this->seed_venue( 'Scoped search venue', '32.7765,-79.9311' );
		$date   = new DateTimeImmutable( '+70 days' );
		$target = $this->seed_event( 'Scoped needle target', $date->format( 'Y-m-d 20:00:00' ), $date->format( 'Y-m-d 22:00:00' ), $venue );
		$this->seed_event( 'Scoped needle excluded', $date->format( 'Y-m-d 21:00:00' ), $date->format( 'Y-m-d 23:00:00' ), $venue );

		$filter = static function ( array $query_args, array $input ) use ( $target ): array {
			if ( 'search-geo-scope' === ( $input['scope_token'] ?? '' ) ) {
				$query_args['post__in'] = array( $target );
			}
			return $query_args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		try {
			$result = $this->abilities->executeGetCalendarPage(
				array(
					'event_search' => 'Scoped needle',
					'geo_lat' => '32.7765',
					'geo_lng' => '-79.9311',
					'geo_radius' => 10,
					'scope_token' => 'search-geo-scope',
					'include_html' => false,
				)
			);
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $filter, 10 );
		}

		$this->assertSame( 1, $result['total_event_count'] );
		$this->assertSame( 1, $result['event_count'] );
		$this->assertSame( array( $target ), $this->result_post_ids( $result ) );
	}
}
