<?php
/**
 * Flows REST API Tests
 *
 * Tests for flows REST endpoint with ability integration.
 *
 * @package DataMachine\Tests\Unit\Api\Flows
 */

namespace DataMachine\Tests\Unit\Api\Flows;

use DataMachine\Api\Flows\Flows;
use WP_UnitTestCase;
use WP_REST_Request;

class FlowsEndpointTest extends WP_UnitTestCase {

	private int $test_pipeline_id;
	private int $test_flow_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($user_id);

		$pipeline_ability = wp_get_ability( 'datamachine/create-pipeline' );
		$flow_ability     = wp_get_ability( 'datamachine/create-flow' );

		$pipeline = $pipeline_ability->execute( [ 'pipeline_name' => 'Test Pipeline for REST' ] );
		$this->test_pipeline_id = $pipeline['pipeline_id'];

		$flow = $flow_ability->execute( [ 'pipeline_id' => $this->test_pipeline_id, 'flow_name' => 'Test Flow for REST' ] );
		$this->test_flow_id = $flow['flow_id'];
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_get_all_flows(): void {
		$request = new WP_REST_Request('GET', '/datamachine/v1/flows');

		$response = Flows::handle_get_flows($request);

		$this->assertSame(200, $response->get_status());
		$data = $response->get_data();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('data', $data);
		$this->assertIsArray($data['data']);
		$this->assertGreaterThan(0, count($data['data']));
	}

	public function test_get_flows_for_pipeline(): void {
		$request = new WP_REST_Request('GET', '/datamachine/v1/flows');
		$request->set_param('pipeline_id', $this->test_pipeline_id);

		$response = Flows::handle_get_flows($request);

		$this->assertSame(200, $response->get_status());
		$data = $response->get_data();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('data', $data);
		$this->assertArrayHasKey('pipeline_id', $data['data']);
		$this->assertEquals($this->test_pipeline_id, $data['data']['pipeline_id']);
		$this->assertGreaterThan(0, count($data['data']['flows']));
	}

	public function test_pagination(): void {
		$request = new WP_REST_Request('GET', '/datamachine/v1/flows');
		$request->set_param('pipeline_id', $this->test_pipeline_id);
		$request->set_param('per_page', 1);
		$request->set_param('offset', 0);

		$response = Flows::handle_get_flows($request);

		$this->assertSame(200, $response->get_status());
		$data = $response->get_data();
		$this->assertTrue($data['success']);
		$this->assertEquals(1, count($data['data']['flows']));
		$this->assertEquals(1, $data['per_page']);
		$this->assertEquals(0, $data['offset']);
	}

	public function test_get_flows_accepts_output_mode(): void {
		$request = new WP_REST_Request('GET', '/datamachine/v1/flows');
		$request->set_param('pipeline_id', $this->test_pipeline_id);
		$request->set_param('output_mode', 'ids');

		$response = Flows::handle_get_flows($request);

		$this->assertSame(200, $response->get_status());
		$data = $response->get_data();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('flows', $data['data']);
		$this->assertContains($this->test_flow_id, $data['data']['flows']);
		$this->assertContainsOnly('int', $data['data']['flows']);
	}

	public function test_response_structure(): void {
		$request = new WP_REST_Request('GET', '/datamachine/v1/flows');

		$response = Flows::handle_get_flows($request);

		$this->assertSame(200, $response->get_status());
		$data = $response->get_data();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('data', $data);
		$this->assertArrayHasKey('total', $data);
		$this->assertArrayHasKey('per_page', $data);
		$this->assertArrayHasKey('offset', $data);

		$flow = $data['data'][0] ?? $data['data']['flows'][0] ?? null;
		if ($flow) {
			$this->assertArrayHasKey('flow_id', $flow);
			$this->assertArrayHasKey('flow_name', $flow);
			$this->assertArrayHasKey('pipeline_id', $flow);
			$this->assertArrayHasKey('flow_config', $flow);
			$this->assertArrayHasKey('scheduling_config', $flow);
			$this->assertArrayHasKey('last_run', $flow);
			$this->assertArrayHasKey('last_run_status', $flow);
			$this->assertArrayHasKey('next_run', $flow);
			$this->assertArrayHasKey('last_run_display', $flow);
			$this->assertArrayHasKey('next_run_display', $flow);
		}
	}

	public function test_ability_integration(): void {
		$ability = wp_get_ability('datamachine/get-flows');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/get-flows', $ability->get_name());
	}

	public function test_permission_denied_for_non_admin(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$request = new \WP_REST_Request( 'GET', '/datamachine/v1/flows' );

		$response = Flows::handle_get_flows( $request );

		// execute() returns WP_Error when permission denied — handler passes it through
		$this->assertInstanceOf( \WP_Error::class, $response );
	}
}
