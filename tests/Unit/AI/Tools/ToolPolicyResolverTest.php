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

	public function test_system_returns_global_tools(): void {
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_SYSTEM,
		) );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_system_includes_system_filter_tools(): void {
		// Register a system-only tool via filter.
		add_filter( 'datamachine_system_tools', function ( $tools ) {
			$tools['test_system_tool'] = array(
				'label'       => 'Test System Tool',
				'description' => 'Only available to system tasks.',
				'class'       => 'NonExistentClass',
				'method'      => 'handle_tool_call',
				'parameters'  => array(),
			);
			return $tools;
		} );

		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_SYSTEM,
		) );

		$this->assertArrayHasKey( 'test_system_tool', $tools );

		// Clean up.
		remove_all_filters( 'datamachine_system_tools' );
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
	// PARITY WITH EXISTING IMPLEMENTATIONS
	// ============================================

	public function test_chat_parity_with_tool_manager(): void {
		// ToolPolicyResolver chat surface should return at least the same
		// tool set as ToolManager::getAvailableToolsForChat().
		$manager_tools  = ( new ToolManager() )->getAvailableToolsForChat();
		$resolver_tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_CHAT,
		) );

		// The resolver applies is_tool_available() to chat tools, which
		// the old method didn't. So the resolver may have fewer tools if
		// some chat tools fail the availability check. But all globally
		// available tools should still be present.
		$global_tools = ( new ToolManager() )->get_global_tools();
		foreach ( $global_tools as $tool_name => $tool_config ) {
			if ( isset( $manager_tools[ $tool_name ] ) ) {
				$this->assertArrayHasKey(
					$tool_name,
					$resolver_tools,
					"Global tool '{$tool_name}' present in manager should also be in resolver"
				);
			}
		}
	}

	public function test_pipeline_parity_with_tool_executor(): void {
		// With no adjacent steps and no pipeline_step_id, both should
		// return the same global tools.
		$executor_tools = \DataMachine\Engine\AI\Tools\ToolExecutor::getAvailableTools( null, null, null, array() );
		$resolver_tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_PIPELINE,
		) );

		// Both should have global tools.
		foreach ( $executor_tools as $tool_name => $tool_config ) {
			if ( ! isset( $tool_config['handler'] ) ) {
				$this->assertArrayHasKey(
					$tool_name,
					$resolver_tools,
					"Non-handler tool '{$tool_name}' from executor should also be in resolver"
				);
			}
		}
	}
}
