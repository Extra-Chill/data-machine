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
	 * cache keys. The pre-fix bug was that the bucket cache key omitted
	 * geo params entirely, so distinct radius searches collapsed onto
	 * one bucket. The full-response key fixes that.
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

		$other_geo_envelope             = $base_envelope;
		$other_geo_envelope['geo_lat']  = '34.85';
		$other_geo_envelope['geo_lng']  = '-82.40';

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
}
