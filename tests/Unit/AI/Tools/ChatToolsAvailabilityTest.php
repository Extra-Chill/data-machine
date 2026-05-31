<?php
/**
 * Tests for tool availability in chat context.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

class ChatToolsAvailabilityTest extends WP_UnitTestCase {

	private ToolPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();

		// Ensure Data Machine capabilities are assigned to roles.
		datamachine_register_capabilities();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->resolver = new ToolPolicyResolver();
	}

	public function test_chat_tools_exclude_pipeline_editing_tools(): void {
		// Pipeline-editing tools (update_flow et al.) moved from the generic
		// chat mode to the dedicated pipeline_editor mode so portable chat
		// surfaces (frontend widget, bridge) no longer inherit them.
		// See data-machine#2425.
		$tools = $this->resolver->resolve( [
			'mode'     => ToolPolicyResolver::MODE_CHAT,
		] );

		$this->assertIsArray( $tools );
		$this->assertArrayNotHasKey( 'update_flow', $tools );
		$this->assertArrayNotHasKey( 'create_pipeline', $tools );
		$this->assertArrayNotHasKey( 'run_flow', $tools );
		$this->assertArrayNotHasKey( 'execute_workflow', $tools );
	}

	public function test_pipeline_editor_tools_include_update_flow(): void {
		// The pipeline-editing tools live on the pipeline_editor mode now; the
		// DM admin pipeline chat opts into [chat, pipeline_editor].
		$tools = $this->resolver->resolve( [
			'mode'     => 'pipeline_editor',
		] );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'update_flow', $tools );
		$this->assertIsArray( $tools['update_flow'] );
		$this->assertArrayHasKey( 'class', $tools['update_flow'] );
		$this->assertArrayHasKey( 'method', $tools['update_flow'] );
	}

	public function test_chat_tools_include_web_fetch(): void {
		$tools = $this->resolver->resolve( [
			'mode'     => ToolPolicyResolver::MODE_CHAT,
		] );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
		$this->assertIsArray( $tools['web_fetch'] );
		$this->assertArrayHasKey( 'class', $tools['web_fetch'] );
		$this->assertArrayHasKey( 'method', $tools['web_fetch'] );
	}
}
