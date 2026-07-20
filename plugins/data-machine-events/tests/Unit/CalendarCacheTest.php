<?php
/**
 * Calendar Cache Tests
 *
 * Tests the full-response cache layer added in
 * Extra-Chill/data-machine-events#246.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.32.1
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

class CalendarCacheTest extends WP_UnitTestCase {

	protected $server;

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		CacheInvalidator::init();
	}

	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		// Ensure no test bleeds cache state into the next.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( CalendarCache::GROUP );
		} else {
			wp_cache_flush();
		}
		parent::tearDown();
	}

	/**
	 * Two requests differing only by `lat`/`lng` MUST produce distinct
	 * full-response cache keys.
	 */
	public function test_full_response_key_includes_geo_params() {
		$base_envelope = array(
			'paged'            => 1,
			'past'             => true,
			'event_search'     => '',
			'date_start'       => '',
			'date_end'         => '',
			'scope'            => '',
			'tax_filter'       => array(),
			'archive_taxonomy' => 'venue',
			'archive_term_id'  => 27364,
			'geo_lat'          => '47.66',
			'geo_lng'          => '-122.33',
			'geo_radius'       => 2,
			'geo_radius_unit'  => 'mi',
		);

		$other_geo_envelope            = $base_envelope;
		$other_geo_envelope['geo_lat'] = '34.85';
		$other_geo_envelope['geo_lng'] = '-82.40';

		$other_radius_envelope               = $base_envelope;
		$other_radius_envelope['geo_radius'] = 25;

		$other_unit_envelope                    = $base_envelope;
		$other_unit_envelope['geo_radius_unit'] = 'km';

		$key_base   = CalendarCache::generate_full_response_key( $base_envelope );
		$key_geo    = CalendarCache::generate_full_response_key( $other_geo_envelope );
		$key_radius = CalendarCache::generate_full_response_key( $other_radius_envelope );
		$key_unit   = CalendarCache::generate_full_response_key( $other_unit_envelope );

		$this->assertNotEquals( $key_base, $key_geo, 'lat/lng change MUST change the cache key' );
		$this->assertNotEquals( $key_base, $key_radius, 'radius change MUST change the cache key' );
		$this->assertNotEquals( $key_base, $key_unit, 'radius_unit change MUST change the cache key' );

		// Same envelope MUST produce the same key (deterministic hashing).
		$this->assertEquals(
			$key_base,
			CalendarCache::generate_full_response_key( $base_envelope ),
			'identical envelope MUST produce identical cache key'
		);
	}

	public function test_bucket_key_includes_geo_and_time_constraints() {
		$params = array(
			'show_past'       => false,
			'search_query'    => 'calendar',
			'date_start'      => '2026-07-19',
			'date_end'        => '2026-07-19',
			'time_start'      => '18:00:00',
			'time_end'        => '23:59:59',
			'geo_lat'         => '32.7765',
			'geo_lng'         => '-79.9311',
			'geo_radius'      => 25,
			'geo_radius_unit' => 'mi',
		);

		$other_lat            = $params;
		$other_lat['geo_lat'] = '33.7765';
		$other_radius               = $params;
		$other_radius['geo_radius'] = 50;
		$other_time               = $params;
		$other_time['time_start'] = '20:00:00';

		$key = CalendarCache::generate_key( $params, 'dates' );
		$this->assertNotSame( $key, CalendarCache::generate_key( $other_lat, 'dates' ) );
		$this->assertNotSame( $key, CalendarCache::generate_key( $other_radius, 'dates' ) );
		$this->assertNotSame( $key, CalendarCache::generate_key( $other_time, 'dates' ) );
	}

	/**
	 * The `format` envelope field MUST be part of the cache key so the
	 * legacy HTML-string envelope and the phase-1 data-only envelope
	 * never collide in the same bucket. See refactor #298.
	 */
	public function test_full_response_key_includes_format() {
		$html_envelope = array(
			'paged'            => 1,
			'past'             => false,
			'event_search'     => '',
			'date_start'       => '',
			'date_end'         => '',
			'scope'            => '',
			'tax_filter'       => array(),
			'archive_taxonomy' => '',
			'archive_term_id'  => 0,
			'geo_lat'          => '',
			'geo_lng'          => '',
			'geo_radius'       => 25,
			'geo_radius_unit'  => 'mi',
			'format'           => '',
		);

		$data_envelope           = $html_envelope;
		$data_envelope['format'] = 'data';

		$this->assertNotEquals(
			CalendarCache::generate_full_response_key( $html_envelope ),
			CalendarCache::generate_full_response_key( $data_envelope ),
			'format=data MUST produce a distinct cache key from the legacy HTML envelope'
		);
	}

	/**
	 * Past=1 requests MUST be served from cache on the second hit
	 * without re-running EventQueryBuilder. We pre-seed the cache with
	 * a sentinel response body that no real query could produce; if
	 * the controller calls through to CalendarAbilities it would
	 * overwrite our sentinel and return real (different) data.
	 */
	public function test_past_request_served_from_cache_without_rerunning_query() {
		$envelope = array(
			'paged'            => 1,
			'past'             => true,
			'event_search'     => '',
			'date_start'       => '',
			'date_end'         => '',
			'scope'            => '',
			'tax_filter'       => array(),
			'archive_taxonomy' => '',
			'archive_term_id'  => 0,
			'geo_lat'          => '',
			'geo_lng'          => '',
			'geo_radius'       => 25,
			'geo_radius_unit'  => 'mi',
		);

		$sentinel = array(
			'success'    => true,
			'html'       => '<!-- SENTINEL CACHE HIT past=1 -->',
			'pagination' => array(
				'html'         => '<!-- SENTINEL pagination -->',
				'current_page' => 1,
				'max_pages'    => 1,
				'total_events' => 999999,
			),
			'counter'    => '<!-- SENTINEL counter -->',
			'navigation' => array(
				'html'         => '<!-- SENTINEL navigation -->',
				'past_count'   => 999999,
				'future_count' => 0,
				'show_past'    => true,
			),
		);

		$cache_key = CalendarCache::generate_full_response_key( $envelope );
		CalendarCache::set_full_response( $cache_key, $sentinel, CalendarCache::TTL_FULL_PAST );

		// Anonymous request — should hit the cache (manage_options bypass
		// only fires for authenticated editors).
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/calendar' );
		$request->set_param( 'past', '1' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertEquals(
			$sentinel['html'],
			$data['html'],
			'past=1 cache hit MUST return the cached body, not a freshly computed one'
		);
		$this->assertEquals(
			$sentinel['pagination']['total_events'],
			$data['pagination']['total_events'],
			'past=1 cache hit MUST return the cached pagination, not a freshly computed one'
		);
		$this->assertEquals(
			$sentinel['navigation']['past_count'],
			$data['navigation']['past_count'],
			'past=1 cache hit MUST return the cached navigation counts, not freshly computed ones'
		);
	}

	/**
	 * Past=1 envelopes MUST get a longer TTL than upcoming envelopes —
	 * the whole DOS-mitigation premise is that historical data is
	 * immutable and worth caching aggressively.
	 */
	public function test_ttl_for_envelope_uses_past_ttl_when_past_flag_set() {
		$past_envelope     = array( 'past' => true );
		$upcoming_envelope = array( 'past' => false );

		$this->assertEquals(
			CalendarCache::TTL_FULL_PAST,
			CalendarCache::ttl_for_envelope( $past_envelope )
		);
		$this->assertEquals(
			CalendarCache::TTL_FULL_UPCOMING,
			CalendarCache::ttl_for_envelope( $upcoming_envelope )
		);
		$this->assertGreaterThan(
			CalendarCache::TTL_FULL_UPCOMING,
			CalendarCache::TTL_FULL_PAST,
			'past TTL must be longer than upcoming TTL'
		);
	}

	/**
	 * Editors with `manage_options` MUST bypass the cache so they see
	 * fresh data immediately after publishing or editing events.
	 */
	public function test_admin_user_bypasses_cache() {
		$envelope = array(
			'paged'            => 1,
			'past'             => true,
			'event_search'     => '',
			'date_start'       => '',
			'date_end'         => '',
			'scope'            => '',
			'tax_filter'       => array(),
			'archive_taxonomy' => '',
			'archive_term_id'  => 0,
			'geo_lat'          => '',
			'geo_lng'          => '',
			'geo_radius'       => 25,
			'geo_radius_unit'  => 'mi',
		);

		$sentinel = array(
			'success'    => true,
			'html'       => '<!-- SENTINEL admin should bypass -->',
			'pagination' => array(
				'html'         => '',
				'current_page' => 1,
				'max_pages'    => 1,
				'total_events' => 999999,
			),
			'counter'    => '',
			'navigation' => array(
				'html'         => '',
				'past_count'   => 999999,
				'future_count' => 0,
				'show_past'    => true,
			),
		);

		$cache_key = CalendarCache::generate_full_response_key( $envelope );
		CalendarCache::set_full_response( $cache_key, $sentinel, CalendarCache::TTL_FULL_PAST );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/calendar' );
		$request->set_param( 'past', '1' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		// Admin should see freshly computed data, not the sentinel.
		$this->assertNotEquals(
			$sentinel['html'],
			$data['html'],
			'admin users with manage_options MUST bypass the cache'
		);

		wp_set_current_user( 0 );
	}

	public function test_scope_tokens_isolate_cache_keys_without_leaking_token_contents() {
		$token = 'signed.private.scope.payload';
		$key   = CalendarCache::generate_full_response_key( array( 'scope_token' => $token ) );

		$this->assertNotSame(
			CalendarCache::generate_full_response_key( array() ),
			$key
		);
		$this->assertStringNotContainsString( $token, $key );
	}

	public function test_identical_venue_assignment_does_not_invalidate_calendar_cache(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$venue   = wp_insert_term( 'Cache Identical Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		$invalidations = $this->count_calendar_invalidations(
			static function () use ( $post_id, $venue ): void {
				wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );
			}
		);

		$this->assertSame( 0, $invalidations );
	}

	public function test_venue_assignment_invalidates_calendar_cache_once(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$venue   = wp_insert_term( 'Cache Assignment Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );

		$invalidations = $this->count_calendar_invalidations(
			static function () use ( $post_id, $venue ): void {
				wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );
			}
		);

		$this->assertSame( 1, $invalidations );
	}

	public function test_identical_venue_append_does_not_invalidate_calendar_cache(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$venue   = wp_insert_term( 'Cache Identical Append Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		$invalidations = $this->count_calendar_invalidations(
			static function () use ( $post_id, $venue ): void {
				wp_add_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );
			}
		);

		$this->assertSame( 0, $invalidations );
	}

	public function test_real_venue_append_invalidates_calendar_cache_once(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$first   = wp_insert_term( 'Cache Existing Append Venue ' . uniqid(), 'venue' );
		$second  = wp_insert_term( 'Cache Added Append Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		wp_set_object_terms( $post_id, array( $first['term_id'] ), 'venue' );

		$invalidations = $this->count_calendar_invalidations(
			static function () use ( $post_id, $second ): void {
				wp_add_object_terms( $post_id, array( $second['term_id'] ), 'venue' );
			}
		);

		$this->assertSame( 1, $invalidations );
	}

	public function test_venue_replacement_invalidates_calendar_cache_once(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$first   = wp_insert_term( 'Cache First Venue ' . uniqid(), 'venue' );
		$second  = wp_insert_term( 'Cache Second Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		wp_set_object_terms( $post_id, array( $first['term_id'] ), 'venue' );

		$invalidations = $this->count_calendar_invalidations(
			static function () use ( $post_id, $second ): void {
				wp_set_object_terms( $post_id, array( $second['term_id'] ), 'venue' );
			}
		);

		$this->assertSame( 1, $invalidations );
		$this->assertSame( array(), $this->pending_removed_terms() );
	}

	public function test_reentrant_removal_for_another_event_invalidates_independently(): void {
		$first_post  = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$second_post = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$first_old   = wp_insert_term( 'Cache Reentrant First Old ' . uniqid(), 'venue' );
		$first_new   = wp_insert_term( 'Cache Reentrant First New ' . uniqid(), 'venue' );
		$second_term = wp_insert_term( 'Cache Reentrant Second ' . uniqid(), 'venue' );
		$this->assertNotWPError( $first_old );
		$this->assertNotWPError( $first_new );
		$this->assertNotWPError( $second_term );
		wp_set_object_terms( $first_post, array( $first_old['term_id'] ), 'venue' );
		wp_set_object_terms( $second_post, array( $second_term['term_id'] ), 'venue' );

		$reentrant_removal = static function ( $post_id, $terms, $tt_ids, $taxonomy ) use ( $first_post, $second_post, $second_term ): void {
			if ( $first_post === (int) $post_id && 'venue' === $taxonomy ) {
				wp_remove_object_terms( $second_post, array( $second_term['term_id'] ), 'venue' );
			}
		};
		add_action( 'set_object_terms', $reentrant_removal, 20, 4 );
		try {
			$invalidations = $this->count_calendar_invalidations(
				static function () use ( $first_post, $first_new ): void {
					wp_set_object_terms( $first_post, array( $first_new['term_id'] ), 'venue' );
				}
			);
		} finally {
			remove_action( 'set_object_terms', $reentrant_removal, 20 );
		}

		$this->assertSame( 2, $invalidations );
		$this->assertSame( array(), $this->pending_removed_terms() );
		$this->assertSame( array(), $this->operation_state( 'added_terms' ) );
	}

	public function test_same_relationship_key_nested_removals_use_lifo_state(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$first   = wp_insert_term( 'Cache Nested Removal First ' . uniqid(), 'venue' );
		$second  = wp_insert_term( 'Cache Nested Removal Second ' . uniqid(), 'venue' );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		wp_set_object_terms( $post_id, array( $first['term_id'], $second['term_id'] ), 'venue' );

		$nested       = false;
		$invalidations = 0;
		$snapshots     = array();
		$query_filter  = static function ( string $query ) use ( &$invalidations ): string {
			if ( str_starts_with( ltrim( $query ), 'DELETE FROM' ) && str_contains( $query, '_transient_' . CalendarCache::PREFIX ) ) {
				++$invalidations;
			}
			return $query;
		};
		$nested_removal = static function ( $object_id, $tt_ids, $taxonomy ) use ( $post_id, $first, &$nested ): void {
			if ( ! $nested && $post_id === (int) $object_id && 'venue' === $taxonomy ) {
				$nested = true;
				wp_remove_object_terms( $post_id, array( $first['term_id'] ), 'venue' );
			}
		};
		$observe_removal = static function ( $object_id, $tt_ids, $taxonomy ) use ( $post_id, &$invalidations, &$snapshots ): void {
			if ( $post_id === (int) $object_id && 'venue' === $taxonomy ) {
				$remaining   = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'tt_ids' ) );
				$snapshots[] = array(
					'invalidations' => $invalidations,
					'remaining'     => is_wp_error( $remaining ) ? -1 : count( $remaining ),
				);
			}
		};

		add_filter( 'query', $query_filter );
		add_action( 'delete_term_relationships', $nested_removal, 20, 3 );
		add_action( 'deleted_term_relationships', $observe_removal, 20, 3 );
		try {
			wp_remove_object_terms( $post_id, array( $first['term_id'], $second['term_id'] ), 'venue' );
		} finally {
			remove_filter( 'query', $query_filter );
			remove_action( 'delete_term_relationships', $nested_removal, 20 );
			remove_action( 'deleted_term_relationships', $observe_removal, 20 );
		}

		$this->assertSame( 2, $invalidations );
		$this->assertSame(
			array(
				array(
					'invalidations' => 1,
					'remaining'     => 1,
				),
				array(
					'invalidations' => 2,
					'remaining'     => 0,
				),
			),
			$snapshots
		);
		$this->assertSame( array(), $this->pending_removed_terms() );
	}

	public function test_bulk_venue_removal_invalidates_once_and_clears_pending_state(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$first   = wp_insert_term( 'Cache Bulk Removal One ' . uniqid(), 'venue' );
		$second  = wp_insert_term( 'Cache Bulk Removal Two ' . uniqid(), 'venue' );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		wp_set_object_terms( $post_id, array( $first['term_id'], $second['term_id'] ), 'venue' );

		$invalidations = $this->count_calendar_invalidations(
			static function () use ( $post_id, $first, $second ): void {
				wp_remove_object_terms( $post_id, array( $first['term_id'], $second['term_id'] ), 'venue' );
			}
		);

		$this->assertSame( 1, $invalidations );
		$this->assertSame( array(), $this->pending_removed_terms() );
	}

	public function test_venue_removal_invalidates_calendar_cache_once(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$venue   = wp_insert_term( 'Cache Removal Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		$invalidations = $this->count_calendar_invalidations(
			static function () use ( $post_id, $venue ): void {
				wp_remove_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );
			}
		);

		$this->assertSame( 1, $invalidations );
		$this->assertSame( array(), $this->pending_removed_terms() );

		$next_venue = wp_insert_term( 'Cache Next Operation Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $next_venue );
		$this->assertSame(
			1,
			$this->count_calendar_invalidations(
				static function () use ( $post_id, $next_venue ): void {
					wp_set_object_terms( $post_id, array( $next_venue['term_id'] ), 'venue' );
				}
			),
			'A standalone removal must not suppress a later independent operation.'
		);
	}

	private function count_calendar_invalidations( callable $operation ): int {
		$count  = 0;
		$filter = static function ( string $query ) use ( &$count ): string {
			if ( str_starts_with( ltrim( $query ), 'DELETE FROM' ) && str_contains( $query, '_transient_' . CalendarCache::PREFIX ) ) {
				++$count;
			}
			return $query;
		};

		add_filter( 'query', $filter );
		$operation();
		remove_filter( 'query', $filter );

		return $count;
	}

	private function pending_removed_terms(): array {
		return $this->operation_state( 'pending_removed_terms' );
	}

	private function operation_state( string $name ): array {
		$property = new \ReflectionProperty( CacheInvalidator::class, $name );
		$property->setAccessible( true );
		return $property->getValue();
	}

	public function test_scope_tokens_isolate_boundary_cache_keys_without_leaking_contents() {
		$first_token = 'signed.private.boundary.scope.a';
		$second_token = 'signed.private.boundary.scope.b';
		$first_key = CalendarCache::generate_key( array( 'scope_token' => $first_token ), 'dates' );
		$second_key = CalendarCache::generate_key( array( 'scope_token' => $second_token ), 'dates' );

		$this->assertNotSame( $first_key, $second_key );
		$this->assertNotSame( CalendarCache::generate_key( array(), 'dates' ), $first_key );
		$this->assertStringNotContainsString( $first_token, $first_key );
		$this->assertStringNotContainsString( $second_token, $second_key );
	}
}
