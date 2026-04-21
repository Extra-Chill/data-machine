<?php
/**
 * ActionPolicyResolver unit tests.
 *
 * Covers the 6-step resolution precedence:
 *   1. Context-level deny wins over everything else.
 *   2. Per-agent tool override beats per-agent category override.
 *   3. Per-agent category override beats tool-declared default.
 *   4. Tool-declared default beats mode preset.
 *   5. Mode preset can upgrade a direct tool default to preview in chat.
 *   6. Global default is 'direct'.
 *   + External filter always runs last and can override anything.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\Actions
 */

namespace DataMachine\Tests\Unit\Engine\AI\Actions;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Engine\AI\Actions\ActionPolicyResolver;
use WP_UnitTestCase;

class ActionPolicyResolverTest extends WP_UnitTestCase {

	private ActionPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new ActionPolicyResolver();
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		remove_all_filters( 'datamachine_tool_action_policy' );
		parent::tear_down();
	}

	public function test_default_policy_is_direct(): void {
		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'some_tool',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_DIRECT, $policy );
	}

	public function test_tool_declared_default_overrides_global_default(): void {
		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'publish_instagram',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
				'tool_def'  => array( 'action_policy' => 'preview' ),
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_PREVIEW, $policy );
	}

	public function test_mode_preset_upgrades_direct_tool_to_preview_in_chat(): void {
		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'publish_tweet',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
				'tool_def'  => array(
					'action_policy'      => 'direct',
					'action_policy_chat' => 'preview',
				),
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_PREVIEW, $policy );
	}

	public function test_mode_preset_does_not_affect_pipeline(): void {
		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'publish_tweet',
				'mode'      => ActionPolicyResolver::MODE_PIPELINE,
				'tool_def'  => array(
					'action_policy'      => 'direct',
					'action_policy_chat' => 'preview',
				),
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_DIRECT, $policy );
	}

	public function test_context_deny_always_wins(): void {
		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'publish_instagram',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
				'tool_def'  => array( 'action_policy' => 'direct' ),
				'deny'      => array( 'publish_instagram' ),
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_FORBIDDEN, $policy );
	}

	public function test_agent_tool_override_beats_tool_default(): void {
		$agent_id = $this->createAgentWithPolicy(
			array(
				'tools' => array( 'publish_instagram' => 'forbidden' ),
			)
		);

		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'publish_instagram',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
				'tool_def'  => array( 'action_policy' => 'preview' ),
				'agent_id'  => $agent_id,
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_FORBIDDEN, $policy );
	}

	public function test_agent_tool_override_can_downgrade_preview_to_direct(): void {
		$agent_id = $this->createAgentWithPolicy(
			array(
				'tools' => array( 'publish_instagram' => 'direct' ),
			)
		);

		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'publish_instagram',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
				'tool_def'  => array( 'action_policy' => 'preview' ),
				'agent_id'  => $agent_id,
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_DIRECT, $policy );
	}

	public function test_invalid_agent_policy_values_are_dropped(): void {
		$agent_id = $this->createAgentWithPolicy(
			array(
				'tools' => array( 'publish_instagram' => 'bogus_value' ),
			)
		);

		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'publish_instagram',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
				'tool_def'  => array( 'action_policy' => 'preview' ),
				'agent_id'  => $agent_id,
			)
		);

		// Bogus value dropped → falls through to tool-declared default.
		$this->assertSame( ActionPolicyResolver::POLICY_PREVIEW, $policy );
	}

	public function test_filter_can_override_any_layer(): void {
		add_filter(
			'datamachine_tool_action_policy',
			function ( $policy, $tool_name ) {
				return 'publish_instagram' === $tool_name ? 'forbidden' : $policy;
			},
			10,
			2
		);

		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'publish_instagram',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
				'tool_def'  => array( 'action_policy' => 'direct' ),
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_FORBIDDEN, $policy );
	}

	public function test_filter_garbage_return_is_ignored(): void {
		add_filter(
			'datamachine_tool_action_policy',
			fn() => 'not_a_real_policy'
		);

		$policy = $this->resolver->resolveForTool(
			array(
				'tool_name' => 'some_tool',
				'mode'      => ActionPolicyResolver::MODE_CHAT,
				'tool_def'  => array( 'action_policy' => 'preview' ),
			)
		);

		$this->assertSame( ActionPolicyResolver::POLICY_PREVIEW, $policy );
	}

	public function test_no_policy_returns_null_for_missing_agent(): void {
		$this->assertNull( $this->resolver->getAgentActionPolicy( 99999 ) );
	}

	public function test_empty_agent_policy_returns_null(): void {
		$agent_id = $this->createAgentWithPolicy( array() );
		$this->assertNull( $this->resolver->getAgentActionPolicy( $agent_id ) );
	}

	/**
	 * Seed an agent with a specific action_policy config.
	 */
	private function createAgentWithPolicy( array $action_policy ): int {
		$repo     = new Agents();
		$agent_id = $repo->create_if_missing( 'test-agent-' . wp_generate_uuid4(), 'Test Agent', get_current_user_id() );

		if ( ! empty( $action_policy ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->base_prefix . 'datamachine_agents',
				array(
					'agent_config' => wp_json_encode( array( 'action_policy' => $action_policy ) ),
				),
				array( 'agent_id' => $agent_id )
			);
		}

		return $agent_id;
	}
}
