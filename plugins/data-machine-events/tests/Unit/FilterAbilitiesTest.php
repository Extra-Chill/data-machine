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

		wp_cache_flush();
		$wpdb->last_error = '';
		parent::tearDown();
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

		$result = $this->abilities->executeGetFilterOptions( array( 'event_search' => 'Needle' ) );

		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );
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
		add_filter( 'data_machine_events_calendar_query_args', $filter, 10, 2 );
		$result = $this->abilities->executeGetFilterOptions( array( 'scope_token' => 'signed-scope' ) );
		remove_filter( 'data_machine_events_calendar_query_args', $filter, 10 );

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

	public function test_grouped_archive_counts_replace_unbounded_id_scans_exactly_once(): void {
		$root = wp_insert_term( 'Archive root ' . uniqid(), 'filter_location' );
		$this->assertNotWPError( $root );
		$child = wp_insert_term( 'Archive child ' . uniqid(), 'filter_location', array( 'parent' => (int) $root['term_id'] ) );
		$this->assertNotWPError( $child );
		$kind_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$this->seed_event( 'Archive direct', $tomorrow->format( 'Y-m-d 18:00:00' ), array( 'filter_location' => (int) $root['term_id'], 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Archive child', $tomorrow->format( 'Y-m-d 19:00:00' ), array( 'filter_location' => (int) $child['term_id'], 'filter_kind' => $kind_id ) );

		$grouped  = array();
		$unbounded = array();
		$observer = static function ( string $sql ) use ( &$grouped, &$unbounded ): string {
			if ( false !== strpos( $sql, ' AS event_count' ) ) {
				$grouped[] = $sql;
			}
			if ( false !== stripos( $sql, 'SELECT DISTINCT' ) && false !== strpos( $sql, EventDatesTable::table_name() ) ) {
				$unbounded[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observer );
		try {
			$result = $this->abilities->executeGetFilterOptions(
				array(
					'archive_taxonomy' => 'filter_location',
					'archive_term_id'  => (int) $root['term_id'],
				)
			);
		} finally {
			remove_filter( 'query', $observer );
		}

		$this->assertCount( 1, $grouped );
		$this->assertSame( array(), $unbounded );
		$this->assertSame( 1, $this->term_count( $result, 'filter_location', (int) $root['term_id'] ) );
		$this->assertSame( 1, $this->term_count( $result, 'filter_location', (int) $child['term_id'] ) );
		$this->assertSame( 2, $this->term_count( $result, 'filter_kind', $kind_id ) );
	}

	public function test_grouped_and_canonical_counts_match_hierarchy_cross_filters_and_time_scopes(): void {
		$root = wp_insert_term( 'Parity root ' . uniqid(), 'filter_location' );
		$this->assertNotWPError( $root );
		$child = wp_insert_term( 'Parity child ' . uniqid(), 'filter_location', array( 'parent' => (int) $root['term_id'] ) );
		$this->assertNotWPError( $child );
		$deep = wp_insert_term( 'Parity deep ' . uniqid(), 'filter_location', array( 'parent' => (int) $child['term_id'] ) );
		$this->assertNotWPError( $deep );
		$selected_group = $this->create_term( 'filter_group' );
		$other_group    = $this->create_term( 'filter_group' );
		$kind_id        = $this->create_term( 'filter_kind' );
		$tomorrow       = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$yesterday      = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '-1 day' );
		$this->seed_event( 'Root event', $tomorrow->format( 'Y-m-d 18:00:00' ), array( 'filter_location' => (int) $root['term_id'], 'filter_group' => $selected_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Child event', $tomorrow->format( 'Y-m-d 19:00:00' ), array( 'filter_location' => (int) $child['term_id'], 'filter_group' => $selected_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Deep multi-term event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_location' => array( (int) $child['term_id'], (int) $deep['term_id'] ), 'filter_group' => $selected_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Other group event', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_location' => (int) $deep['term_id'], 'filter_group' => $other_group, 'filter_kind' => $kind_id ) );
		$this->seed_event( 'Past event', $yesterday->format( 'Y-m-d 20:00:00' ), array( 'filter_location' => (int) $deep['term_id'], 'filter_group' => $selected_group, 'filter_kind' => $kind_id ) );

		$input = array(
			'active_filters'   => array( 'filter_group' => array( $selected_group ) ),
			'archive_taxonomy' => 'filter_location',
			'archive_term_id'  => (int) $root['term_id'],
		);
		$optimized = $this->abilities->executeGetFilterOptions( $input );

		$identity = static fn( array $terms ): array => $terms;
		add_filter( 'wp_get_object_terms', $identity );
		try {
			$canonical = ( new FilterAbilities() )->executeGetFilterOptions( $input );
		} finally {
			remove_filter( 'wp_get_object_terms', $identity );
		}

		$this->assertSame( $canonical['taxonomies'], $optimized['taxonomies'] );
		$this->assertSame( 1, $this->term_count( $optimized, 'filter_location', (int) $root['term_id'] ) );
		$this->assertSame( 2, $this->term_count( $optimized, 'filter_location', (int) $child['term_id'] ) );
		$this->assertSame( 1, $this->term_count( $optimized, 'filter_location', (int) $deep['term_id'] ) );
		$this->assertSame( 3, $this->term_count( $optimized, 'filter_kind', $kind_id ) );
		$this->assertSame( 3, $this->term_count( $optimized, 'filter_group', $selected_group ) );
		$this->assertSame( 1, $this->term_count( $optimized, 'filter_group', $other_group ) );

		$past = ( new FilterAbilities() )->executeGetFilterOptions( array_merge( $input, array( 'date_context' => array( 'past' => '1' ) ) ) );
		$this->assertSame( 1, $this->term_count( $past, 'filter_kind', $kind_id ) );
	}

	public function test_archive_render_shape_reuses_generation_cache_and_refreshes_after_invalidation(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$this->seed_event( 'Cache event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$queries  = 0;
		$observer = static function ( string $sql ) use ( &$queries ): string {
			if ( false !== strpos( $sql, ' AS event_count' ) ) {
				++$queries;
			}
			return $sql;
		};
		add_filter( 'query', $observer );
		try {
			$archive_input = array(
				'active_filters'   => array(),
				'date_context'     => array( 'past' => '' ),
				'archive_taxonomy' => '',
				'archive_term_id'  => 0,
			);
			$this->abilities->executeGetFilterOptions( $archive_input );
			$this->abilities->executeGetFilterOptions( $archive_input );
			$this->assertSame( 1, $queries, 'Initial and paginated archive renders pass the same page-independent filter inventory shape.' );
			CalendarCache::invalidate();
			$this->abilities->executeGetFilterOptions( $archive_input );
		} finally {
			remove_filter( 'query', $observer );
		}

		$this->assertSame( 2, $queries );
	}

	public function test_noop_query_hooks_remain_optimized_but_mutations_fall_back(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$this->seed_event( 'Hook event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$grouped = 0;
		$query_observer = static function ( string $sql ) use ( &$grouped ): string {
			if ( false !== strpos( $sql, ' AS event_count' ) ) {
				++$grouped;
			}
			return $sql;
		};
		$noop_args = static fn( array $args ): array => $args;
		$noop_posts = static fn( $posts ) => $posts;
		$noop_order = static fn( string $orderby ): string => $orderby;
		add_filter( 'query', $query_observer );
		add_filter( 'data_machine_events_calendar_query_args', $noop_args );
		add_filter( 'posts_pre_query', $noop_posts );
		add_filter( 'posts_orderby', $noop_order );
		try {
			( new FilterAbilities() )->executeGetFilterOptions( array() );
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $noop_args );
			remove_filter( 'posts_pre_query', $noop_posts );
			remove_filter( 'posts_orderby', $noop_order );
		}

		$this->assertSame( 1, $grouped );

		$mutate = static function ( array $args ): array {
			$args['post__in'] = array( 0 );
			return $args;
		};
		add_filter( 'data_machine_events_calendar_query_args', $mutate );
		try {
			$result = ( new FilterAbilities() )->executeGetFilterOptions( array() );
		} finally {
			remove_filter( 'data_machine_events_calendar_query_args', $mutate );
			remove_filter( 'query', $query_observer );
		}

		$this->assertSame( 1, $grouped, 'A warmed inventory is not reused, and customized fallback does not run another grouped query.' );
		$this->assertSame( 0, $this->term_count( $result, 'filter_kind', $term_id ) );
	}

	public function test_sql_failure_and_portable_database_use_canonical_fallback(): void {
		global $wpdb;

		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$this->seed_event( 'Fallback event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$attempts = 0;
		$fail     = static function ( string $sql ) use ( &$attempts ): string {
			if ( false !== strpos( $sql, ' AS event_count' ) ) {
				++$attempts;
				return 'SELECT grouped_inventory_failure';
			}
			return $sql;
		};
		$previous_suppression = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$result = ( new FilterAbilities() )->executeGetFilterOptions( array() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous_suppression );
		}
		$this->assertSame( 1, $attempts );
		$this->assertSame( 1, $this->term_count( $result, 'filter_kind', $term_id ) );

		CalendarCache::invalidate();
		$is_mysql       = $wpdb->is_mysql;
		$wpdb->is_mysql = false;
		try {
			$portable = ( new FilterAbilities() )->executeGetFilterOptions( array() );
		} finally {
			$wpdb->is_mysql = $is_mysql;
		}
		$this->assertSame( 1, $this->term_count( $portable, 'filter_kind', $term_id ) );
	}

	public function test_parse_query_and_posts_pre_query_mutations_force_canonical_fallback(): void {
		$term_id  = $this->create_term( 'filter_kind' );
		$tomorrow = ( new DateTimeImmutable( current_time( 'mysql' ) ) )->modify( '+1 day' );
		$allowed  = $this->seed_event( 'Allowed hook event', $tomorrow->format( 'Y-m-d 20:00:00' ), array( 'filter_kind' => $term_id ) );
		$this->seed_event( 'Excluded hook event', $tomorrow->format( 'Y-m-d 21:00:00' ), array( 'filter_kind' => $term_id ) );

		$parse = static function ( \WP_Query $query ): void {
			if ( Event_Post_Type::POST_TYPE === $query->get( 'post_type' ) ) {
				$query->set( 'post__in', array( 0 ) );
			}
		};
		add_action( 'parse_query', $parse );
		try {
			$parsed = ( new FilterAbilities() )->executeGetFilterOptions( array() );
		} finally {
			remove_action( 'parse_query', $parse );
		}
		$this->assertSame( 0, $this->term_count( $parsed, 'filter_kind', $term_id ) );

		$short_circuit = static function ( $posts, \WP_Query $query ) use ( $allowed ) {
			return Event_Post_Type::POST_TYPE === $query->get( 'post_type' ) ? array( $allowed ) : $posts;
		};
		add_filter( 'posts_pre_query', $short_circuit, 10, 2 );
		try {
			$short_circuited = ( new FilterAbilities() )->executeGetFilterOptions( array() );
		} finally {
			remove_filter( 'posts_pre_query', $short_circuit, 10 );
		}
		$this->assertSame( 1, $this->term_count( $short_circuited, 'filter_kind', $term_id ) );
	}
}
