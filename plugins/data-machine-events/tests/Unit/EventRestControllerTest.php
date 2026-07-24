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
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;
use const DataMachineEvents\Api\API_NAMESPACE;

class EventRestControllerTest extends WP_UnitTestCase {

	protected $server;
	private int $original_user_id;

	public function setUp(): void {
		// Create the table before WP_UnitTestCase rewrites CREATE TABLE as temporary.
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}

		parent::setUp();
		$this->original_user_id = get_current_user_id();
		wp_set_current_user( 0 );

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Ensure post type and taxonomies are registered
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
		wp_set_current_user( $this->original_user_id );
		parent::tearDown();
	}

	private function calendar_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/' . API_NAMESPACE . '/events/calendar' );
		$request->set_header( 'X-Requested-With', 'XMLHttpRequest' );
		return $request;
	}

	public function test_calendar_endpoint_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey(
			'/' . API_NAMESPACE . '/events/calendar',
			$routes,
			'Calendar endpoint should be registered'
		);
	}

	public function test_venues_endpoint_registered() {
		$routes = $this->server->get_routes();

		$has_venues_endpoint = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, '/' . API_NAMESPACE . '/events/venues' ) !== false ) {
				$has_venues_endpoint = true;
				break;
			}
		}

		$this->assertTrue( $has_venues_endpoint, 'Venues endpoint should be registered' );
	}

	public function test_filters_endpoint_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey(
			'/' . API_NAMESPACE . '/events/filters',
			$routes,
			'Filters endpoint should be registered'
		);
	}

	public function test_calendar_endpoint_returns_events() {
		// Create a test event
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'REST Test Event ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);

		// Set event datetime in the future (table is the query source of truth).
		$future_datetime = current_datetime()->modify( '+1 week' )->format( 'Y-m-d H:i:s' );
		EventDatesTable::upsert( $post_id, $future_datetime );

		$request  = $this->calendar_request();
		$request->set_param( 'format', 'data' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( array( $post_id ), array_column( $data['events'], 'id' ) );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	public function test_calendar_endpoint_filters_by_search() {
		$unique_term = 'UniqueSearchTerm' . uniqid();

		$post_id = wp_insert_post(
			array(
				'post_title'  => "Event with $unique_term",
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);

		$future_datetime = current_datetime()->modify( '+1 week' )->format( 'Y-m-d H:i:s' );
		EventDatesTable::upsert( $post_id, $future_datetime );

		$request = $this->calendar_request();
		$request->set_param( 'event_search', $unique_term );
		$request->set_param( 'format', 'data' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $post_id ), array_column( $response->get_data()['events'], 'id' ) );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	public function test_calendar_endpoint_accepts_date_range() {
		$request = $this->calendar_request();
		$date    = current_datetime()->modify( '+1 month' );
		$request->set_param( 'date_start', $date->format( 'Y-m-01' ) );
		$request->set_param( 'date_end', $date->format( 'Y-m-t' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_calendar_endpoint_accepts_pagination() {
		$request = $this->calendar_request();
		$request->set_param( 'paged', 1 );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	public function test_calendar_data_response_uses_canonical_empty_state(): void {
		$request = $this->calendar_request();
		$request->set_param( 'format', 'data' );
		$request->set_param( 'month', '2999-12' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 4, $data['schema']['version'] );
		$this->assertSame( array(), $data['grouping']['ordered_dates'] );
		$this->assertStringContainsString( 'data-machine-events-no-events', $data['empty_html'] );
		$this->assertStringContainsString( 'data-machine-events-no-events-today-link', $data['empty_html'] );
	}

	public function test_filters_endpoint_returns_taxonomies() {
		$request  = new WP_REST_Request( 'GET', '/' . API_NAMESPACE . '/events/filters' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	public function test_venues_check_duplicate_requires_auth() {
		// Test that non-authenticated requests are rejected
		$request = new WP_REST_Request( 'GET', '/' . API_NAMESPACE . '/events/venues/check-duplicate' );
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

		$request = new WP_REST_Request( 'GET', '/' . API_NAMESPACE . '/events/venues/check-duplicate' );
		$request->set_param( 'name', 'Non Existent Venue ' . uniqid() );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'is_duplicate', $data );
		$this->assertFalse( $data['is_duplicate'] );

	}
}
