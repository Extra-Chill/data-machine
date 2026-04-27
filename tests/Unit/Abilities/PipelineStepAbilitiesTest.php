<?php
/**
 * PipelineStepAbilities Tests
 *
 * Tests for pipeline step CRUD abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Pipeline\CreatePipelineAbility;
use DataMachine\Abilities\PipelineStepAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use WP_UnitTestCase;

class PipelineStepAbilitiesTest extends WP_UnitTestCase {

	private PipelineStepAbilities $step_abilities;
	private CreatePipelineAbility $create_pipeline_ability;
	private int $test_pipeline_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->create_pipeline_ability = new CreatePipelineAbility();
		$this->step_abilities     = new PipelineStepAbilities();

		$result                 = $this->create_pipeline_ability->execute(
			array( 'pipeline_name' => 'Test Pipeline for Step Abilities' )
		);
		$this->test_pipeline_id = $result['pipeline_id'];
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_get_pipeline_steps_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-pipeline-steps' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-pipeline-steps', $ability->get_name() );
	}

	public function test_get_pipeline_steps_supports_single_step_lookup(): void {
		$add_result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);
		$pipeline_step_id = $add_result['pipeline_step_id'];

		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_step_id' => $pipeline_step_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertCount( 1, $result['steps'] );
	}

	public function test_add_pipeline_step_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/add-pipeline-step' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/add-pipeline-step', $ability->get_name() );
	}

	public function test_update_pipeline_step_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/update-pipeline-step' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/update-pipeline-step', $ability->get_name() );
	}

	public function test_delete_pipeline_step_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/delete-pipeline-step' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/delete-pipeline-step', $ability->get_name() );
	}

	public function test_reorder_pipeline_steps_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/reorder-pipeline-steps' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/reorder-pipeline-steps', $ability->get_name() );
	}

	public function test_get_pipeline_steps_empty(): void {
		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertArrayHasKey( 'step_count', $result );
		$this->assertEquals( $this->test_pipeline_id, $result['pipeline_id'] );
		$this->assertEquals( 0, $result['step_count'] );
	}

	public function test_get_pipeline_steps_with_steps(): void {
		$add_result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);

		$this->assertTrue( $add_result['success'] );

		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 1, $result['step_count'] );
		$this->assertCount( 1, $result['steps'] );
	}

	public function test_get_pipeline_steps_not_found(): void {
		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_id' => 999999 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_get_pipeline_steps_invalid_id(): void {
		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_id' => 0 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'positive integer', $result['error'] );
	}

	public function test_add_pipeline_step(): void {
		$result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'pipeline_step_id', $result );
		$this->assertArrayHasKey( 'step_type', $result );
		$this->assertArrayHasKey( 'flows_updated', $result );
		$this->assertArrayHasKey( 'flow_step_ids', $result );
		$this->assertEquals( $this->test_pipeline_id, $result['pipeline_id'] );
		$this->assertEquals( 'fetch', $result['step_type'] );
		$this->assertStringStartsWith( $this->test_pipeline_id . '_', $result['pipeline_step_id'] );
	}

	public function test_add_pipeline_step_ai_type(): void {
		$result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'ai', $result['step_type'] );
	}

	public function test_add_pipeline_step_publish_type(): void {
		$result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'publish',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'publish', $result['step_type'] );
	}

	public function test_add_pipeline_step_upsert_type(): void {
		$result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'upsert',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'upsert', $result['step_type'] );
	}

	public function test_add_pipeline_step_with_step_config_seeds_fields(): void {
		$seed_config = array(
			'system_prompt' => 'You are a careful summarizer.',
			'label'         => 'Imported AI Step',
			'provider'      => 'openai',
			'model'         => 'gpt-5.4',
			'custom_field'  => 'preserved',
		);

		$result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
				'step_config' => $seed_config,
			)
		);

		$this->assertTrue( $result['success'] );

		$steps = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_step_id' => $result['pipeline_step_id'] )
		);

		$this->assertTrue( $steps['success'] );
		$this->assertCount( 1, $steps['steps'] );
		$stored = $steps['steps'][0];

		$this->assertSame( 'You are a careful summarizer.', $stored['system_prompt'] );
		$this->assertSame( 'Imported AI Step', $stored['label'] );
		$this->assertSame( 'openai', $stored['provider'] );
		$this->assertSame( 'gpt-5.4', $stored['model'] );
		$this->assertSame( 'preserved', $stored['custom_field'] );
		$this->assertSame( 'ai', $stored['step_type'] );
		$this->assertSame( $result['pipeline_step_id'], $stored['pipeline_step_id'] );
		$this->assertEquals( 0, $stored['execution_order'] );
	}

	public function test_add_pipeline_step_with_step_config_overrides_identity_fields(): void {
		$seed_config = array(
			'pipeline_step_id' => 'legacy-id-from-another-install',
			'execution_order'  => 99,
			'step_type'        => 'publish',
			'label'            => 'Kept Label',
		);

		$result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
				'step_config' => $seed_config,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'fetch', $result['step_type'] );
		$this->assertNotSame( 'legacy-id-from-another-install', $result['pipeline_step_id'] );
		$this->assertStringStartsWith( $this->test_pipeline_id . '_', $result['pipeline_step_id'] );

		$steps  = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_step_id' => $result['pipeline_step_id'] )
		);
		$stored = $steps['steps'][0];

		$this->assertSame( 'fetch', $stored['step_type'] );
		$this->assertSame( 'Kept Label', $stored['label'] );
		$this->assertEquals( 0, $stored['execution_order'] );
		$this->assertNotSame( 'legacy-id-from-another-install', $stored['pipeline_step_id'] );
	}

	public function test_add_pipeline_step_ai_type_without_step_config_uses_defaults(): void {
		$result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		$this->assertTrue( $result['success'] );

		$steps  = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_step_id' => $result['pipeline_step_id'] )
		);
		$stored = $steps['steps'][0];

		$this->assertArrayHasKey( 'provider', $stored );
		$this->assertArrayHasKey( 'model', $stored );
		$this->assertNotEmpty( $stored['provider'] );
		$this->assertNotEmpty( $stored['model'] );
	}

	public function test_add_pipeline_step_invalid_type(): void {
		$result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'invalid_type',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Invalid step_type', $result['error'] );
	}

	public function test_add_pipeline_step_missing_pipeline_id(): void {
		$result = $this->step_abilities->executeAddPipelineStep(
			array( 'step_type' => 'fetch' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'pipeline_id', $result['error'] );
	}

	public function test_add_pipeline_step_missing_step_type(): void {
		$result = $this->step_abilities->executeAddPipelineStep(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'step_type', $result['error'] );
	}

	public function test_get_pipeline_steps_with_step_id_returns_single_step(): void {
		$add_result       = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);
		$pipeline_step_id = $add_result['pipeline_step_id'];

		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_step_id' => $pipeline_step_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertCount( 1, $result['steps'] );
		$this->assertEquals( $pipeline_step_id, $result['steps'][0]['pipeline_step_id'] );
		$this->assertEquals( 'fetch', $result['steps'][0]['step_type'] );
	}

	public function test_get_pipeline_steps_with_invalid_step_id_returns_empty_array(): void {
		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_step_id' => '999999_nonexistent-uuid' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertEmpty( $result['steps'] );
		$this->assertEquals( 0, $result['step_count'] );
	}

	public function test_get_pipeline_steps_with_empty_step_id_returns_error(): void {
		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_step_id' => '' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'non-empty', $result['error'] );
	}

	public function test_update_pipeline_step_system_prompt(): void {
		$add_result       = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);
		$pipeline_step_id = $add_result['pipeline_step_id'];

		$result = $this->step_abilities->executeUpdatePipelineStep(
			array(
				'pipeline_step_id' => $pipeline_step_id,
				'system_prompt'    => 'You are a helpful assistant.',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $pipeline_step_id, $result['pipeline_step_id'] );
		$this->assertStringContainsString( 'system_prompt', $result['message'] );
	}

	public function test_update_pipeline_step_rejects_provider_and_model(): void {
		$add_result       = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);
		$pipeline_step_id = $add_result['pipeline_step_id'];

		// Provider/model are no longer configurable at the pipeline step level.
		// Model resolution is handled by the context system (mode_models setting).
		$result = $this->step_abilities->executeUpdatePipelineStep(
			array(
				'pipeline_step_id' => $pipeline_step_id,
				'provider'         => 'anthropic',
				'model'            => 'claude-sonnet-4-20250514',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'system_prompt or disabled_tools', $result['error'] );
	}

	public function test_update_pipeline_step_no_fields(): void {
		$add_result       = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);
		$pipeline_step_id = $add_result['pipeline_step_id'];

		$result = $this->step_abilities->executeUpdatePipelineStep(
			array( 'pipeline_step_id' => $pipeline_step_id )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'At least one', $result['error'] );
	}

	public function test_update_pipeline_step_not_found(): void {
		$result = $this->step_abilities->executeUpdatePipelineStep(
			array(
				'pipeline_step_id' => '999999_nonexistent-uuid',
				'system_prompt'    => 'Test prompt',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_delete_pipeline_step(): void {
		$add_result       = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);
		$pipeline_step_id = $add_result['pipeline_step_id'];

		$result = $this->step_abilities->executeDeletePipelineStep(
			array(
				'pipeline_id'      => $this->test_pipeline_id,
				'pipeline_step_id' => $pipeline_step_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $this->test_pipeline_id, $result['pipeline_id'] );
		$this->assertEquals( $pipeline_step_id, $result['pipeline_step_id'] );
		$this->assertArrayHasKey( 'affected_flows', $result );

		$get_result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_step_id' => $pipeline_step_id )
		);
		$this->assertTrue( $get_result['success'] );
		$this->assertEmpty( $get_result['steps'] );
	}

	public function test_delete_pipeline_step_not_found(): void {
		$result = $this->step_abilities->executeDeletePipelineStep(
			array(
				'pipeline_id'      => $this->test_pipeline_id,
				'pipeline_step_id' => '999999_nonexistent-uuid',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_delete_pipeline_step_missing_pipeline_id(): void {
		$result = $this->step_abilities->executeDeletePipelineStep(
			array( 'pipeline_step_id' => 'some-step-id' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'pipeline_id', $result['error'] );
	}

	public function test_delete_pipeline_step_missing_step_id(): void {
		$result = $this->step_abilities->executeDeletePipelineStep(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'pipeline_step_id', $result['error'] );
	}

	public function test_reorder_pipeline_steps(): void {
		$step1 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);

		$step2 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		$result = $this->step_abilities->executeReorderPipelineSteps(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_order'  => array(
					array(
						'pipeline_step_id' => $step2['pipeline_step_id'],
						'execution_order'  => 0,
					),
					array(
						'pipeline_step_id' => $step1['pipeline_step_id'],
						'execution_order'  => 1,
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $this->test_pipeline_id, $result['pipeline_id'] );
		$this->assertEquals( 2, $result['step_count'] );
		$this->assertStringContainsString( 'reordered', $result['message'] );
	}

	public function test_reorder_pipeline_steps_normalizes_gapped_orders_and_syncs_flows(): void {
		$step1 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);

		$step2 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		$step3 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'publish',
			)
		);

		$flow_result = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'flow_name'   => 'Flow for gapped reorder sync',
			)
		);
		$this->assertTrue( $flow_result['success'] );

		$result = $this->step_abilities->executeReorderPipelineSteps(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_order'  => array(
					array(
						'pipeline_step_id' => $step3['pipeline_step_id'],
						'execution_order'  => 0,
					),
					array(
						'pipeline_step_id' => $step1['pipeline_step_id'],
						'execution_order'  => 10,
					),
					array(
						'pipeline_step_id' => $step2['pipeline_step_id'],
						'execution_order'  => 20,
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$db_pipelines    = new Pipelines();
		$pipeline_config = $db_pipelines->get_pipeline_config( $this->test_pipeline_id );

		$this->assertSame( array( $step3['pipeline_step_id'], $step1['pipeline_step_id'], $step2['pipeline_step_id'] ), array_keys( $pipeline_config ) );
		$this->assertSame( 0, $pipeline_config[ $step3['pipeline_step_id'] ]['execution_order'] );
		$this->assertSame( 1, $pipeline_config[ $step1['pipeline_step_id'] ]['execution_order'] );
		$this->assertSame( 2, $pipeline_config[ $step2['pipeline_step_id'] ]['execution_order'] );

		$db_flows    = new Flows();
		$flow        = $db_flows->get_flow( $flow_result['flow_id'] );
		$flow_config = $flow['flow_config'];

		$this->assertSame( 0, $flow_config[ $step3['pipeline_step_id'] . '_' . $flow_result['flow_id'] ]['execution_order'] );
		$this->assertSame( 1, $flow_config[ $step1['pipeline_step_id'] . '_' . $flow_result['flow_id'] ]['execution_order'] );
		$this->assertSame( 2, $flow_config[ $step2['pipeline_step_id'] . '_' . $flow_result['flow_id'] ]['execution_order'] );
	}

	public function test_reorder_pipeline_steps_normalizes_duplicate_orders_by_input_order(): void {
		$step1 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);

		$step2 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		$step3 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'publish',
			)
		);

		$result = $this->step_abilities->executeReorderPipelineSteps(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_order'  => array(
					array(
						'pipeline_step_id' => $step2['pipeline_step_id'],
						'execution_order'  => 0,
					),
					array(
						'pipeline_step_id' => $step3['pipeline_step_id'],
						'execution_order'  => 0,
					),
					array(
						'pipeline_step_id' => $step1['pipeline_step_id'],
						'execution_order'  => 1,
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$db_pipelines    = new Pipelines();
		$pipeline_config = $db_pipelines->get_pipeline_config( $this->test_pipeline_id );

		$this->assertSame( 0, $pipeline_config[ $step2['pipeline_step_id'] ]['execution_order'] );
		$this->assertSame( 1, $pipeline_config[ $step3['pipeline_step_id'] ]['execution_order'] );
		$this->assertSame( 2, $pipeline_config[ $step1['pipeline_step_id'] ]['execution_order'] );
	}

	public function test_reorder_pipeline_steps_rejects_duplicate_step_ids(): void {
		$step1 = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);

		$this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		$result = $this->step_abilities->executeReorderPipelineSteps(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_order'  => array(
					array(
						'pipeline_step_id' => $step1['pipeline_step_id'],
						'execution_order'  => 0,
					),
					array(
						'pipeline_step_id' => $step1['pipeline_step_id'],
						'execution_order'  => 1,
					),
				),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'appears more than once', $result['error'] );
	}

	public function test_reorder_pipeline_steps_invalid_format(): void {
		$result = $this->step_abilities->executeReorderPipelineSteps(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_order'  => array( 'invalid' ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'object', $result['error'] );
	}

	public function test_reorder_pipeline_steps_missing_fields(): void {
		$result = $this->step_abilities->executeReorderPipelineSteps(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_order'  => array(
					array( 'pipeline_step_id' => 'test' ),
				),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'execution_order', $result['error'] );
	}

	public function test_reorder_pipeline_steps_empty_array(): void {
		$result = $this->step_abilities->executeReorderPipelineSteps(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_order'  => array(),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_permission_callback(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/get-pipeline-steps' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_multiple_steps_execution_order(): void {
		$this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);

		$this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		$this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'publish',
			)
		);

		$result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_id' => $this->test_pipeline_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 3, $result['step_count'] );

		$steps          = $result['steps'];
		$expected_types = array( 'fetch', 'ai', 'publish' );
		foreach ( $steps as $index => $step ) {
			$this->assertEquals( $expected_types[ $index ], $step['step_type'] );
		}
	}

	public function test_delete_pipeline_step_syncs_to_flows_and_cleans_processed_items(): void {
		// Create a pipeline step
		$add_result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);
		$pipeline_step_id = $add_result['pipeline_step_id'];

		// Create a flow for this pipeline (steps are synced automatically)
		$flow_result = wp_get_ability( 'datamachine/create-flow' )->execute([
			'pipeline_id' => $this->test_pipeline_id,
			'flow_name'   => 'Test Flow for Delete Sync',
		]);
		$flow_id = $flow_result['flow_id'];

		// Verify flow has the step synced
		$db_flows   = new \DataMachine\Core\Database\Flows\Flows();
		$flow       = $db_flows->get_flow($flow_id);
		$flow_step_id = $pipeline_step_id . '_' . $flow_id;
		$this->assertArrayHasKey($flow_step_id, $flow['flow_config']);
		$this->assertEquals($pipeline_step_id, $flow['flow_config'][$flow_step_id]['pipeline_step_id']);

		// Add some processed items for this step
		$processed_items_db = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
		$processed_items_db->add_processed_item($flow_step_id, 'rss', 'test-item-1', 1);
		$processed_items_db->add_processed_item($flow_step_id, 'rss', 'test-item-2', 1);

		// Verify processed items exist
		$this->assertTrue($processed_items_db->has_item_been_processed($flow_step_id, 'rss', 'test-item-1'));
		$this->assertTrue($processed_items_db->has_item_been_processed($flow_step_id, 'rss', 'test-item-2'));

		// Delete the pipeline step
		$delete_result = $this->step_abilities->executeDeletePipelineStep([
			'pipeline_id'      => $this->test_pipeline_id,
			'pipeline_step_id' => $pipeline_step_id,
		]);

		$this->assertTrue($delete_result['success']);
		$this->assertEquals($this->test_pipeline_id, $delete_result['pipeline_id']);
		$this->assertEquals($pipeline_step_id, $delete_result['pipeline_step_id']);

		// Verify pipeline step is gone
		$get_result = $this->step_abilities->executeGetPipelineSteps([
			'pipeline_step_id' => $pipeline_step_id
		]);
		$this->assertTrue($get_result['success']);
		$this->assertEmpty($get_result['steps']);

		// Verify flow config no longer has the step
		$updated_flow = $db_flows->get_flow($flow_id);
		$this->assertArrayNotHasKey($flow_step_id, $updated_flow['flow_config']);

		// Verify processed items are cleaned up
		$this->assertFalse($processed_items_db->has_item_been_processed($flow_step_id, 'rss', 'test-item-1'));
		$this->assertFalse($processed_items_db->has_item_been_processed($flow_step_id, 'rss', 'test-item-2'));
	}
}
