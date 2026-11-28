<?php
/**
 * Geocoding REST Controller Tests
 *
 * Regression guard for data-machine-events#759: the controller treated the
 * `array|WP_Error` return of GeocodingAbilities::executeGeocodeSearch() as an
 * array, so every short-query autocomplete keystroke produced a PHP fatal
 * ("Cannot use object of type WP_Error as array") and a 500 instead of a 400.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use DataMachineEvents\Api\Controllers\Geocoding;
use const DataMachineEvents\Api\API_NAMESPACE;

class GeocodingRestControllerTest extends WP_UnitTestCase {

	protected $server;

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	public function test_geocode_search_endpoint_registered() {
		$this->assertArrayHasKey(
			'/' . API_NAMESPACE . '/events/geocode/search',
			$this->server->get_routes(),
			'Geocode search endpoint should be registered'
		);
	}

	/**
	 * A query shorter than the ability minimum must surface the ability's
	 * WP_Error verbatim — no fatal, no 500, no re-wrapping.
	 */
	public function test_short_query_returns_wp_error_from_controller() {
		$request = new WP_REST_Request( 'GET', '/' . API_NAMESPACE . '/events/geocode/search' );
		$request->set_param( 'query', 'ab' );

		$result = ( new Geocoding() )->search( $request );

		$this->assertWPError( $result, 'Short query should return the ability WP_Error, not fatal.' );
		$this->assertSame( 'invalid_query', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Same path through the REST dispatcher: the route must render 400, which
	 * only happens if the controller hands the WP_Error back untouched.
	 */
	public function test_short_query_dispatches_as_400() {
		$request = new WP_REST_Request( 'GET', '/' . API_NAMESPACE . '/events/geocode/search' );
		$request->set_param( 'query', 'ab' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_query', $response->get_data()['code'] );
	}

	/**
	 * An empty query hits the same guard rather than falling through to the
	 * Nominatim transport.
	 */
	public function test_empty_query_dispatches_as_400() {
		$request = new WP_REST_Request( 'GET', '/' . API_NAMESPACE . '/events/geocode/search' );
		$request->set_param( 'query', '' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
