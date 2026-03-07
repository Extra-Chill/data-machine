<?php
/**
 * List Flows Tool Tests
 *
 * Tests for list_flows chat tool.
 *
 * @package DataMachine\Tests\Unit\Api\Chat\Tools
 */

namespace DataMachine\Tests\Unit\Api\Chat\Tools;

use DataMachine\Api\Chat\Tools\ListFlows;
use WP_UnitTestCase;

class ListFlowsTest extends WP_UnitTestCase {

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

		$pipeline = $pipeline_ability->execute( [ 'pipeline_name' => 'Test Pipeline for Chat' ] );
		$this->test_pipeline_id = $pipeline['pipeline_id'];

		$flow = $flow_ability->execute( [ 'pipeline_id' => $this->test_pipeline_id, 'flow_name' => 'Test Flow for Chat' ] );
		$this->test_flow_id = $flow['flow_id'];
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_tool_registered(): void {
		$tools = apply_filters('datamachine_chat_tools', []);

		$this->assertArrayHasKey('list_flows', $tools);
		$definition = is_callable( $tools['list_flows'] ) ? call_user_func( $tools['list_flows'] ) : $tools['list_flows'];
		$this->assertSame(ListFlows::class, $definition['class']);
	}

	public function test_tool_calls_ability(): void {
		$tool = new ListFlows();
		$ability = wp_get_ability('datamachine/get-flows');

		$this->assertNotNull($ability);

		$result = $tool->handle_tool_call([
			'pipeline_id' => $this->test_pipeline_id,
			'per_page' => 20,
			'offset' => 0
		], []);

		$this->assertIsArray($result);
		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('data', $result);
		$this->assertArrayHasKey('tool_name', $result);
		$this->assertSame('list_flows', $result['tool_name']);
	}

	public function test_tool_passes_parameters(): void {
		$tool = new ListFlows();
		$ability = wp_get_ability('datamachine/get-flows');

		$result = $tool->handle_tool_call([
			'pipeline_id' => $this->test_pipeline_id,
			'per_page' => 10,
			'offset' => 5
		], []);

		$this->assertIsArray($result);
		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('data', $result);
		$this->assertArrayHasKey('flows', $result['data']);
		$this->assertEquals($this->test_pipeline_id, $result['data']['filters_applied']['pipeline_id']);
	}

	public function test_tool_preserves_errors(): void {
		$tool = new ListFlows();

		$result = $tool->handle_tool_call([
			'pipeline_id' => 999999
		], []);

		$this->assertIsArray($result);
		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('data', $result);
		$this->assertEquals(0, count($result['data']['flows']));
	}

	public function test_tool_success_response(): void {
		$tool = new ListFlows();

		$result = $tool->handle_tool_call([], []);

		$this->assertIsArray($result);
		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('data', $result);
		$this->assertArrayHasKey('tool_name', $result);
		$this->assertArrayHasKey('flows', $result['data']);
		$this->assertArrayHasKey('total', $result['data']);
		$this->assertArrayHasKey('per_page', $result['data']);
		$this->assertArrayHasKey('offset', $result['data']);
		$this->assertArrayHasKey('filters_applied', $result['data']);
	}
}
