<?php
/**
 * Contract tests for account-level recent comments routing.
 */

namespace DataMachineSocials\Tests\Unit;

use DataMachineSocials\RestApi;
use WP_UnitTestCase;

class RestApiRecentCommentsTest extends WP_UnitTestCase {

	public function test_omitted_media_id_uses_recent_comments_contract(): void {
		$request = new \WP_REST_Request( 'GET', '/datamachine/v1/socials/comments/threads' );
		$request->set_param( 'platform', 'threads' );

		$response = RestApi::get_comments( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'not supported', $data['error'] );
		$this->assertStringNotContainsString( 'media_id is required', $data['error'] );
	}

	public function test_comments_route_does_not_require_media_id(): void {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/datamachine/v1/socials/comments/(?P<platform>[a-z]+)'][0];

		$this->assertFalse( $route['args']['media_id']['required'] );
	}
}
