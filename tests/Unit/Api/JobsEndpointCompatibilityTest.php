<?php
/**
 * Jobs REST compatibility tests.
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Api\Jobs;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

class JobsEndpointCompatibilityTest extends WP_UnitTestCase {

	public function test_invalid_delete_type_preserves_legacy_controller_error(): void {
		$request = new WP_REST_Request( 'DELETE', '/datamachine/v1/jobs' );
		$request->set_param( 'type', 'invalid' );

		$result = Jobs::handle_clear( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'delete_failed', $result->get_error_code() );
		$this->assertSame( 'type is required and must be "all" or "failed"', $result->get_error_message() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}
