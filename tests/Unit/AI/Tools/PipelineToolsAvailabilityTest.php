<?php
/**
 * Tests for tool availability in pipeline-step context.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

class PipelineToolsAvailabilityTest extends WP_UnitTestCase {

	private Pipelines $pipelines;
	private ToolPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->pipelines = new Pipelines();
		$this->resolver  = new ToolPolicyResolver();
	}

	public function test_pipeline_step_tools_respect_disabled_tools(): void {
		$pipeline_id = $this->pipelines->create_pipeline( [
			'pipeline_name' => 'Tool Gate Pipeline',
			'pipeline_config' => [],
		] );

		$this->assertIsInt( $pipeline_id );
		$this->assertGreaterThan( 0, $pipeline_id );

		$pipeline_step_id = $pipeline_id . '_test-step-uuid';

		$pipeline_config = [
			$pipeline_step_id => [
				'step_type' => 'fetch',
				'disabled_tools' => [],
			],
		];

		$updated = $this->pipelines->update_pipeline( $pipeline_id, [
			'pipeline_config' => $pipeline_config,
		] );
		$this->assertTrue( $updated );

		$tools_without_disabled = $this->resolver->resolve( [
			'mode'              => ToolPolicyResolver::MODE_PIPELINE,
			'pipeline_step_id' => $pipeline_step_id,
		] );
		$this->assertIsArray( $tools_without_disabled );
		$this->assertArrayHasKey( 'web_fetch', $tools_without_disabled );

		$pipeline_config[ $pipeline_step_id ]['disabled_tools'] = [ 'web_fetch' ];
		$updated_again = $this->pipelines->update_pipeline( $pipeline_id, [
			'pipeline_config' => $pipeline_config,
		] );
		$this->assertTrue( $updated_again );

		$tools_with_disabled = $this->resolver->resolve( [
			'mode'              => ToolPolicyResolver::MODE_PIPELINE,
			'pipeline_step_id' => $pipeline_step_id,
			'deny'             => [ 'web_fetch' ],
		] );
		$this->assertIsArray( $tools_with_disabled );
		$this->assertArrayNotHasKey( 'web_fetch', $tools_with_disabled );
	}

	public function test_pipeline_step_tools_do_not_include_chat_tools(): void {
		$pipeline_id = $this->pipelines->create_pipeline( [
			'pipeline_name' => 'No Chat Tools Pipeline',
			'pipeline_config' => [],
		] );

		$this->assertIsInt( $pipeline_id );
		$this->assertGreaterThan( 0, $pipeline_id );

		$pipeline_step_id = $pipeline_id . '_test-step-uuid';

		$pipeline_config = [
			$pipeline_step_id => [
				'step_type' => 'fetch',
				'disabled_tools' => [ 'web_fetch' ],
			],
		];

		$updated = $this->pipelines->update_pipeline( $pipeline_id, [
			'pipeline_config' => $pipeline_config,
		] );
		$this->assertTrue( $updated );

		$tools = $this->resolver->resolve( [
			'mode'              => ToolPolicyResolver::MODE_PIPELINE,
			'pipeline_step_id' => $pipeline_step_id,
		] );
		$this->assertIsArray( $tools );
		$this->assertArrayNotHasKey( 'update_flow', $tools );
	}
}
