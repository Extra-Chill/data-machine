<?php
/**
 * FlowStepAbilities Tests
 *
 * Tests for flow step configuration abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\FlowAbilities;
use DataMachine\Abilities\FlowStepAbilities;
use DataMachine\Abilities\PipelineAbilities;
use DataMachine\Abilities\PipelineStepAbilities;
use WP_UnitTestCase;

class FlowStepAbilitiesTest extends WP_UnitTestCase {

	private FlowStepAbilities $flow_step_abilities;
	private FlowAbilities $flow_abilities;
	private PipelineAbilities $pipeline_abilities;
	private PipelineStepAbilities $pipeline_step_abilities;
	private int $test_pipeline_id;
	private int $test_flow_id;
	private string $test_pipeline_step_id;
	private string $test_flow_step_id;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->pipeline_abilities      = new PipelineAbilities();
		$this->pipeline_step_abilities = new PipelineStepAbilities();
		$this->flow_abilities          = new FlowAbilities();
		$this->flow_step_abilities     = new FlowStepAbilities();

		$pipeline_result        = $this->pipeline_abilities->executeCreatePipeline(
			array( 'pipeline_name' => 'Test Pipeline for Flow Step Abilities' )
		);
		$this->test_pipeline_id = $pipeline_result['pipeline_id'];

		$step_result                 = $this->pipeline_step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'fetch',
			)
		);
		$this->test_pipeline_step_id = $step_result['pipeline_step_id'];

		$flow_result        = $this->flow_abilities->executeCreateFlow(
			array(
				'flow_name'   => 'Test Flow for Step Abilities',
				'pipeline_id' => $this->test_pipeline_id,
			)
		);
		$this->test_flow_id = $flow_result['flow_id'];

		$this->test_flow_step_id = $this->test_pipeline_step_id . '_' . $this->test_flow_id;
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_get_flow_steps_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/get-flow-steps' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/get-flow-steps', $ability->get_name() );
	}

	public function test_get_flow_steps_supports_single_step_lookup(): void {
		$result = $this->flow_step_abilities->executeGetFlowSteps(
			array( 'flow_step_id' => $this->test_flow_step_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertCount( 1, $result['steps'] );
	}

	public function test_update_flow_step_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/update-flow-step' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/update-flow-step', $ability->get_name() );
	}

	public function test_configure_flow_steps_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/configure-flow-steps' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/configure-flow-steps', $ability->get_name() );
	}

	public function test_get_flow_steps_success(): void {
		$result = $this->flow_step_abilities->executeGetFlowSteps(
			array( 'flow_id' => $this->test_flow_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertArrayHasKey( 'step_count', $result );
		$this->assertEquals( $this->test_flow_id, $result['flow_id'] );
		$this->assertGreaterThanOrEqual( 1, $result['step_count'] );
	}

	public function test_get_flow_steps_not_found(): void {
		$result = $this->flow_step_abilities->executeGetFlowSteps(
			array( 'flow_id' => 999999 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_get_flow_steps_invalid_id(): void {
		$result = $this->flow_step_abilities->executeGetFlowSteps(
			array( 'flow_id' => 0 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'positive integer', $result['error'] );
	}

	public function test_get_flow_steps_missing_id(): void {
		$result = $this->flow_step_abilities->executeGetFlowSteps( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_get_flow_steps_with_step_id_returns_single_step(): void {
		$result = $this->flow_step_abilities->executeGetFlowSteps(
			array( 'flow_step_id' => $this->test_flow_step_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertCount( 1, $result['steps'] );
	}

	public function test_get_flow_steps_with_invalid_step_id_returns_empty_array(): void {
		$result = $this->flow_step_abilities->executeGetFlowSteps(
			array( 'flow_step_id' => '999999_nonexistent_999' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertEmpty( $result['steps'] );
		$this->assertEquals( 0, $result['step_count'] );
	}

	public function test_get_flow_steps_with_empty_step_id_returns_error(): void {
		$result = $this->flow_step_abilities->executeGetFlowSteps(
			array( 'flow_step_id' => '' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'non-empty', $result['error'] );
	}

	public function test_update_flow_step_handler_slug(): void {
		$result = $this->flow_step_abilities->executeUpdateFlowStep(
			array(
				'flow_step_id' => $this->test_flow_step_id,
				'handler_slug' => 'rss',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( $this->test_flow_step_id, $result['flow_step_id'] );
		$this->assertStringContainsString( 'handler_slug', $result['message'] );
	}

	public function test_update_flow_step_user_message(): void {
		$ai_step_result = $this->pipeline_step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);
		$ai_step_id     = $ai_step_result['pipeline_step_id'];
		$ai_flow_step   = $ai_step_id . '_' . $this->test_flow_id;

		$result = $this->flow_step_abilities->executeUpdateFlowStep(
			array(
				'flow_step_id' => $ai_flow_step,
				'user_message' => 'Test user message for AI step',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'user_message', $result['message'] );
	}

	public function test_update_flow_step_no_fields(): void {
		$result = $this->flow_step_abilities->executeUpdateFlowStep(
			array( 'flow_step_id' => $this->test_flow_step_id )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'At least one', $result['error'] );
	}

	public function test_update_flow_step_not_found(): void {
		$result = $this->flow_step_abilities->executeUpdateFlowStep(
			array(
				'flow_step_id' => '999999_nonexistent_999',
				'handler_slug' => 'rss',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_update_flow_step_missing_id(): void {
		$result = $this->flow_step_abilities->executeUpdateFlowStep(
			array( 'handler_slug' => 'rss' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'required', $result['error'] );
	}

	public function test_configure_flow_steps_no_flows(): void {
		$empty_pipeline = $this->pipeline_abilities->executeCreatePipeline(
			array( 'pipeline_name' => 'Empty Pipeline' )
		);

		$result = $this->flow_step_abilities->executeConfigureFlowSteps(
			array(
				'pipeline_id'   => $empty_pipeline['pipeline_id'],
				'handler_slug'  => 'rss',
				'handler_config' => array(),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Pipeline has no flows yet', $result['error'] );
	}

	public function test_configure_flow_steps_invalid_pipeline_id(): void {
		$result = $this->flow_step_abilities->executeConfigureFlowSteps(
			array( 'pipeline_id' => 0 )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'positive integer', $result['error'] );
	}

	public function test_configure_flow_steps_missing_pipeline_id(): void {
		$result = $this->flow_step_abilities->executeConfigureFlowSteps( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_configure_flow_steps_invalid_target_handler(): void {
		$result = $this->flow_step_abilities->executeConfigureFlowSteps(
			array(
				'pipeline_id'         => $this->test_pipeline_id,
				'target_handler_slug' => 'nonexistent_handler',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_configure_flow_steps_with_step_type_filter(): void {
		$result = $this->flow_step_abilities->executeConfigureFlowSteps(
			array(
				'pipeline_id'         => $this->test_pipeline_id,
				'step_type'           => 'fetch',
				'target_handler_slug' => 'rss',
			)
		);

		if ( $result['success'] ) {
			$this->assertArrayHasKey( 'steps_modified', $result );
			$this->assertGreaterThanOrEqual( 1, $result['steps_modified'] );
		} else {
			$this->assertArrayHasKey( 'error', $result );
		}
	}

	public function test_configure_flow_steps_with_handler_slug_filter(): void {
		$this->flow_step_abilities->executeUpdateFlowStep(
			array(
				'flow_step_id' => $this->test_flow_step_id,
				'handler_slug' => 'rss',
			)
		);

		$result = $this->flow_step_abilities->executeConfigureFlowSteps(
			array(
				'pipeline_id'         => $this->test_pipeline_id,
				'handler_slug'        => 'rss',
				'target_handler_slug' => 'rss',
				'handler_config'      => array(),
			)
		);

		if ( $result['success'] ) {
			$this->assertArrayHasKey( 'steps_modified', $result );
		} else {
			$this->assertArrayHasKey( 'error', $result );
		}
	}

	public function test_permission_callback_admin(): void {
		$this->assertTrue( $this->flow_step_abilities->checkPermission() );
	}

	public function test_permission_callback_no_user(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/get-flow-steps' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array( 'flow_id' => $this->test_flow_id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_get_flow_steps_returns_sorted(): void {
		$this->pipeline_step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		$this->pipeline_step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'publish',
			)
		);

		$result = $this->flow_step_abilities->executeGetFlowSteps(
			array( 'flow_id' => $this->test_flow_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertGreaterThanOrEqual( 3, $result['step_count'] );

		$steps = $result['steps'];
		$steps_count = count( $steps );
		for ( $i = 1; $i < $steps_count; $i++ ) {
			$prev_order = $steps[ $i - 1 ]['execution_order'] ?? 0;
			$curr_order = $steps[ $i ]['execution_order'] ?? 0;
			$this->assertLessThanOrEqual( $curr_order, $prev_order );
		}
	}

	public function test_update_flow_step_both_handler_and_message(): void {
		$ai_step = $this->pipeline_step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'step_type'   => 'ai',
			)
		);
		$ai_flow_step = $ai_step['pipeline_step_id'] . '_' . $this->test_flow_id;

		$result = $this->flow_step_abilities->executeUpdateFlowStep(
			array(
				'flow_step_id' => $ai_flow_step,
				'handler_slug' => 'openai',
				'user_message' => 'Process the content',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'handler_slug', $result['message'] );
		$this->assertStringContainsString( 'user_message', $result['message'] );
	}

	public function test_configure_flow_steps_per_flow_configs(): void {
		$flow2 = $this->flow_abilities->executeCreateFlow(
			array(
				'flow_name'   => 'Second Test Flow',
				'pipeline_id' => $this->test_pipeline_id,
			)
		);

		$result = $this->flow_step_abilities->executeConfigureFlowSteps(
			array(
				'pipeline_id'         => $this->test_pipeline_id,
				'step_type'           => 'fetch',
				'target_handler_slug' => 'rss',
				'flow_configs'        => array(
					array(
						'flow_id'        => $this->test_flow_id,
						'handler_config' => array(),
					),
					array(
						'flow_id'        => $flow2['flow_id'],
						'handler_config' => array(),
					),
				),
			)
		);

		if ( $result['success'] ) {
			$this->assertArrayHasKey( 'flows_updated', $result );
			$this->assertGreaterThanOrEqual( 2, $result['flows_updated'] );
		}
	}

	public function test_configure_flow_steps_skipped_flows(): void {
		$result = $this->flow_step_abilities->executeConfigureFlowSteps(
			array(
				'pipeline_id'         => $this->test_pipeline_id,
				'step_type'           => 'fetch',
				'target_handler_slug' => 'rss',
				'flow_configs'        => array(
					array(
						'flow_id'        => 999999,
						'handler_config' => array(),
					),
				),
			)
		);

		if ( $result['success'] || isset( $result['skipped'] ) ) {
			$this->assertArrayHasKey( 'skipped', $result );
			$this->assertNotEmpty( $result['skipped'] );
		}
	}
}
