<?php
/**
 * Action Policy Resolver
 *
 * Determines HOW a tool invocation is allowed to execute. Sibling to
 * ToolPolicyResolver (which decides IF a tool is visible) and
 * MemoryPolicyResolver (which decides WHICH memory files inject). Where
 * ToolPolicy answers "can the agent see this tool?", ActionPolicy answers
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

use DataMachine\Core\Database\Agents\Agents;

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
	 */
	public const POLICY_DIRECT    = 'direct';
	public const POLICY_PREVIEW   = 'preview';
	public const POLICY_FORBIDDEN = 'forbidden';

	/**
	 * Resolve the action policy for a single tool invocation.
	 *
	 * @param array $context {
	 *     Resolution context.
	 *
	 *     @type string     $tool_name      Required. The tool being invoked.
	 *     @type string     $mode           Required. Agent mode (chat/pipeline/system).
	 *     @type array      $tool_def       Optional. Tool definition (to read `action_policy` default).
	 *     @type int|null   $agent_id       Optional. Acting agent ID for per-agent overrides.
	 *     @type array      $client_context Optional. Client-supplied runtime context.
	 *     @type string[]   $deny           Optional. Tools to forcibly forbid in this call.
	 * }
	 * @return string One of the POLICY_* constants.
	 */
	public function resolveForTool( array $context ): string {
		$tool_name      = (string) ( $context['tool_name'] ?? '' );
		$mode           = (string) ( $context['mode'] ?? self::MODE_CHAT );
		$tool_def       = is_array( $context['tool_def'] ?? null ) ? $context['tool_def'] : array();
		$agent_id       = isset( $context['agent_id'] ) ? (int) $context['agent_id'] : 0;
		$deny           = is_array( $context['deny'] ?? null ) ? $context['deny'] : array();
		$client_context = is_array( $context['client_context'] ?? null ) ? $context['client_context'] : array();

		if ( '' === $tool_name ) {
			return self::POLICY_DIRECT;
		}

		// 1. Explicit deny list always wins.
		if ( in_array( $tool_name, $deny, true ) ) {
			return $this->applyFilter( self::POLICY_FORBIDDEN, $tool_name, $mode, $context );
		}

		// 2 + 3. Per-agent overrides (tool-specific, then category).
		if ( $agent_id > 0 ) {
			$agent_policy = $this->getAgentActionPolicy( $agent_id );

			if ( null !== $agent_policy ) {
				$tool_override = $this->agentToolOverride( $agent_policy, $tool_name );
				if ( null !== $tool_override ) {
					return $this->applyFilter( $tool_override, $tool_name, $mode, $context );
				}

				$category_override = $this->agentCategoryOverride( $agent_policy, $tool_def );
				if ( null !== $category_override ) {
					return $this->applyFilter( $category_override, $tool_name, $mode, $context );
				}
			}
		}

		// 4. Tool-declared default.
		$tool_default = $this->toolDeclaredDefault( $tool_def );
		if ( null !== $tool_default ) {
			// Mode can still upgrade a 'direct' default to 'preview' in chat
			// if the tool opts in via action_policy_chat. Check step 5 first.
			$mode_preset = $this->modePreset( $tool_def, $mode );
			if ( null !== $mode_preset && self::POLICY_PREVIEW === $mode_preset && self::POLICY_DIRECT === $tool_default ) {
				return $this->applyFilter( $mode_preset, $tool_name, $mode, $context );
			}
			return $this->applyFilter( $tool_default, $tool_name, $mode, $context );
		}

		// 5. Mode preset (only meaningful when the tool has opted in).
		$mode_preset = $this->modePreset( $tool_def, $mode );
		if ( null !== $mode_preset ) {
			return $this->applyFilter( $mode_preset, $tool_name, $mode, $context );
		}

		// 6. Global default.
		return $this->applyFilter( self::POLICY_DIRECT, $tool_name, $mode, $context );
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
		if ( $agent_id <= 0 ) {
			return null;
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return null;
		}

		$config = $agent['agent_config'] ?? array();
		if ( empty( $config['action_policy'] ) || ! is_array( $config['action_policy'] ) ) {
			return null;
		}

		$policy = $config['action_policy'];

		$tools      = ( isset( $policy['tools'] ) && is_array( $policy['tools'] ) )
			? array_filter( array_map( array( $this, 'normalizePolicyValue' ), $policy['tools'] ) )
			: array();
		$categories = ( isset( $policy['categories'] ) && is_array( $policy['categories'] ) )
			? array_filter( array_map( array( $this, 'normalizePolicyValue' ), $policy['categories'] ) )
			: array();

		if ( empty( $tools ) && empty( $categories ) ) {
			return null;
		}

		return array(
			'tools'      => $tools,
			'categories' => $categories,
		);
	}

	/**
	 * Look up a per-tool override in an agent policy.
	 *
	 * @param array  $policy    Normalized agent policy.
	 * @param string $tool_name Tool being resolved.
	 * @return string|null Policy value, or null if no override.
	 */
	private function agentToolOverride( array $policy, string $tool_name ): ?string {
		$tools = $policy['tools'] ?? array();
		return isset( $tools[ $tool_name ] ) ? $tools[ $tool_name ] : null;
	}

	/**
	 * Look up a per-category override in an agent policy.
	 *
	 * Walks the tool's linked abilities (`ability` + `abilities`) and
	 * returns the first matching override.
	 *
	 * @param array $policy   Normalized agent policy.
	 * @param array $tool_def Tool definition.
	 * @return string|null Policy value, or null if no override.
	 */
	private function agentCategoryOverride( array $policy, array $tool_def ): ?string {
		$categories = $policy['categories'] ?? array();
		if ( empty( $categories ) ) {
			return null;
		}

		$registry = class_exists( 'WP_Abilities_Registry' ) ? \WP_Abilities_Registry::get_instance() : null;
		if ( ! $registry ) {
			return null;
		}

		$ability_slugs = array();
		if ( ! empty( $tool_def['ability'] ) ) {
			$ability_slugs[] = (string) $tool_def['ability'];
		}
		if ( ! empty( $tool_def['abilities'] ) && is_array( $tool_def['abilities'] ) ) {
			foreach ( $tool_def['abilities'] as $slug ) {
				$ability_slugs[] = (string) $slug;
			}
		}

		foreach ( $ability_slugs as $slug ) {
			$ability = $registry->get_registered( $slug );
			if ( $ability ) {
				$cat = $ability->get_category();
				if ( isset( $categories[ $cat ] ) ) {
					return $categories[ $cat ];
				}
			}
		}

		return null;
	}

	/**
	 * Tool-declared default policy.
	 *
	 * Tools opt into ActionPolicy by declaring `action_policy` in their
	 * definition (returned by BaseTool::getToolDefinition()). Unopted tools
	 * return null and fall through to mode preset / global default.
	 *
	 * @param array $tool_def Tool definition.
	 * @return string|null
	 */
	private function toolDeclaredDefault( array $tool_def ): ?string {
		if ( empty( $tool_def['action_policy'] ) ) {
			return null;
		}
		$value = $this->normalizePolicyValue( $tool_def['action_policy'] );
		return '' === $value ? null : $value;
	}

	/**
	 * Mode preset.
	 *
	 * Tools can declare mode-specific defaults via `action_policy_chat`,
	 * `action_policy_pipeline`, `action_policy_system`. In chat mode this
	 * lets a tool default to 'preview' conversationally while leaving
	 * pipeline execution at 'direct' (no user to confirm).
	 *
	 * @param array  $tool_def Tool definition.
	 * @param string $mode     Agent mode.
	 * @return string|null
	 */
	private function modePreset( array $tool_def, string $mode ): ?string {
		$key = 'action_policy_' . $mode;
		if ( empty( $tool_def[ $key ] ) ) {
			return null;
		}
		$value = $this->normalizePolicyValue( $tool_def[ $key ] );
		return '' === $value ? null : $value;
	}

	/**
	 * Normalize a user-supplied policy value.
	 *
	 * Returns one of the POLICY_* constants, or an empty string when the
	 * value is not recognized (caller drops it).
	 *
	 * @param mixed $value Raw value from config or tool def.
	 * @return string Normalized policy or ''.
	 */
	private function normalizePolicyValue( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtolower( trim( $value ) );
		$allowed = array(
			self::POLICY_DIRECT,
			self::POLICY_PREVIEW,
			self::POLICY_FORBIDDEN,
		);
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Apply the datamachine_tool_action_policy filter and validate the result.
	 *
	 * @param string $policy    Policy computed by the resolver.
	 * @param string $tool_name Tool being resolved.
	 * @param string $mode      Agent mode.
	 * @param array  $context   Full resolution context.
	 * @return string Filtered policy (falls back to computed value if filter returns garbage).
	 */
	private function applyFilter( string $policy, string $tool_name, string $mode, array $context ): string {
		/**
		 * Filter the resolved action policy for a single tool invocation.
		 *
		 * Runs after all layered resolution. Allows plugins to force a
		 * specific policy (e.g. network admin override, audit-mode wrapper)
		 * without touching agent_config.
		 *
		 * @since 0.72.0
		 *
		 * @param string $policy    Computed policy (direct/preview/forbidden).
		 * @param string $tool_name Tool name.
		 * @param string $mode      Agent mode.
		 * @param array  $context   Resolution context.
		 */
		$filtered = apply_filters( 'datamachine_tool_action_policy', $policy, $tool_name, $mode, $context );

		$normalized = $this->normalizePolicyValue( $filtered );
		return '' === $normalized ? $policy : $normalized;
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
