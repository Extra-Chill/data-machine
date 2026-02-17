<?php
/**
 * FlowAbilities Tests
 *
 * Tests for flow listing ability.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\FlowAbilities;
use WP_UnitTestCase;

class FlowAbilitiesTest extends WP_UnitTestCase {

	private FlowAbilities $flow_abilities;
	private int $test_pipeline_id;
	private int $test_flow_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($user_id);

		$this->flow_abilities = new FlowAbilities();

		$pipeline_ability = wp_get_ability( 'datamachine/create-pipeline' );
		$flow_ability     = wp_get_ability( 'datamachine/create-flow' );

		$pipeline = $pipeline_ability->execute( [ 'pipeline_name' => 'Test Pipeline for Abilities' ] );
		$this->test_pipeline_id = $pipeline['pipeline_id'];

		$flow = $flow_ability->execute( [ 'pipeline_id' => $this->test_pipeline_id, 'flow_name' => 'Test Flow for Abilities' ] );
		$this->test_flow_id = $flow['flow_id'];
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_ability_registered(): void {
		$ability = wp_get_ability('datamachine/get-flows');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/get-flows', $ability->get_name());
	}

	public function test_get_all_flows(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => null,
			'handler_slug' => null,
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertIsArray($result);
		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('flows', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertArrayHasKey('per_page', $result);
		$this->assertArrayHasKey('offset', $result);
		$this->assertArrayHasKey('output_mode', $result);
		$this->assertArrayHasKey('filters_applied', $result);
		$this->assertEquals('full', $result['output_mode']);

		$flows = $result['flows'];
		$this->assertIsArray($flows);
		$this->assertGreaterThan(0, count($flows));

		$first_flow = $flows[0];
		$this->assertArrayHasKey('flow_id', $first_flow);
		$this->assertArrayHasKey('flow_name', $first_flow);
		$this->assertArrayHasKey('pipeline_id', $first_flow);
		$this->assertArrayHasKey('flow_config', $first_flow);
		$this->assertArrayHasKey('scheduling_config', $first_flow);
		$this->assertArrayHasKey('last_run', $first_flow);
		$this->assertArrayHasKey('last_run_status', $first_flow);
		$this->assertArrayHasKey('next_run', $first_flow);
	}

	public function test_get_flows_by_pipeline_id(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $this->test_pipeline_id,
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('flows', $result);

		$flows = $result['flows'];
		$this->assertGreaterThan(0, count($flows));

		foreach ($flows as $flow) {
			$this->assertEquals($this->test_pipeline_id, $flow['pipeline_id']);
		}

		$this->assertEquals($this->test_pipeline_id, $result['filters_applied']['pipeline_id']);
	}

	public function test_get_flows_by_handler_slug(): void {
		$flow_ability = wp_get_ability( 'datamachine/create-flow' );

		$flow = $flow_ability->execute( [
			'pipeline_id'       => $this->test_pipeline_id,
			'flow_name'         => 'RSS Test Flow',
			'scheduling_config' => [ 'interval' => 'manual' ],
		] );

		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $this->test_pipeline_id,
			'handler_slug' => 'rss',
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);

		$flows = $result['flows'];
		$this->assertGreaterThan(0, count($flows));

		$found_test_flow = false;
		foreach ($flows as $flow_data) {
			if ($flow_data['flow_id'] === $flow['flow_id']) {
				$found_test_flow = true;
				break;
			}
		}

		$this->assertTrue($found_test_flow, 'Test flow should be in results when filtered by handler_slug');
		$this->assertEquals('rss', $result['filters_applied']['handler_slug']);
	}

	public function test_handler_slug_any_step_match(): void {
		$pipeline_ability = wp_get_ability( 'datamachine/create-pipeline' );
		$flow_ability     = wp_get_ability( 'datamachine/create-flow' );

		$pipeline = $pipeline_ability->execute( [
			'pipeline_name' => 'Multi-Handler Pipeline',
			'steps'         => [
				[
					'step_type' => 'fetch',
					'label'     => 'Fetch Step',
				],
				[
					'step_type' => 'publish',
					'label'     => 'Publish Step',
				],
			],
		] );

		$flow = $flow_ability->execute( [
			'pipeline_id' => $pipeline['pipeline_id'],
			'flow_name'   => 'Multi-Handler Flow',
			'flow_config' => [
				'step1' => [
					'step_type'        => 'fetch',
					'handler_slugs'    => [ 'rss' ],
					'handler_configs'  => [ 'rss' => [] ],
					'pipeline_step_id' => 'step1',
				],
				'step2' => [
					'step_type'        => 'publish',
					'handler_slugs'    => [ 'wordpress_publish' ],
					'handler_configs'  => [ 'wordpress_publish' => [] ],
					'pipeline_step_id' => 'step2',
				],
			],
		] );

		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $pipeline['pipeline_id'],
			'handler_slug' => 'wordpress_publish',
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertGreaterThan(0, count($result['flows']));

		$found_flow = null;
		foreach ($result['flows'] as $flow_data) {
			if ($flow_data['flow_id'] === $flow['flow_id']) {
				$found_flow = $flow_data;
				break;
			}
		}

		$this->assertNotNull($found_flow);
		$this->assertEquals('Multi-Handler Flow', $found_flow['flow_name']);
	}

	public function test_get_flows_with_pagination(): void {
		$result1 = $this->flow_abilities->executeAbility([
			'per_page' => 1,
			'offset' => 0
		]);

		$result2 = $this->flow_abilities->executeAbility([
			'per_page' => 1,
			'offset' => 1
		]);

		$this->assertTrue($result1['success']);
		$this->assertTrue($result2['success']);

		$this->assertEquals(1, count($result1['flows']));
		$this->assertEquals(1, count($result2['flows']));

		$this->assertEquals(0, $result1['offset']);
		$this->assertEquals(1, $result2['offset']);
	}

	public function test_get_flows_with_both_filters(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $this->test_pipeline_id,
			'handler_slug' => null,
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertGreaterThan(0, count($result['flows']));
	}

	public function test_empty_results(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => 999999,
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertIsArray($result['flows']);
		$this->assertEquals(0, count($result['flows']));
		$this->assertEquals(0, $result['total']);
	}

	public function test_output_mode_full(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $this->test_pipeline_id,
			'output_mode' => 'full',
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('full', $result['output_mode']);
		$this->assertGreaterThan(0, count($result['flows']));

		$first_flow = $result['flows'][0];
		$this->assertArrayHasKey('flow_id', $first_flow);
		$this->assertArrayHasKey('flow_name', $first_flow);
		$this->assertArrayHasKey('pipeline_id', $first_flow);
		$this->assertArrayHasKey('flow_config', $first_flow);
		$this->assertArrayHasKey('scheduling_config', $first_flow);
		$this->assertArrayHasKey('last_run', $first_flow);
		$this->assertArrayHasKey('last_run_status', $first_flow);
		$this->assertArrayHasKey('next_run', $first_flow);
	}

	public function test_output_mode_summary(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $this->test_pipeline_id,
			'output_mode' => 'summary',
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('summary', $result['output_mode']);
		$this->assertGreaterThan(0, count($result['flows']));

		$first_flow = $result['flows'][0];
		$this->assertArrayHasKey('flow_id', $first_flow);
		$this->assertArrayHasKey('flow_name', $first_flow);
		$this->assertArrayHasKey('pipeline_id', $first_flow);
		$this->assertArrayHasKey('last_run_status', $first_flow);
		$this->assertArrayNotHasKey('flow_config', $first_flow);
		$this->assertArrayNotHasKey('scheduling_config', $first_flow);
		$this->assertArrayNotHasKey('next_run', $first_flow);
	}

	public function test_output_mode_ids(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $this->test_pipeline_id,
			'output_mode' => 'ids',
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('ids', $result['output_mode']);
		$this->assertGreaterThan(0, count($result['flows']));

		$first_flow = $result['flows'][0];
		$this->assertIsInt($first_flow);
	}

	public function test_output_mode_default_is_full(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $this->test_pipeline_id,
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('full', $result['output_mode']);
	}

	public function test_output_mode_invalid_defaults_to_full(): void {
		$result = $this->flow_abilities->executeAbility([
			'pipeline_id' => $this->test_pipeline_id,
			'output_mode' => 'invalid_mode',
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('full', $result['output_mode']);
	}

	public function test_single_flow_with_output_modes(): void {
		$result_full = $this->flow_abilities->executeAbility([
			'flow_id' => $this->test_flow_id,
			'output_mode' => 'full'
		]);

		$this->assertTrue($result_full['success']);
		$this->assertEquals('full', $result_full['output_mode']);
		$this->assertEquals(1, count($result_full['flows']));
		$this->assertArrayHasKey('flow_config', $result_full['flows'][0]);

		$result_summary = $this->flow_abilities->executeAbility([
			'flow_id' => $this->test_flow_id,
			'output_mode' => 'summary'
		]);

		$this->assertTrue($result_summary['success']);
		$this->assertEquals('summary', $result_summary['output_mode']);
		$this->assertEquals(1, count($result_summary['flows']));
		$this->assertArrayNotHasKey('flow_config', $result_summary['flows'][0]);

		$result_ids = $this->flow_abilities->executeAbility([
			'flow_id' => $this->test_flow_id,
			'output_mode' => 'ids'
		]);

		$this->assertTrue($result_ids['success']);
		$this->assertEquals('ids', $result_ids['output_mode']);
		$this->assertEquals(1, count($result_ids['flows']));
		$this->assertIsInt($result_ids['flows'][0]);
	}

	public function test_permission_callback(): void {
		wp_set_current_user(0);

		$ability = wp_get_ability('datamachine/get-flows');
		$this->assertNotNull($ability);

		$result = $ability->execute([
			'pipeline_id' => null,
			'per_page' => 20,
			'offset' => 0
		]);

		$this->assertIsArray($result);
		$this->assertFalse($result['success'] ?? true);
		$this->assertArrayHasKey('error', $result);

		$user_id = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($user_id);
	}

	public function test_ability_not_found(): void {
		$ability = wp_get_ability('datamachine/non-existent-ability');
		$this->assertNull($ability);
	}

	public function test_delete_flow_ability_registered(): void {
		$ability = wp_get_ability('datamachine/delete-flow');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/delete-flow', $ability->get_name());
	}

	public function test_delete_flow_with_valid_id_deletes_flow(): void {
		$result = $this->flow_abilities->executeDeleteFlow([
			'flow_id' => $this->test_flow_id
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals($this->test_flow_id, $result['flow_id']);
		$this->assertEquals($this->test_pipeline_id, $result['pipeline_id']);
		$this->assertArrayHasKey('message', $result);

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow = $db_flows->get_flow($this->test_flow_id);
		$this->assertNull($flow);
	}

	public function test_delete_flow_with_invalid_id_returns_error(): void {
		$result = $this->flow_abilities->executeDeleteFlow([
			'flow_id' => 999999
		]);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('not found', $result['error']);
	}

	public function test_delete_flow_with_zero_id_returns_error(): void {
		$result = $this->flow_abilities->executeDeleteFlow([
			'flow_id' => 0
		]);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('positive integer', $result['error']);
	}

	public function test_create_flow_ability_registered(): void {
		$ability = wp_get_ability('datamachine/create-flow');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/create-flow', $ability->get_name());
	}

	public function test_create_flow_with_valid_pipeline_creates_flow(): void {
		$result = $this->flow_abilities->executeCreateFlow([
			'pipeline_id' => $this->test_pipeline_id,
			'flow_name' => 'Test Created Flow'
		]);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('flow_id', $result);
		$this->assertEquals('Test Created Flow', $result['flow_name']);
		$this->assertEquals($this->test_pipeline_id, $result['pipeline_id']);
		$this->assertArrayHasKey('flow_data', $result);
		$this->assertArrayHasKey('synced_steps', $result);
	}

	public function test_create_flow_with_invalid_pipeline_returns_error(): void {
		$result = $this->flow_abilities->executeCreateFlow([
			'pipeline_id' => 999999,
			'flow_name' => 'Should Not Exist'
		]);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('not found', $result['error']);
	}

	public function test_create_flow_defaults_name_to_flow(): void {
		$result = $this->flow_abilities->executeCreateFlow([
			'pipeline_id' => $this->test_pipeline_id
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('Flow', $result['flow_name']);
	}

	public function test_create_flow_with_scheduling_config(): void {
		$result = $this->flow_abilities->executeCreateFlow([
			'pipeline_id' => $this->test_pipeline_id,
			'flow_name' => 'Scheduled Flow',
			'scheduling_config' => ['interval' => 'manual']
		]);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('flow_data', $result);
		$this->assertEquals('manual', $result['flow_data']['scheduling_config']['interval'] ?? null);
	}

	public function test_update_flow_ability_registered(): void {
		$ability = wp_get_ability('datamachine/update-flow');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/update-flow', $ability->get_name());
	}

	public function test_update_flow_updates_name(): void {
		$result = $this->flow_abilities->executeUpdateFlow([
			'flow_id' => $this->test_flow_id,
			'flow_name' => 'Updated Flow Name'
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('Updated Flow Name', $result['flow_name']);
		$this->assertEquals($this->test_flow_id, $result['flow_id']);
	}

	public function test_update_flow_requires_at_least_one_field(): void {
		$result = $this->flow_abilities->executeUpdateFlow([
			'flow_id' => $this->test_flow_id
		]);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('Must provide', $result['error']);
	}

	public function test_update_flow_with_invalid_id_returns_error(): void {
		$result = $this->flow_abilities->executeUpdateFlow([
			'flow_id' => 999999,
			'flow_name' => 'Should Not Update'
		]);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('not found', $result['error']);
	}

	public function test_update_flow_with_empty_name_returns_error(): void {
		$result = $this->flow_abilities->executeUpdateFlow([
			'flow_id' => $this->test_flow_id,
			'flow_name' => ''
		]);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('empty', $result['error']);
	}

	public function test_duplicate_flow_ability_registered(): void {
		$ability = wp_get_ability('datamachine/duplicate-flow');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/duplicate-flow', $ability->get_name());
	}

	public function test_duplicate_flow_same_pipeline(): void {
		$result = $this->flow_abilities->executeDuplicateFlow([
			'source_flow_id' => $this->test_flow_id
		]);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('flow_id', $result);
		$this->assertNotEquals($this->test_flow_id, $result['flow_id']);
		$this->assertEquals($this->test_flow_id, $result['source_flow_id']);
		$this->assertEquals($this->test_pipeline_id, $result['source_pipeline_id']);
		$this->assertEquals($this->test_pipeline_id, $result['target_pipeline_id']);
		$this->assertStringContainsString('Copy of', $result['flow_name']);
	}

	public function test_duplicate_flow_with_custom_name(): void {
		$result = $this->flow_abilities->executeDuplicateFlow([
			'source_flow_id' => $this->test_flow_id,
			'flow_name' => 'My Custom Copy'
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('My Custom Copy', $result['flow_name']);
	}

	public function test_duplicate_flow_with_invalid_source_returns_error(): void {
		$result = $this->flow_abilities->executeDuplicateFlow([
			'source_flow_id' => 999999
		]);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('not found', $result['error']);
	}

	public function test_duplicate_flow_with_invalid_target_pipeline_returns_error(): void {
		$result = $this->flow_abilities->executeDuplicateFlow([
			'source_flow_id' => $this->test_flow_id,
			'target_pipeline_id' => 999999
		]);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('not found', $result['error']);
	}
}
