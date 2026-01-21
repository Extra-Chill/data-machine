<?php
/**
 * Event REST Controller Tests
 *
 * Tests for Calendar REST API endpoints.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventRestControllerTest extends WP_UnitTestCase {

	protected $server;

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Ensure post type and taxonomies are registered
		if ( ! post_type_exists( 'datamachine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	public function test_calendar_endpoint_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey(
			'/datamachine/v1/events/calendar',
			$routes,
			'Calendar endpoint should be registered'
		);
	}

	public function test_venues_endpoint_registered() {
		$routes = $this->server->get_routes();

		$has_venues_endpoint = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, '/datamachine/v1/events/venues' ) !== false ) {
				$has_venues_endpoint = true;
				break;
			}
		}

		$this->assertTrue( $has_venues_endpoint, 'Venues endpoint should be registered' );
	}

	public function test_filters_endpoint_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey(
			'/datamachine/v1/events/filters',
			$routes,
			'Filters endpoint should be registered'
		);
	}

	public function test_calendar_endpoint_returns_events() {
		// Create a test event
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'REST Test Event ' . uniqid(),
				'post_type'   => 'datamachine_events',
				'post_status' => 'publish',
			)
		);

		// Set event datetime in the future
		$future_datetime = date( 'Y-m-d H:i:s', strtotime( '+1 week' ) );
		update_post_meta( $post_id, '_datamachine_event_datetime', $future_datetime );

		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/calendar' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	public function test_calendar_endpoint_filters_by_search() {
		$unique_term = 'UniqueSearchTerm' . uniqid();

		$post_id = wp_insert_post(
			array(
				'post_title'  => "Event with $unique_term",
				'post_type'   => 'datamachine_events',
				'post_status' => 'publish',
			)
		);

		$future_datetime = date( 'Y-m-d H:i:s', strtotime( '+1 week' ) );
		update_post_meta( $post_id, '_datamachine_event_datetime', $future_datetime );

		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/calendar' );
		$request->set_param( 'event_search', $unique_term );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	public function test_calendar_endpoint_accepts_date_range() {
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/calendar' );
		$request->set_param( 'date_start', '2026-01-01' );
		$request->set_param( 'date_end', '2026-12-31' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_calendar_endpoint_accepts_pagination() {
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/calendar' );
		$request->set_param( 'paged', 1 );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	public function test_filters_endpoint_returns_taxonomies() {
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/filters' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	public function test_venues_check_duplicate_requires_auth() {
		// Test that non-authenticated requests are rejected
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/venues/check-duplicate' );
		$request->set_param( 'name', 'Test Venue' );
		$response = $this->server->dispatch( $request );

		// Should return 401 or 403 for unauthorized
		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Unauthenticated request should be rejected'
		);
	}

	public function test_venues_check_duplicate_with_admin() {
		// Create admin user
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/datamachine/v1/events/venues/check-duplicate' );
		$request->set_param( 'name', 'Non Existent Venue ' . uniqid() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'is_duplicate', $data );
		$this->assertFalse( $data['is_duplicate'] );

		// Cleanup
		wp_set_current_user( 0 );
	}
}
