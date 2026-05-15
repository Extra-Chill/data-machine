<?php
/**
 * Tool Policy Resolver
 *
 * Single entry point for determining which tools are available for any
 * execution context. Composes tools from registered sources, filters by
 * context (pipeline/chat/system), then applies per-agent tool policies.
 *
 * Resolution precedence (highest to lowest):
 * 1. Explicit deny list (always wins)
 * 2. Per-agent tool policy (deny/allow mode from agent_config, supports categories)
 * 3. Ability category filter (narrows tools by their linked ability's category)
 * 4. Context-level allow_only (narrows to explicit subset)
 * 5. Context preset (pipeline/chat/system)
 * 6. Global enablement settings
 * 7. Tool configuration requirements
 *
 * @package DataMachine\Engine\AI\Tools
 * @since 0.39.0
 */

namespace DataMachine\Engine\AI\Tools;

use DataMachine\Engine\AI\Tools\Policy\DataMachineAgentToolPolicyProvider;
use DataMachine\Engine\AI\Tools\Policy\DataMachineMandatoryToolPolicy;
use DataMachine\Engine\AI\Tools\Policy\DataMachineToolAccessPolicy;

defined( 'ABSPATH' ) || exit;

class ToolPolicyResolver {

	/**
	 * Agent mode presets define which tool pools are available.
	 */
	public const MODE_PIPELINE = 'pipeline';
	public const MODE_CHAT     = 'chat';
	public const MODE_SYSTEM   = 'system';

	private ToolManager $tool_manager;
	private ToolSourceRegistry $tool_source_registry;
	private DataMachineAgentToolPolicyProvider $agent_policy_provider;
	private DataMachineMandatoryToolPolicy $mandatory_tool_policy;
	private DataMachineToolAccessPolicy $tool_access_policy;
	private \WP_Agent_Tool_Policy $tool_policy;
	private \WP_Agent_Tool_Policy_Filter $policy_filter;

	public function __construct(
		?ToolManager $tool_manager = null,
		?DataMachineAgentToolPolicyProvider $agent_policy_provider = null,
		?DataMachineMandatoryToolPolicy $mandatory_tool_policy = null,
		?DataMachineToolAccessPolicy $tool_access_policy = null,
		?\WP_Agent_Tool_Policy_Filter $policy_filter = null,
		?\WP_Agent_Tool_Policy $tool_policy = null
	) {
		$this->tool_manager           = $tool_manager ?? new ToolManager();
		$this->tool_source_registry   = new ToolSourceRegistry( $this->tool_manager );
		$this->agent_policy_provider  = $agent_policy_provider ?? new DataMachineAgentToolPolicyProvider();
		$this->mandatory_tool_policy  = $mandatory_tool_policy ?? new DataMachineMandatoryToolPolicy();
		$this->tool_access_policy     = $tool_access_policy ?? new DataMachineToolAccessPolicy();
		$this->policy_filter          = $policy_filter ?? new \WP_Agent_Tool_Policy_Filter();
		$this->tool_policy            = $tool_policy ?? new \WP_Agent_Tool_Policy(
			array(
				$this->mandatory_tool_policy,
			),
			$this->policy_filter
		);
	}

	/**
	 * Resolve available tools for given agent modes.
	 *
	 * This is the single entry point. All tool assembly should go through here.
	 *
	 * @param array $args {
	 *     Resolution arguments describing the request.
	 *
	 *     @type string[]    $modes                 Active agent mode slugs.
	 *     @type int|null    $agent_id              Agent ID for per-agent tool policy filtering.
	 *     @type array|null  $previous_step_config  Pipeline only: previous step config.
	 *     @type array|null  $next_step_config      Pipeline only: next step config.
	 *     @type string|null $pipeline_step_id      Pipeline only: current pipeline step ID for per-step filtering.
	 *     @type array       $engine_data           Engine data snapshot for dynamic tool generation.
	 *     @type array       $deny                  Tool names to explicitly deny (highest precedence).
	 *     @type array       $allow_only            If set, only these tools are allowed (allowlist mode).
	 *     @type array       $categories            If set, only tools whose linked ability belongs to one
	 *                                              of these categories are included. Empty = no filtering.
	 *     @type string|null $cache_scope           Scope key for tool cache (e.g. flow_step_id).
	 * }
	 * @return array Resolved tools array keyed by tool name.
	 */
	public function resolve( array $args ): array {
		$modes    = self::normalizeModes( $args['modes'] ?? ( array_key_exists( 'mode', $args ) ? array( $args['mode'] ) : array( self::MODE_PIPELINE ) ) );
		$agent_id = isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0;

		$is_interactive = in_array( self::MODE_CHAT, $modes, true ) || ! empty( $args['interactive'] );

		if ( $is_interactive && ! $this->tool_access_policy->passesChatGate( $args ) ) {
			return array();
		}

		// 1. Gather tools from Data Machine-owned sources.
		$args['modes'] = $modes;
		$tools         = $this->gatherByModes( $modes, $args );

		// 2. Delegate generic mode/allow/deny/category policy resolution to Agents API.
		$policy_context = array_merge(
			$args,
			array(
				'mode'              => '',
				'modes'             => $modes,
				'datamachine_tools' => $tools,
			)
		);

		$agent_policy = $agent_id > 0 ? $this->getAgentToolPolicy( $agent_id ) : null;
		if ( null !== $agent_policy ) {
			$policy_context['agent_config'] = array( 'tool_policy' => $agent_policy );
		} else {
			unset( $policy_context['agent_id'], $policy_context['agent_slug'] );
		}

		// Interactive modes are request-user scoped. Pipeline/system run as product automation.
		if ( $is_interactive ) {
			$policy_context['tool_access_checker'] = array( $this->tool_access_policy, 'canAccessTool' );
		}

		$tools = $this->tool_policy->resolve( $tools, $policy_context );

		// 7. Allow external filtering of resolved tools.
		// @phpstan-ignore-next-line WordPress apply_filters accepts additional hook arguments.
		$filter_mode = 1 === count( $modes ) ? $modes[0] : $modes;
		$tools       = apply_filters( 'datamachine_resolved_tools', $tools, $filter_mode, $args );

		return $tools;
	}

