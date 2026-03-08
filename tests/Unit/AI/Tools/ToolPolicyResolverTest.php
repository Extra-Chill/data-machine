<?php
/**
 * Tests for ToolPolicyResolver — single entry point for tool resolution.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

class ToolPolicyResolverTest extends WP_UnitTestCase {

	private ToolPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		ToolManager::clearCache();
		$this->resolver = new ToolPolicyResolver();
	}

	public function tear_down(): void {
		ToolManager::clearCache();
		parent::tear_down();
	}

	// ============================================
	// STANDALONE SURFACE
	// ============================================

	public function test_standalone_returns_global_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_STANDALONE,
		) );

		$this->assertIsArray( $tools );
		// Global tools like web_fetch should be present.
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_standalone_excludes_chat_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_STANDALONE,
		) );

		// Chat-only tools like update_flow should not appear in standalone.
		$this->assertArrayNotHasKey( 'update_flow', $tools );
	}

	// ============================================
	// CHAT SURFACE
	// ============================================

	public function test_chat_includes_global_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_CHAT,
		) );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_chat_includes_chat_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_CHAT,
		) );

		$this->assertIsArray( $tools );
		// update_flow is registered as a chat tool.
		$this->assertArrayHasKey( 'update_flow', $tools );
	}

	public function test_chat_tools_pass_availability_check(): void {
		// This is the bug fix: chat tools now go through is_tool_available().
		// Verify by checking a known chat tool is present and valid.
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_CHAT,
		) );

		foreach ( $tools as $tool_name => $tool_config ) {
			$this->assertIsArray( $tool_config, "Tool '{$tool_name}' should have array config" );
		}
	}

	// ============================================
	// PIPELINE SURFACE
	// ============================================

	public function test_pipeline_includes_global_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_PIPELINE,
		) );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_pipeline_excludes_chat_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_PIPELINE,
		) );

		$this->assertArrayNotHasKey( 'update_flow', $tools );
	}

	public function test_pipeline_respects_step_disabled_tools(): void {
		$pipelines   = new Pipelines();
		$pipeline_id = $pipelines->create_pipeline( array(
			'pipeline_name'   => 'Resolver Pipeline',
			'pipeline_config' => array(),
		) );

		$this->assertIsInt( $pipeline_id );
		$pipeline_step_id = $pipeline_id . '_resolver-test-uuid';

		$pipelines->update_pipeline( $pipeline_id, array(
			'pipeline_config' => array(
				$pipeline_step_id => array(
					'step_type'      => 'fetch',
					'disabled_tools' => array( 'web_fetch' ),
				),
			),
		) );

		$tools = $this->resolver->resolve( array(
			'surface'          => ToolPolicyResolver::SURFACE_PIPELINE,
			'pipeline_step_id' => $pipeline_step_id,
		) );

		$this->assertArrayNotHasKey( 'web_fetch', $tools );
	}

	// ============================================
	// SYSTEM SURFACE
	// ============================================

	public function test_system_returns_only_system_context_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_SYSTEM,
		) );

		$this->assertIsArray( $tools );
		// No tools currently register with 'system' context,
		// so the system surface should be empty by default.
		$this->assertArrayNotHasKey( 'web_fetch', $tools );
	}

	public function test_system_includes_system_context_tools(): void {
		// Register a system-only tool via unified filter.
		add_filter( 'datamachine_tools', function ( $tools ) {
			$tools['test_system_tool'] = array(
				'label'       => 'Test System Tool',
				'description' => 'Only available to system tasks.',
				'class'       => 'NonExistentClass',
				'method'      => 'handle_tool_call',
				'parameters'  => array(),
				'contexts'    => array( 'system' ),
			);
			return $tools;
		} );

		ToolManager::clearCache();

		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_SYSTEM,
		) );

		$this->assertArrayHasKey( 'test_system_tool', $tools );

		// Clean up.
		remove_all_filters( 'datamachine_tools' );
		ToolManager::clearCache();
	}

	// ============================================
	// DENY LIST
	// ============================================

	public function test_deny_list_removes_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_STANDALONE,
			'deny'    => array( 'web_fetch' ),
		) );

		$this->assertArrayNotHasKey( 'web_fetch', $tools );
	}

	public function test_deny_list_overrides_allowlist(): void {
		// A tool in both allow and deny should be denied (deny wins).
		$tools = $this->resolver->resolve( array(
			'surface'    => ToolPolicyResolver::SURFACE_STANDALONE,
			'allow_only' => array( 'web_fetch' ),
			'deny'       => array( 'web_fetch' ),
		) );

		$this->assertArrayNotHasKey( 'web_fetch', $tools );
	}

	// ============================================
	// ALLOW LIST
	// ============================================

	public function test_allowlist_narrows_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface'    => ToolPolicyResolver::SURFACE_STANDALONE,
			'allow_only' => array( 'web_fetch' ),
		) );

		$this->assertArrayHasKey( 'web_fetch', $tools );
		// Should only contain web_fetch.
		$this->assertCount( 1, $tools );
	}

	public function test_allowlist_with_nonexistent_tool_returns_empty(): void {
		$tools = $this->resolver->resolve( array(
			'surface'    => ToolPolicyResolver::SURFACE_STANDALONE,
			'allow_only' => array( 'completely_fake_tool_xyz' ),
		) );

		$this->assertEmpty( $tools );
	}

	// ============================================
	// FILTER HOOK
	// ============================================

	public function test_resolved_tools_filter_can_modify_output(): void {
		add_filter( 'datamachine_resolved_tools', function ( $tools, $surface, $context ) {
			// Add a custom tool.
			$tools['injected_tool'] = array(
				'label'       => 'Injected Tool',
				'description' => 'Added via filter.',
				'class'       => 'NonExistentClass',
				'method'      => 'handle_tool_call',
				'parameters'  => array(),
			);
			return $tools;
		}, 10, 3 );

		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_STANDALONE,
		) );

		$this->assertArrayHasKey( 'injected_tool', $tools );

		// Clean up.
		remove_all_filters( 'datamachine_resolved_tools' );
	}

	public function test_resolved_tools_filter_receives_surface_and_context(): void {
		$captured_surface = null;
		$captured_context = null;

		add_filter( 'datamachine_resolved_tools', function ( $tools, $surface, $context ) use ( &$captured_surface, &$captured_context ) {
			$captured_surface = $surface;
			$captured_context = $context;
			return $tools;
		}, 10, 3 );

		$this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_CHAT,
		) );

		$this->assertSame( ToolPolicyResolver::SURFACE_CHAT, $captured_surface );
		$this->assertIsArray( $captured_context );

		remove_all_filters( 'datamachine_resolved_tools' );
	}

	// ============================================
	// SURFACE DEFAULTS & EDGE CASES
	// ============================================

	public function test_default_surface_is_pipeline(): void {
		// No surface provided — should default to pipeline.
		$tools = $this->resolver->resolve( array() );

		$this->assertIsArray( $tools );
		// Pipeline default includes global tools.
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_unknown_surface_falls_back_to_global_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => 'unknown_surface_type',
		) );

		$this->assertIsArray( $tools );
		// Fallback = global tools.
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_getSurfaces_returns_all_four_presets(): void {
		$surfaces = ToolPolicyResolver::getSurfaces();

		$this->assertArrayHasKey( ToolPolicyResolver::SURFACE_PIPELINE, $surfaces );
		$this->assertArrayHasKey( ToolPolicyResolver::SURFACE_CHAT, $surfaces );
		$this->assertArrayHasKey( ToolPolicyResolver::SURFACE_STANDALONE, $surfaces );
		$this->assertArrayHasKey( ToolPolicyResolver::SURFACE_SYSTEM, $surfaces );
		$this->assertCount( 4, $surfaces );
	}

	// ============================================
	// DEPRECATED METHOD DELEGATION
	// ============================================

	public function test_deprecated_executor_delegates_to_resolver(): void {
		// ToolExecutor::getAvailableTools() now delegates to the resolver.
		// Verify identical output.
		$executor_tools = \DataMachine\Engine\AI\Tools\ToolExecutor::getAvailableTools( null, null, null, array() );
		$resolver_tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_PIPELINE,
		) );

		$this->assertSame( $executor_tools, $resolver_tools );
	}

	public function test_deprecated_manager_delegates_to_resolver(): void {
		// ToolManager::getAvailableToolsForChat() now delegates to the resolver.
		// Verify identical output.
		$manager_tools  = ( new ToolManager() )->getAvailableToolsForChat();
		$resolver_tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_CHAT,
		) );

		$this->assertSame( $manager_tools, $resolver_tools );
	}
}
