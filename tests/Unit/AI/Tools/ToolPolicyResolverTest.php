<?php
/**
 * Tests for ToolPolicyResolver — single entry point for tool resolution.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Core\Database\Agents\Agents;
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
	// CHAT CONTEXT
	// ============================================

	public function test_chat_includes_global_tools(): void {
		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_chat_includes_chat_tools(): void {
		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'update_flow', $tools );
	}

	public function test_chat_tools_pass_availability_check(): void {
		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		foreach ( $tools as $tool_name => $tool_config ) {
			$this->assertIsArray( $tool_config, "Tool '{$tool_name}' should have array config" );
		}
	}

	public function test_chat_does_not_require_use_tools_cap_by_default(): void {
		add_filter( 'user_has_cap', array( $this, 'deny_all_datamachine_caps' ), 10, 4 );

		add_filter( 'datamachine_tools', function ( $tools ) {
			$tools['test_authenticated_tool'] = array(
				'label'        => 'Test Authenticated Tool',
				'description'  => 'Visible to authenticated users.',
				'class'        => 'NonExistentClass',
				'method'       => 'handle_tool_call',
				'parameters'   => array(),
				'contexts'     => array( 'chat' ),
				'access_level' => 'authenticated',
			);
			return $tools;
		} );

		ToolManager::clearCache();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertArrayHasKey( 'test_authenticated_tool', $tools );

		remove_filter( 'user_has_cap', array( $this, 'deny_all_datamachine_caps' ), 10 );
		remove_all_filters( 'datamachine_tools' );
		wp_set_current_user( 0 );
		ToolManager::clearCache();
	}

	public function test_chat_can_restore_legacy_use_tools_gate_via_filter(): void {
		add_filter( 'user_has_cap', array( $this, 'deny_all_datamachine_caps' ), 10, 4 );
		add_filter( 'datamachine_require_use_tools_for_chat_tools', '__return_true' );

		add_filter( 'datamachine_tools', function ( $tools ) {
			$tools['test_authenticated_tool'] = array(
				'label'        => 'Test Authenticated Tool',
				'description'  => 'Visible to authenticated users.',
				'class'        => 'NonExistentClass',
				'method'       => 'handle_tool_call',
				'parameters'   => array(),
				'contexts'     => array( 'chat' ),
				'access_level' => 'authenticated',
			);
			return $tools;
		} );

		ToolManager::clearCache();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertEmpty( $tools );

		remove_filter( 'user_has_cap', array( $this, 'deny_all_datamachine_caps' ), 10 );
		remove_filter( 'datamachine_require_use_tools_for_chat_tools', '__return_true' );
		remove_all_filters( 'datamachine_tools' );
		wp_set_current_user( 0 );
		ToolManager::clearCache();
	}

	// ============================================
	// PIPELINE CONTEXT
	// ============================================

	public function test_pipeline_includes_global_tools(): void {
		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_PIPELINE,
		) );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_pipeline_excludes_chat_tools(): void {
		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_PIPELINE,
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
			'context'          => ToolPolicyResolver::CONTEXT_PIPELINE,
			'pipeline_step_id' => $pipeline_step_id,
		) );

		$this->assertArrayNotHasKey( 'web_fetch', $tools );
	}

	// ============================================
	// SYSTEM CONTEXT
	// ============================================

	public function test_system_returns_only_system_context_tools(): void {
		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_SYSTEM,
		) );

		$this->assertIsArray( $tools );
		// No tools currently register with 'system' context.
		$this->assertArrayNotHasKey( 'web_fetch', $tools );
	}

	public function test_system_includes_system_context_tools(): void {
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
			'context' => ToolPolicyResolver::CONTEXT_SYSTEM,
		) );

		$this->assertArrayHasKey( 'test_system_tool', $tools );

		remove_all_filters( 'datamachine_tools' );
		ToolManager::clearCache();
	}

	// ============================================
	// DENY LIST
	// ============================================

	public function test_deny_list_removes_tools(): void {
		$tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
			'deny'    => array( 'web_fetch' ),
		) );

		$this->assertArrayNotHasKey( 'web_fetch', $tools );
	}

	public function test_deny_list_overrides_allowlist(): void {
		$tools = $this->resolver->resolve( array(
			'context'    => ToolPolicyResolver::CONTEXT_CHAT,
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
			'context'    => ToolPolicyResolver::CONTEXT_CHAT,
			'allow_only' => array( 'web_fetch' ),
		) );

		$this->assertArrayHasKey( 'web_fetch', $tools );
		$this->assertCount( 1, $tools );
	}

	public function test_allowlist_with_nonexistent_tool_returns_empty(): void {
		$tools = $this->resolver->resolve( array(
			'context'    => ToolPolicyResolver::CONTEXT_CHAT,
			'allow_only' => array( 'completely_fake_tool_xyz' ),
		) );

		$this->assertEmpty( $tools );
	}

	// ============================================
	// FILTER HOOK
	// ============================================

	public function test_resolved_tools_filter_can_modify_output(): void {
		add_filter( 'datamachine_resolved_tools', function ( $tools, $context_type, $context ) {
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
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertArrayHasKey( 'injected_tool', $tools );

		remove_all_filters( 'datamachine_resolved_tools' );
	}

	public function test_resolved_tools_filter_receives_context_type(): void {
		$captured_context_type = null;
		$captured_context      = null;

		add_filter( 'datamachine_resolved_tools', function ( $tools, $context_type, $context ) use ( &$captured_context_type, &$captured_context ) {
			$captured_context_type = $context_type;
			$captured_context      = $context;
			return $tools;
		}, 10, 3 );

		$this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertSame( ToolPolicyResolver::CONTEXT_CHAT, $captured_context_type );
		$this->assertIsArray( $captured_context );

		remove_all_filters( 'datamachine_resolved_tools' );
	}

	// ============================================
	// DEFAULTS & EDGE CASES
	// ============================================

	public function test_default_context_is_pipeline(): void {
		$tools = $this->resolver->resolve( array() );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_unknown_context_returns_empty_tools(): void {
		// Unknown contexts resolve via the generic gatherer — no tools
		// are registered for this context, so the result is empty.
		// This is correct: custom contexts only get tools that explicitly
		// declare them, making the system extensible.
		$tools = $this->resolver->resolve( array(
			'context' => 'unknown_context_type',
		) );

		$this->assertIsArray( $tools );
		$this->assertEmpty( $tools );
	}

	public function test_custom_context_resolves_registered_tools(): void {
		// Third parties can register tools with custom contexts and
		// resolve them through the same path as built-in contexts.
		add_filter( 'datamachine_tools', function ( $tools ) {
			$tools['custom_automation_tool'] = array(
				'label'       => 'Custom Automation Tool',
				'description' => 'Only available in the automation context.',
				'class'       => 'NonExistentClass',
				'method'      => 'handle_tool_call',
				'parameters'  => array(),
				'contexts'    => array( 'automation' ),
			);
			return $tools;
		} );

		ToolManager::clearCache();

		$tools = $this->resolver->resolve( array(
			'context' => 'automation',
		) );

		$this->assertArrayHasKey( 'custom_automation_tool', $tools );

		// Built-in tools that don't declare 'automation' are excluded.
		$this->assertArrayNotHasKey( 'web_fetch', $tools );

		remove_all_filters( 'datamachine_tools' );
		ToolManager::clearCache();
	}

	public function test_getContexts_returns_all_three_presets(): void {
		$contexts = ToolPolicyResolver::getContexts();

		$this->assertArrayHasKey( ToolPolicyResolver::CONTEXT_PIPELINE, $contexts );
		$this->assertArrayHasKey( ToolPolicyResolver::CONTEXT_CHAT, $contexts );
		$this->assertArrayHasKey( ToolPolicyResolver::CONTEXT_SYSTEM, $contexts );
		$this->assertCount( 3, $contexts );
	}

	// ============================================
	// BACKWARD COMPATIBILITY
	// ============================================

	public function test_surface_key_still_works(): void {
		// The deprecated 'surface' key should still resolve correctly.
		$tools = $this->resolver->resolve( array(
			'surface' => ToolPolicyResolver::SURFACE_CHAT,
		) );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
		$this->assertArrayHasKey( 'update_flow', $tools );
	}

	public function test_surface_constants_alias_context_constants(): void {
		$this->assertSame( ToolPolicyResolver::CONTEXT_PIPELINE, ToolPolicyResolver::SURFACE_PIPELINE );
		$this->assertSame( ToolPolicyResolver::CONTEXT_CHAT, ToolPolicyResolver::SURFACE_CHAT );
		$this->assertSame( ToolPolicyResolver::CONTEXT_SYSTEM, ToolPolicyResolver::SURFACE_SYSTEM );
	}

	public function test_getSurfaces_delegates_to_getContexts(): void {
		$this->assertSame( ToolPolicyResolver::getSurfaces(), ToolPolicyResolver::getContexts() );
	}

	// ============================================
	// DEPRECATED METHOD DELEGATION
	// ============================================

	public function test_deprecated_executor_delegates_to_resolver(): void {
		$executor_tools = \DataMachine\Engine\AI\Tools\ToolExecutor::getAvailableTools( null, null, null, array() );
		$resolver_tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_PIPELINE,
		) );

		$this->assertSame( $executor_tools, $resolver_tools );
	}

	public function test_deprecated_manager_delegates_to_resolver(): void {
		$manager_tools  = ( new ToolManager() )->getAvailableToolsForChat();
		$resolver_tools = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertSame( $manager_tools, $resolver_tools );
	}

	// ============================================
	// AGENT TOOL POLICY
	// ============================================

	/**
	 * Helper: create an agent with a given tool_policy in agent_config.
	 *
	 * @param array|null $tool_policy Tool policy array, or null for no policy.
	 * @return int Agent ID.
	 */
	private function createAgentWithPolicy( ?array $tool_policy ): int {
		$agents_repo = new Agents();
		$config      = array();

		if ( null !== $tool_policy ) {
			$config['tool_policy'] = $tool_policy;
		}

		$slug = 'test-agent-' . wp_generate_uuid4();

		return $agents_repo->create_if_missing( $slug, 'Test Agent', 1, $config );
	}

	/**
	 * Deny all Data Machine caps while leaving normal login intact.
	 */
	public function deny_all_datamachine_caps( array $allcaps, array $caps, array $args, $user ): array {
		unset( $args, $user );

		foreach ( array_keys( $allcaps ) as $cap ) {
			if ( str_starts_with( $cap, 'datamachine_' ) ) {
				$allcaps[ $cap ] = false;
			}
		}

		return $allcaps;
	}

	public function test_no_agent_id_means_no_restrictions(): void {
		$tools_without = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$tools_with_zero = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => 0,
		) );

		$this->assertSame( $tools_without, $tools_with_zero );
	}

	public function test_agent_without_policy_no_restrictions(): void {
		$agent_id = $this->createAgentWithPolicy( null );

		$tools_no_agent = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$tools_with_agent = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertSame( $tools_no_agent, $tools_with_agent );
	}

	public function test_agent_deny_mode_removes_listed_tools(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'deny',
			'tools' => array( 'web_fetch' ),
		) );

		$tools = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertArrayNotHasKey( 'web_fetch', $tools );
		// Other tools should still be present.
		$this->assertNotEmpty( $tools );
	}

	public function test_agent_allow_mode_keeps_only_listed_tools(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'allow',
			'tools' => array( 'web_fetch' ),
		) );

		$tools = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertArrayHasKey( 'web_fetch', $tools );
		$this->assertCount( 1, $tools );
	}

	public function test_agent_allow_mode_empty_tools_returns_empty(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'allow',
			'tools' => array(),
		) );

		$tools = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertEmpty( $tools );
	}

	public function test_agent_deny_mode_empty_tools_no_restrictions(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'deny',
			'tools' => array(),
		) );

		$tools_no_agent = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$tools_with_agent = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertSame( $tools_no_agent, $tools_with_agent );
	}

	public function test_nonexistent_agent_id_no_restrictions(): void {
		$tools_no_agent = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$tools_bad_id = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => 999999,
		) );

		$this->assertSame( $tools_no_agent, $tools_bad_id );
	}

	public function test_explicit_deny_overrides_agent_allow_policy(): void {
		// Agent allows only web_fetch, but explicit deny removes it.
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'allow',
			'tools' => array( 'web_fetch' ),
		) );

		$tools = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
			'deny'     => array( 'web_fetch' ),
		) );

		$this->assertEmpty( $tools );
	}

	public function test_agent_policy_applies_to_chat_context(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'deny',
			'tools' => array( 'update_flow' ),
		) );

		$tools = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertArrayNotHasKey( 'update_flow', $tools );
		// Other chat tools should still be present.
		$this->assertArrayHasKey( 'web_fetch', $tools );
	}

	public function test_agent_policy_applies_to_pipeline_context(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'deny',
			'tools' => array( 'web_fetch' ),
		) );

		$tools = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_PIPELINE,
			'agent_id' => $agent_id,
		) );

		$this->assertArrayNotHasKey( 'web_fetch', $tools );
	}

	public function test_agent_invalid_policy_mode_ignored(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'invalid_mode',
			'tools' => array( 'web_fetch' ),
		) );

		$tools_no_agent = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$tools_with_agent = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertSame( $tools_no_agent, $tools_with_agent );
	}

	public function test_agent_malformed_policy_ignored(): void {
		// Missing 'tools' key.
		$agent_id = $this->createAgentWithPolicy( array(
			'mode' => 'deny',
		) );

		$tools_no_agent = $this->resolver->resolve( array(
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		) );

		$tools_with_agent = $this->resolver->resolve( array(
			'context'  => ToolPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertSame( $tools_no_agent, $tools_with_agent );
	}

	public function test_getAgentToolPolicy_returns_valid_policy(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'  => 'deny',
			'tools' => array( 'web_fetch', 'send_ping' ),
		) );

		$policy = $this->resolver->getAgentToolPolicy( $agent_id );

		$this->assertIsArray( $policy );
		$this->assertSame( 'deny', $policy['mode'] );
		$this->assertSame( array( 'web_fetch', 'send_ping' ), $policy['tools'] );
	}

	public function test_getAgentToolPolicy_returns_null_for_no_policy(): void {
		$agent_id = $this->createAgentWithPolicy( null );

		$policy = $this->resolver->getAgentToolPolicy( $agent_id );

		$this->assertNull( $policy );
	}

	public function test_getAgentToolPolicy_returns_null_for_invalid_agent(): void {
		$policy = $this->resolver->getAgentToolPolicy( 999999 );

		$this->assertNull( $policy );
	}

	public function test_applyAgentPolicy_null_returns_unchanged(): void {
		$tools = array( 'tool_a' => array(), 'tool_b' => array() );

		$result = $this->resolver->applyAgentPolicy( $tools, null );

		$this->assertSame( $tools, $result );
	}

	public function test_applyAgentPolicy_deny_removes_tools(): void {
		$tools  = array(
			'tool_a' => array( 'label' => 'A' ),
			'tool_b' => array( 'label' => 'B' ),
			'tool_c' => array( 'label' => 'C' ),
		);
		$policy = array(
			'mode'  => 'deny',
			'tools' => array( 'tool_b' ),
		);

		$result = $this->resolver->applyAgentPolicy( $tools, $policy );

		$this->assertArrayHasKey( 'tool_a', $result );
		$this->assertArrayNotHasKey( 'tool_b', $result );
		$this->assertArrayHasKey( 'tool_c', $result );
	}

	public function test_applyAgentPolicy_allow_keeps_only_listed(): void {
		$tools  = array(
			'tool_a' => array( 'label' => 'A' ),
			'tool_b' => array( 'label' => 'B' ),
			'tool_c' => array( 'label' => 'C' ),
		);
		$policy = array(
			'mode'  => 'allow',
			'tools' => array( 'tool_a', 'tool_c' ),
		);

		$result = $this->resolver->applyAgentPolicy( $tools, $policy );

		$this->assertArrayHasKey( 'tool_a', $result );
		$this->assertArrayNotHasKey( 'tool_b', $result );
		$this->assertArrayHasKey( 'tool_c', $result );
	}
}
