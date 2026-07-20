<?php
/**
 * Action Policy Resolver
 *
 * Determines HOW a tool invocation is allowed to execute. Sibling to
 * ToolPolicyResolver (which decides IF a tool is visible) and
 * MemoryPolicyResolver (which decides WHICH memory files inject). Where
 * ToolPolicy answers "can the agent see this tool?", WP_Agent_Action_Policy answers
 * "having called it, does it execute directly, stage for user approval,
 * or get refused?"
 *
 * Returned policy is one of:
 *
 *   - 'direct'    Execute immediately (default; no behavioral change).
 *   - 'preview'   Stage the invocation via PendingActionStore and return
 *                 a user-confirmation envelope instead of calling the
 *                 underlying handler.
 *   - 'forbidden' Refuse the invocation with an error.
 *
 * Resolution precedence (highest to lowest):
 *
 * 1. Explicit `deny` list in context (any listed tool → 'forbidden').
 * 2. Per-agent `action_policy.tools[<tool_name>]` override.
 * 3. Per-agent `action_policy.categories[<category>]` override.
 * 4. Tool-declared default (`tool_def['action_policy']`) when present.
 * 5. Mode preset (chat defaults publish-family to 'preview', pipeline
 *    and system default to 'direct' since there is no user to confirm).
 * 6. Global default: 'direct'.
 * 7. `datamachine_tool_action_policy` filter (always runs last).
 *
 * @package DataMachine\Engine\AI\Actions
 * @since   0.72.0
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Tools\WP_Agent_Action_Policy;

defined( 'ABSPATH' ) || exit;

class ActionPolicyResolver {

	/**
	 * Agent mode presets, aligned with ToolPolicyResolver and
	 * MemoryPolicyResolver for consistency.
	 */
	public const MODE_PIPELINE = 'pipeline';
	public const MODE_CHAT     = 'chat';
	public const MODE_SYSTEM   = 'system';

	/**
	 * Valid policy values. Returned by resolveForTool().
	 *
	 * Keep the legacy Data Machine constant names as public aliases while the
	 * generic vocabulary lives in Agents API.
	 */
	public const POLICY_DIRECT    = WP_Agent_Action_Policy::DIRECT;
	public const POLICY_PREVIEW   = WP_Agent_Action_Policy::PREVIEW;
	public const POLICY_FORBIDDEN = WP_Agent_Action_Policy::FORBIDDEN;

	private \WP_Agent_Action_Policy_Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param \WP_Agent_Action_Policy_Resolver|null $resolver Agents API action policy resolver.
	 */
	public function __construct( ?\WP_Agent_Action_Policy_Resolver $resolver = null ) {
		$this->resolver = $resolver ?? new \WP_Agent_Action_Policy_Resolver( array( new DataMachineModeActionPolicyProvider() ) );
	}

	/**
	 * Resolve the action policy for a single tool invocation.
	 *
	 * @param array $context {
	 *     Resolution context.
	 *
	 *     @type string     $tool_name      Required. The tool being invoked.
	 *     @type string     $mode           Required. Agent mode (chat/pipeline/system).
	 *     @type array      $tool_def       Optional. Tool definition (to read `action_policy` default).
	 *     @type array      $input          Optional. Normalized invocation input.
	 *     @type int|null   $agent_id       Optional. Acting agent ID for per-agent overrides.
	 *     @type array      $client_context Optional. Client-supplied runtime context.
	 *     @type string[]   $deny           Optional. Tools to forcibly forbid in this call.
	 * }
	 * @return string One of the POLICY_* constants.
	 */
	public function resolveForTool( array $context ): string {
		$context['input'] = is_array( $context['input'] ?? null ) ? $context['input'] : array();
		$context = $this->withAgentConfig( $context );
		$policy  = $this->resolver->resolve_for_tool( $context );

		return $this->applyDataMachineFilter( $policy, (string) ( $context['tool_name'] ?? '' ), (string) ( $context['mode'] ?? self::MODE_CHAT ), $context );
	}

	/**
	 * Read an agent's action_policy from agent_config.
	 *
	 * Returns null when the agent does not exist, has no policy, or the
	 * policy is structurally invalid. Mirrors the null-for-no-op pattern
	 * used by ToolPolicyResolver and MemoryPolicyResolver.
	 *
	 * Policy shape:
	 *
	 *   array(
	 *       'tools'      => array( 'publish_instagram' => 'preview' ),
	 *       'categories' => array( 'datamachine-socials' => 'preview' ),
	 *   )
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null { tools?: map, categories?: map } or null.
	 */
	public function getAgentActionPolicy( int $agent_id ): ?array {
		$config = $this->getAgentConfig( $agent_id );
		if ( empty( $config['action_policy'] ) || ! is_array( $config['action_policy'] ) ) {
			return null;
		}

		return empty( $config['action_policy']['tools'] ?? array() ) && empty( $config['action_policy']['categories'] ?? array() )
			? null
			: $config['action_policy'];
	}

	/**
	 * Add persisted Data Machine agent config to the Agents API resolver context.
	 *
	 * @param array $context Resolution context.
	 * @return array Context with agent_config when available.
	 */
	private function withAgentConfig( array $context ): array {
		if ( is_array( $context['agent_config'] ?? null ) ) {
			return $context;
		}

		$agent_id = isset( $context['agent_id'] ) ? (int) $context['agent_id'] : 0;
		$config   = $this->getAgentConfig( $agent_id );
		if ( ! empty( $config ) ) {
			$context['agent_config'] = $config;
		} else {
			// Preserve canonical agent identity while preventing fallback registry lookups.
			$context['agent_config'] = array();
		}

		return $context;
	}

	/**
	 * Read persisted Data Machine agent configuration.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array Agent config.
	 */
	private function getAgentConfig( int $agent_id ): array {
		if ( $agent_id <= 0 ) {
			return array();
		}

		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		return is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
	}

	/**
	 * Apply the Data Machine product filter after Agents API resolves policy.
	 *
	 * @param string $policy    Policy computed by Agents API.
	 * @param string $tool_name Tool being resolved.
	 * @param string $mode      Agent mode.
	 * @param array  $context   Full resolution context.
	 * @return string Filtered policy.
	 */
	private function applyDataMachineFilter( string $policy, string $tool_name, string $mode, array $context ): string {
		$filtered = apply_filters( 'datamachine_tool_action_policy', $policy, $tool_name, $mode, $context );

		return WP_Agent_Action_Policy::normalize( $filtered ) ?? $policy;
	}

	/**
	 * Available agent mode presets.
	 *
	 * @return array<string, string>
	 */
	public static function getModes(): array {
		return array(
			self::MODE_CHAT     => 'Admin chat session (users present, preview-friendly)',
			self::MODE_PIPELINE => 'Pipeline step execution (no user, direct-only)',
			self::MODE_SYSTEM   => 'System task execution (no user, direct-only)',
		);
	}
}
