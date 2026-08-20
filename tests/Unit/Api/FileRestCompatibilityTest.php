<?php
/**
 * Legacy file REST compatibility tests.
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Api\AgentFiles;
use DataMachine\Api\FlowFiles;
use WP_REST_Request;
use WP_UnitTestCase;

class FileRestCompatibilityTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_agent_file_adapter_preserves_public_error_code(): void {
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/files/agent/missing-review-file.txt' );
		$request->set_param( 'filename', 'missing-review-file.txt' );

		$result = AgentFiles::get_agent_file( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'get_agent_file_error', $result->get_error_code() );
		$this->assertSame( 'agent_file_not_found', $result->get_error_data()['ability_error_code'] );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_flow_file_adapter_preserves_public_error_code(): void {
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/files/flow' );
		$request->set_param( 'flow_step_id', 'missing-review-step' );

		$result = FlowFiles::list_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'list_files_error', $result->get_error_code() );
		$this->assertSame( 'flow_file_context_failed', $result->get_error_data()['ability_error_code'] );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}
}