	/**
	 * Gather tools by mode preset.
	 *
	 * @param array $modes Agent mode slugs.
	 * @param array  $args Full resolution arguments.
	 * @return array Tools array.
	 */
	private function gatherByModes( array $modes, array $args ): array {
		return $this->tool_source_registry->gather( $modes, $args );
	}

	/**
	 * Get tool policy from an agent's config.
	 *
	 * Reads the `tool_policy` key from the agent's `agent_config` JSON.
	 * Returns null if the agent doesn't exist or has no tool policy configured.
	 *
	 * @since 0.42.0
	 * @param int $agent_id Agent ID.
	 * @return array|null Tool policy array with 'mode' and 'tools' keys, or null.
	 */
	public function getAgentToolPolicy( int $agent_id ): ?array {
		return $this->agent_policy_provider->getForAgent( $agent_id );
	}

	/**
	 * Apply an agent's tool policy to a set of resolved tools.
	 *
	 * - `deny` mode: agent can use everything EXCEPT listed tools/categories.
	 * - `allow` mode: agent can ONLY use listed tools/categories.
	 * - No policy (null): no restrictions (backward compatible).
	 *
	 * The policy supports both individual tool names (`tools` key) and ability
	 * categories (`categories` key). When both are present, they compose:
	 * - allow mode: tool passes if it matches a tool name OR a category.
	 * - deny mode: tool is excluded if it matches a tool name OR a category.
	 *
	 * @since 0.42.0
	 * @since 0.55.0 Added category support in tool policies.
	 *
	 * @param array      $tools  Resolved tools array keyed by tool name.
	 * @param array|null $policy Tool policy from getAgentToolPolicy(), or null for no restrictions.
	 * @return array Filtered tools array.
	 */
	public function applyAgentPolicy( array $tools, ?array $policy ): array {
		return $this->policy_filter->apply_named_policy(
			$tools,
			$policy,
			fn( array $tool, string $name ): bool => $this->mandatory_tool_policy->isMandatory( $tool )
		);
	}

	/**
	 * Apply an allow-only list while preserving adjacent handler tools.
	 *
	 * @param array $tools      Tool definitions keyed by tool name.
	 * @param array $allow_only Optional/global tool names to allow.
	 * @return array Filtered tools.
	 */
	private function filterByAllowOnlyPreservingHandlerTools( array $tools, array $allow_only ): array {
		return $this->policy_filter->filter_by_allow_only(
			$tools,
			$allow_only,
			fn( array $tool, string $name ): bool => $this->mandatory_tool_policy->isMandatory( $tool )
		);
	}

	/**
	 * Get available agent mode presets.
	 *
	 * @return array<string, string> Mode slug => description.
	 */
	public static function getModes(): array {
		return array(
			self::MODE_PIPELINE => 'Pipeline execution with handler tools from adjacent steps',
			self::MODE_CHAT     => 'Chat interaction with full management tools',
			self::MODE_SYSTEM   => 'System task execution with minimal toolset',
		);
	}

	/**
	 * Normalize active mode input to sanitized slugs.
	 *
	 * @param mixed $modes Raw mode input.
	 * @return array<int,string>
	 */
	public static function normalizeModes( mixed $modes ): array {
		if ( is_string( $modes ) ) {
			$modes = array( $modes );
		}
		if ( ! is_array( $modes ) ) {
			$modes = array();
		}

		$normalized = array();
		foreach ( $modes as $mode ) {
			if ( ! is_scalar( $mode ) ) {
				continue;
			}
			$mode = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $mode ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $mode ) ?? '' );
			if ( '' !== $mode ) {
				$normalized[] = $mode;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		return ! empty( $normalized ) ? $normalized : array( self::MODE_PIPELINE );
	}

	/**
	 * Whether an explicitly opt-in tool is available to this run.
	 *
	 * @param array  $tool_config Tool definition.
	 * @param string $tool_name   Tool identifier.
	 * @param array  $args        Resolver args, including optional allow_only.
	 * @return bool True when the tool should be included.
	 */
	public static function isOptInToolAllowed( array $tool_config, string $tool_name, array $args ): bool {
		return empty( $tool_config['requires_opt_in'] )
			|| in_array( $tool_name, $args['allow_only'] ?? array(), true );
	}
}
