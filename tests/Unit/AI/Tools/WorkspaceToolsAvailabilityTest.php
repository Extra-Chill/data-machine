<?php
/**
 * Tests for workspace global tool availability in chat and pipeline contexts.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

class WorkspaceToolsAvailabilityTest extends WP_UnitTestCase {

	private ToolPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new ToolPolicyResolver();
	}

	/**
	 * Verify chat tool list includes workspace global read tools.
	 */
	public function test_chat_tools_include_workspace_global_read_tools(): void {
		$tools = $this->resolver->resolve( [
			'surface' => ToolPolicyResolver::SURFACE_CHAT,
		] );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'workspace_path', $tools );
		$this->assertArrayHasKey( 'workspace_list', $tools );
		$this->assertArrayHasKey( 'workspace_show', $tools );
		$this->assertArrayHasKey( 'workspace_ls', $tools );
		$this->assertArrayHasKey( 'workspace_read', $tools );
	}

	/**
	 * Verify pipeline tool list includes workspace global read tools.
	 */
	public function test_pipeline_tools_include_workspace_global_read_tools(): void {
		$pipelines   = new Pipelines();
		$pipeline_id = $pipelines->create_pipeline(
			array(
				'pipeline_name'   => 'Workspace Tools Pipeline',
				'pipeline_config' => array(),
			)
		);

		$this->assertIsInt( $pipeline_id );
		$this->assertGreaterThan( 0, $pipeline_id );

		$pipeline_step_id = $pipeline_id . '_workspace-tools-step';
		$updated          = $pipelines->update_pipeline(
			$pipeline_id,
			array(
				'pipeline_config' => array(
					$pipeline_step_id => array(
						'step_type'       => 'fetch',
						'disabled_tools'  => array(),
						'handler_slugs'   => array(),
						'handler_configs' => array(),
					),
				),
			)
		);

		$this->assertTrue( $updated );

		$tools = $this->resolver->resolve( [
			'surface'          => ToolPolicyResolver::SURFACE_PIPELINE,
			'pipeline_step_id' => $pipeline_step_id,
		] );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'workspace_path', $tools );
		$this->assertArrayHasKey( 'workspace_list', $tools );
		$this->assertArrayHasKey( 'workspace_show', $tools );
		$this->assertArrayHasKey( 'workspace_ls', $tools );
		$this->assertArrayHasKey( 'workspace_read', $tools );
	}
}
