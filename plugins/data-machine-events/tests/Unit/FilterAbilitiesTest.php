<?php
/**
 * Filter option ability tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DateTimeImmutable;
use WP_UnitTestCase;
use DataMachineEvents\Abilities\FilterAbilities;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Query\ScopeResolver;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;

class FilterAbilitiesTest extends WP_UnitTestCase {

	private FilterAbilities $abilities;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;

		wp_cache_flush();
		CalendarCache::invalidate();
		$wpdb->last_error = '';

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		foreach ( array( 'filter_group', 'filter_kind' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, Event_Post_Type::POST_TYPE, array( 'public' => true ) );
			}
		}
		if ( ! taxonomy_exists( 'filter_location' ) ) {
			register_taxonomy( 'filter_location', Event_Post_Type::POST_TYPE, array( 'public' => true, 'hierarchical' => true ) );
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}

		$this->abilities = new FilterAbilities();
	}

	public function tearDown(): void {
		global $wpdb;

		parent::tearDown();
		wp_cache_flush();
		$wpdb->last_error = '';
	}

	private function seed_event( string $title, string $start, array $terms ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $post_id, $start, ( new DateTimeImmutable( $start ) )->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ), 'publish' );
		foreach ( $terms as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, (array) $term_ids, $taxonomy );
		}
		return $post_id;
	}

	private function create_term( string $taxonomy ): int {
		$term = wp_insert_term( $taxonomy . ' ' . uniqid(), $taxonomy );
		$this->assertNotWPError( $term );
		return (int) $term['term_id'];
	}

	private function term_count( array $result, string $taxonomy, int $term_id ): int {
		$terms = FilterAbilities::flatten_hierarchy( $result['taxonomies'][ $taxonomy ]['terms'] ?? array() );
		foreach ( $terms as $term ) {
			if ( $term_id === (int) $term['term_id'] ) {
				return (int) $term['event_count'];
			}
		}
		return 0;
	}

	public function test_search_limits_offered_term_counts(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$this->seed_event( 'Needle performance', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$this->seed_event( 'Different performance', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_kind' => $term_id ) );

		$query_args = array();
		$observer   = static function ( array $args ) use ( &$query_args ): array {
			$query_args[] = $args;
			return $args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $observer );
		try {
			$result = $this->abilities->executeGetFilterOptions( array( 'event_search' => 'Needle' ) );
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $observer );
		}

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
		$this->assertNotEmpty( $query_args );
		foreach ( $query_args as $args ) {
			$this->assertSame( 'ids', $args['fields'] );
			$this->assertSame( -1, $args['posts_per_page'] );
		}
	}

	/**
	 * @dataProvider named_scope_provider
	 */
	public function test_each_named_scope_limits_offered_terms( string $scope ): void {
		$resolved = ScopeResolver::resolve( $scope );
		$this->assertIsArray( $resolved );
		$term_id = $this->create_term( 'filter_kind' );
		$time    = $resolved['time_start'] ?? '12:00:00';
		$this->seed_event( "{$scope} match", $resolved['date_start'] . ' ' . $time, array( 'filter_kind' => $term_id ) );
		$this->seed_event( "{$scope} outside", '2035-01-01 12:00:00', array( 'filter_kind' => $term_id ) );

		$result = $this->abilities->executeGetFilterOptions( array( 'scope' => $scope ) );

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
	}

	public static function named_scope_provider(): array {
		return array(
			'today'        => array( 'today' ),
			'tonight'      => array( 'tonight' ),
			'this weekend' => array( 'this-weekend' ),
			'this week'    => array( 'this-week' ),
		);
	}

	public function test_combined_search_scope_date_archive_and_taxonomy_constraints(): void {
		$scope       = ScopeResolver::resolve( 'this-week' );
		$group_id    = $this->create_term( 'filter_group' );
		$other_group = $this->create_term( 'filter_group' );
		$kind_id     = $this->create_term( 'filter_kind' );
		$date        = $scope['date_start'];
		$this->seed_event( 'Combined needle', "{$date} 12:00:00", array( 'filter_group' => $group_id, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Combined needle wrong archive', "{$date} 13:00:00", array( 'filter_group' => $other_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Wrong search', "{$date} 14:00:00", array( 'filter_group' => $group_id, 'filter_kind' => $kind_id ) );

		$result = $this->abilities->executeGetFilterOptions(
			array(
				'event_search'     => 'Combined needle',
				'scope'            => 'this-week',
				'date_context'     => array( 'date_start' => $date, 'date_end' => $date ),
				'archive_taxonomy' => 'filter_group',
				'archive_term_id'  => $group_id,
			)
		);

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $kind_id ) );
	}

	public function test_opaque_scope_token_reaches_authoritative_query_filter(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$allowed  = $this->seed_event( 'Allowed scoped event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$this->seed_event( 'Excluded scoped event', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_kind' => $term_id ) );

		$filter = static function ( array $query_args, array $input ) use ( $allowed ): array {
			if ( 'signed-scope' === ( $input['scope_token'] ?? '' ) ) {
				$query_args['post__in'] = array( $allowed );
			}
			return $query_args;
		};
		$grouped_queries = array();
		$observer        = static function ( string $sql ) use ( &$grouped_queries ): string {
			if ( false !== strpos( $sql, ' AS event_count' ) ) {
				$grouped_queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		add_filter( 'query', $observer );
		try {
			$result = $this->abilities->executeGetFilterOptions( array( 'scope_token' => 'signed-scope' ) );
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $filter, 10 );
			remove_filter( 'query', $observer );
		}

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
		$this->assertSame( array(), $grouped_queries, 'Hook-sensitive scope tokens never use direct grouped SQL.' );
	}

	public function test_grouped_sql_failure_immediately_falls_back_to_canonical_tally(): void {
		global $wpdb;

		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = current_datetime()->modify( '+1 day' );
		$this->seed_event( 'SQL fallback event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );

		$grouped_attempts = 0;
		$query_args       = array();
		$observer         = static function ( string $sql ) use ( &$grouped_attempts ): string {
			if ( false !== strpos( $sql, ' AS event_count' ) ) {
				++$grouped_attempts;
				return 'SELECT grouped_count_failure';
			}
			return $sql;
		};
		$args_observer = static function ( array $args ) use ( &$query_args ): array {
			$query_args[] = $args;
			return $args;
		};

		$previous_error       = $wpdb->last_error;
		$previous_suppression = $wpdb->suppress_errors( true );
		add_filter( 'query', $observer );
		add_filter( 'data_machine_events_calendar_query_args', $args_observer );
		try {
			$result = $this->abilities->executeGetFilterOptions( array() );
		} finally {
			remove_filter( 'query', $observer );
			remove_filter( 'data_machine_events_calendar_query_args', $args_observer );
			$wpdb->suppress_errors( $previous_suppression );
			$wpdb->last_error = $previous_error;
		}

		$this->assertSame( 1, $grouped_attempts );
		$this->assertGreaterThan( 1, count( $query_args ), 'Grouped failure is followed by canonical per-taxonomy queries.' );
		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
	}

	public function test_posts_pre_query_short_circuit_forces_canonical_fallback(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = current_datetime()->modify( '+1 day' );
		$allowed  = $this->seed_event( 'Pre-query allowed', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$this->seed_event( 'Pre-query excluded', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_kind' => $term_id ) );

		$invocations   = 0;
		$short_circuit = static function ( $posts, \WP_Query $query ) use ( $allowed, &$invocations ) {
			++$invocations;
			return Event_Post_Type::POST_TYPE === $query->get( 'post_type' ) ? array( $allowed ) : $posts;
		};
		add_filter( 'posts_pre_query', $short_circuit, 10, 2 );
		try {
			$result = $this->abilities->executeGetFilterOptions( array() );
		} finally {
			remove_filter( 'posts_pre_query', $short_circuit, 10 );
		}

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
		$this->assertGreaterThan( 1, $invocations, 'The preempted grouped capture is followed by canonical fallback queries.' );
	}

	public function test_object_term_customization_forces_canonical_fallback(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = current_datetime()->modify( '+1 day' );
		$allowed  = $this->seed_event( 'Object-term allowed', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$this->seed_event( 'Object-term excluded', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_kind' => $term_id ) );

		$filter = static function ( array $terms ) use ( $allowed ): array {
			return array_values(
				array_filter( $terms, static fn( $term ): bool => $allowed === (int) ( $term->object_id ?? 0 ) )
			);
		};
		add_filter( 'wp_get_object_terms', $filter );
		try {
			$result = $this->abilities->executeGetFilterOptions( array() );
		} finally {
			remove_filter( 'wp_get_object_terms', $filter );
		}

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
	}

	public function test_geo_intersects_active_venue_when_counting_other_taxonomies(): void {
		$selected_venue = $this->create_term( 'venue' );
		$nearby_venue   = $this->create_term( 'venue' );
		$kind_id        = $this->create_term( 'filter_kind' );
		add_term_meta( $selected_venue, '_venue_coordinates', '32.7765,-79.9311', true );
		add_term_meta( $nearby_venue, '_venue_coordinates', '32.7800,-79.9300', true );

		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$this->seed_event(
			'Selected nearby venue',
			$tomorrow->format( 'Y-m-d 20:00:00' ),
			array( 'venue' => $selected_venue, 'filter_kind' => $kind_id )
		);
		$this->seed_event(
			'Unselected nearby venue',
			$tomorrow->format( 'Y-m-d 21:00:00' ),
			array( 'venue' => $nearby_venue, 'filter_kind' => $kind_id )
		);

		$result = $this->abilities->executeGetFilterOptions(
			array(
				'active_filters' => array( 'venue' => array( $selected_venue ) ),
				'geo_lat'        => '32.7765',
				'geo_lng'        => '-79.9311',
				'geo_radius'     => 10,
			)
		);

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $kind_id ) );
	}

	public function test_grouped_and_canonical_counts_match_for_hierarchical_cross_filters(): void {
		$root = wp_insert_term( 'Filter root ' . uniqid(), 'filter_location' );
		$this->assertNotWPError( $root );
		$child = wp_insert_term( 'Filter child ' . uniqid(), 'filter_location', array( 'parent' => (int) $root['term_id'] ) );
		$this->assertNotWPError( $child );
		$deep = wp_insert_term( 'Filter deep ' . uniqid(), 'filter_location', array( 'parent' => (int) $child['term_id'] ) );
		$this->assertNotWPError( $deep );
		$selected_group = $this->create_term( 'filter_group' );
		$other_group    = $this->create_term( 'filter_group' );
		$kind_id        = $this->create_term( 'filter_kind' );
		$tomorrow       = current_datetime()->modify( '+1 day' );
		$this->seed_event( 'Root event', $tomorrow->format( 'Y-m-d 18:00:00' ), array( 'filter_location' => (int) $root['term_id'], 'filter_group' => $selected_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Child event', $tomorrow->format( 'Y-m-d 19:00:00' ), array( 'filter_location' => (int) $child['term_id'], 'filter_group' => $selected_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Deep event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_location' => (int) $deep['term_id'], 'filter_group' => $selected_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Other group deep event', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_location' => (int) $deep['term_id'], 'filter_group' => $other_group, 'filter_kind' => $kind_id ) );

		$grouped_queries   = array();
		$unbounded_queries = array();
		$observer          = static function ( string $sql ) use ( &$grouped_queries, &$unbounded_queries ): string {
			if ( false !== strpos( $sql, 'AS event_count' ) && false !== strpos( $sql, EventDatesTable::table_name() ) ) {
				$grouped_queries[] = $sql;
			}
			if ( false !== stripos( $sql, 'SELECT DISTINCT' ) && false !== strpos( $sql, EventDatesTable::table_name() ) ) {
				$unbounded_queries[] = $sql;
			}
			return $sql;
		};
		$input = array(
			'active_filters'   => array( 'filter_group' => array( $selected_group ) ),
			'archive_taxonomy' => 'filter_location',
			'archive_term_id'  => (int) $root['term_id'],
		);

		add_filter( 'query', $observer );
		$calendar_args = static fn( array $args ): array => $args;
		$pre_get_posts = static function (): void {};
		$pre_query     = static fn( $posts ) => $posts;
		$orderby       = static fn( string $sql ): string => $sql;
		add_filter( 'data_machine_events_calendar_query_args', $calendar_args );
		add_action( 'pre_get_posts', $pre_get_posts );
		add_filter( 'posts_pre_query', $pre_query );
		add_filter( 'posts_orderby', $orderby );
		try {
			$grouped = $this->abilities->executeGetFilterOptions( $input );
		} finally {
			remove_filter( 'query', $observer );
			remove_filter( 'data_machine_events_calendar_query_args', $calendar_args );
			remove_action( 'pre_get_posts', $pre_get_posts );
			remove_filter( 'posts_pre_query', $pre_query );
			remove_filter( 'posts_orderby', $orderby );
		}

		$this->assertNotFalse( has_filter( 'wp_get_object_terms', '_post_format_wp_get_object_terms' ) );
		$this->assertNotFalse( has_filter( 'get_terms', '_post_format_get_terms' ) );

		$identity_terms = static fn( array $terms ): array => $terms;
		add_filter( 'wp_get_object_terms', $identity_terms );
		try {
			$canonical = $this->abilities->executeGetFilterOptions( $input );
		} finally {
			remove_filter( 'wp_get_object_terms', $identity_terms );
		}

		$this->assertSame( $canonical['taxonomies'], $grouped['taxonomies'] );
		$this->assertSame( 1, $this->term_count( $grouped, 'filter_location', (int) $root['term_id'] ) );
		$this->assertSame( 1, $this->term_count( $grouped, 'filter_location', (int) $child['term_id'] ) );
		$this->assertSame( 1, $this->term_count( $grouped, 'filter_location', (int) $deep['term_id'] ) );
		$this->assertSame( 3, $this->term_count( $grouped, 'filter_kind', $kind_id ) );
		$this->assertSame( 3, $this->term_count( $grouped, 'filter_group', $selected_group ) );
		$this->assertSame( 1, $this->term_count( $grouped, 'filter_group', $other_group ) );
		$this->assertNotEmpty( $grouped_queries );
		$this->assertSame( array(), $unbounded_queries );
		$this->assertStringContainsString( 'GROUP BY count_tt.taxonomy, count_tt.term_id', $grouped_queries[0] );
		$this->assertStringNotContainsString( 'FROM (SELECT', $grouped_queries[0] );
	}
}
