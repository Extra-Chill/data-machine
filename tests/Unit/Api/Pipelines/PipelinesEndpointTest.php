<?php
/**
 * Pipelines REST API tests.
 *
 * @package DataMachine\Tests\Unit\Api\Pipelines
 */

namespace DataMachine\Tests\Unit\Api\Pipelines;

use DataMachine\Api\Pipelines\Pipelines;
use WP_REST_Request;
use WP_UnitTestCase;

class PipelinesEndpointTest extends WP_UnitTestCase {

	public function test_missing_pipeline_preserves_not_found_response(): void {
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/pipelines/999999' );
		$request->set_param( 'pipeline_id', 999999 );

		$response = Pipelines::handle_get_pipelines( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'pipeline_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_csv_export_failure_passes_native_error_through(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/pipelines' );
		$request->set_param( 'format', 'csv' );
		$request->set_param( 'ids', '999999' );

		$response = Pipelines::handle_get_pipelines( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'export_failed', $response->get_error_code() );
		$this->assertSame( 'Export failed', $response->get_error_message() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
	}
}
