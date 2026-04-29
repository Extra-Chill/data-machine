<?php
/**
 * Tool Policy Resolver
 *
 * Single entry point for determining which tools are available for any
 * execution context. Reads from the unified `datamachine_tools` registry
 * and filters by context (pipeline/chat/system), then applies
 * per-agent tool policies from agent_config.
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

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Abilities\PermissionHelper;

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

	public function __construct( ?ToolManager $tool_manager = null ) {
		$this->tool_manager         = $tool_manager ?? new ToolManager();
		$this->tool_source_registry = new ToolSourceRegistry( $this->tool_manager );
	}

	/**
	 * Resolve available tools for a given agent mode.
	 *
	 * This is the single entry point. All tool assembly should go through here.
	 *
	 * @param array $args {
	 *     Resolution arguments describing the request.
	 *
	 *     @type string      $mode                  Required. One of the MODE_* constants (or a custom mode slug).
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
	/**
	 * Valid access levels for tools without a linked ability, ordered from
	 * least to most privileged. Used by filterByAccessLevel().
	 *
	 * @since 0.54.0
	 */
	private const ACCESS_LEVELS = array(
		'public'        => '',
		'authenticated' => 'datamachine_chat',
		'author'        => 'datamachine_use_tools',
		'editor'        => 'datamachine_view_logs',
		'admin'         => 'datamachine_manage_settings',
	);

	public function resolve( array $args ): array {
		$mode     = $args['mode'] ?? self::MODE_PIPELINE;
		$deny     = $args['deny'] ?? array();
		$agent_id = isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0;

		// 0. Optional legacy baseline gate.
		//
		// Historically chat tool resolution short-circuited to zero tools when the
		// acting user lacked datamachine_use_tools. That made chat tool access an
		// all-or-nothing switch and blocked lower-privilege tool tiers for regular
		// authenticated users.
		//
		// Going forward, chat tool visibility should be resolved per-tool via:
		// - linked ability permission callbacks
		// - explicit access_level metadata
		// - agent tool policy
		//
		// The legacy behavior is still available behind a filter for installs that
		// want to preserve the coarse gate during migration.
		$require_use_tools_for_chat = apply_filters( 'datamachine_require_use_tools_for_chat_tools', false, $args );

		if ( self::MODE_CHAT === $mode && $require_use_tools_for_chat && ! PermissionHelper::can( 'use_tools' ) ) {
			return array();
		}

		// 1. Gather tools based on mode preset.
		$tools = $this->gatherByMode( $mode, $args );

		// 2. Filter by linked ability permissions (from Abilities API).
		// Only applies in chat mode — pipeline/system run as admin.
		if ( self::MODE_CHAT === $mode ) {
			$tools = $this->filterByAbilityPermissions( $tools );
		}

		// 3. Apply per-agent tool policy (from agent_config).
		// Pipeline handler tools are required flow plumbing derived from adjacent
		// steps, so per-agent allow/deny policy only filters optional tools.
		if ( $agent_id > 0 ) {
			$agent_policy = $this->getAgentToolPolicy( $agent_id );
			$tools        = $this->applyAgentPolicy( $tools, $agent_policy );
		}

		// 4. Filter by ability categories (narrows to tools whose linked ability
		// belongs to one of the specified categories).
		$categories = $args['categories'] ?? array();
		if ( ! empty( $categories ) ) {
			$tools = $this->filterByAbilityCategories( $tools, $categories );
		}

		// 5. Apply allowlist if specified (narrows optional tools to explicit subset).
		$allow_only = $args['allow_only'] ?? array();
		if ( ! empty( $allow_only ) ) {
			$tools = $this->filterByAllowOnlyPreservingHandlerTools( $tools, $allow_only );
		}

		// 6. Apply deny list (always wins).
		if ( ! empty( $deny ) ) {
			$tools = array_diff_key( $tools, array_flip( $deny ) );
		}

		// 7. Allow external filtering of resolved tools.
		$tools = apply_filters( 'datamachine_resolved_tools', $tools, $mode, $args );

		return $tools;
	}

	/**
	 * Filter tools by their linked ability permissions.
	 *
	 * For each tool that declares an `ability` or `abilities` key, checks
	 * the ability's permission_callback via WP_Abilities_Registry. If ANY
	 * linked ability fails the permission check, the tool is removed.
	 *
	 * Tools without a linked ability fall back to their `access_level` field.
	 * Tools with neither default to 'admin' (safe fallback — untagged tools
	 * are admin-only until explicitly categorized).
	 *
	 * @since 0.54.0
	 *
	 * @param array $tools Resolved tools array keyed by tool name.
	 * @return array Filtered tools.
	 */
	private function filterByAbilityPermissions( array $tools ): array {
		$registry = \WP_Abilities_Registry::get_instance();

		$filtered = array();

		foreach ( $tools as $name => $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			// Collect all ability slugs to check.
			$ability_slugs = array();

			if ( ! empty( $tool['ability'] ) ) {
				$ability_slugs[] = $tool['ability'];
			}

			if ( ! empty( $tool['abilities'] ) && is_array( $tool['abilities'] ) ) {
				$ability_slugs = array_merge( $ability_slugs, $tool['abilities'] );
			}

			// Tools with linked abilities: check each ability's permission.
			if ( ! empty( $ability_slugs ) && $registry ) {
				$permitted = true;

				foreach ( $ability_slugs as $slug ) {
					$ability = $registry->get_registered( $slug );

					if ( ! $ability ) {
						// Ability not registered — deny (safe default).
						$permitted = false;
						break;
					}

					if ( ! $ability->check_permissions() ) {
						$permitted = false;
						break;
					}
				}

				if ( $permitted ) {
					$filtered[ $name ] = $tool;
				}

				continue;
			}

			// No linked ability — fall back to access_level.
			$access_level = $tool['access_level'] ?? 'admin';

			if ( $this->checkAccessLevel( $access_level ) ) {
				$filtered[ $name ] = $tool;
			}
		}

		return $filtered;
	}

	/**
	 * Check if the current user meets an access level requirement.
	 *
	 * @since 0.54.0
	 *
	 * @param string $access_level One of: 'public', 'authenticated', 'author', 'editor', 'admin'.
	 * @return bool Whether the current user has sufficient capabilities.
	 */
	private function checkAccessLevel( string $access_level ): bool {
		if ( 'public' === $access_level ) {
			return true;
		}

		// Map access levels to PermissionHelper actions for consistent
		// permission resolution (WP-CLI bypass, manage_options fallback).
		$action_map = array(
			'authenticated' => 'chat',
			'author'        => 'use_tools',
			'editor'        => 'view_logs',
			'admin'         => 'manage_settings',
		);

		$action = $action_map[ $access_level ] ?? 'manage_settings';
		return \DataMachine\Abilities\PermissionHelper::can( $action );
	}

	/**
	 * Gather tools by mode preset.
	 *
	 * @param string $mode Agent mode slug.
	 * @param array  $args Full resolution arguments.
	 * @return array Tools array.
	 */
	private function gatherByMode( string $mode, array $args ): array {
		return $this->tool_source_registry->gather( $mode, $args );
	}

	/**
	 * Filter tools by their linked ability's category.
	 *
	 * For each tool, resolves its ability category via the Abilities API registry.
	 * Only tools whose ability belongs to one of the allowed categories pass through.
	 *
	 * Handler tools (those with a 'handler' key but no 'ability' key) are always
	 * included — they are dynamically scoped by the pipeline engine and should not
	 * be filtered by category.
	 *
	 * Tools without any ability linkage are excluded when category filtering is
	 * active, since they cannot be categorized. To include them, add their names
	 * to the context's `allow_only` list as an escape hatch.
	 *
	 * @since 0.55.0
	 *
	 * @param array    $tools      Resolved tools array keyed by tool name.
	 * @param string[] $categories Allowed category slugs (e.g. 'datamachine-content').
	 * @return array Filtered tools.
	 */
	private function filterByAbilityCategories( array $tools, array $categories ): array {
		if ( empty( $categories ) ) {
			return $tools;
		}

		$registry        = \WP_Abilities_Registry::get_instance();
		$categories_flip = array_flip( $categories );
		$filtered        = array();

		foreach ( $tools as $name => $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			// Handler tools bypass category filtering — they're already scoped
			// by the pipeline engine to adjacent step handlers.
			if ( self::isPipelineHandlerTool( $tool ) ) {
				$filtered[ $name ] = $tool;
				continue;
			}

			// Collect ability slugs from tool metadata.
			$ability_slugs = array();

			if ( ! empty( $tool['ability'] ) ) {
				$ability_slugs[] = $tool['ability'];
			}

			if ( ! empty( $tool['abilities'] ) && is_array( $tool['abilities'] ) ) {
				$ability_slugs = array_merge( $ability_slugs, $tool['abilities'] );
			}

			// No ability linkage — cannot determine category, excluded.
			if ( empty( $ability_slugs ) ) {
				continue;
			}

			// Check if ANY linked ability belongs to an allowed category.
			if ( $registry ) {
				foreach ( $ability_slugs as $slug ) {
					$ability = $registry->get_registered( $slug );

					if ( $ability && isset( $categories_flip[ $ability->get_category() ] ) ) {
						$filtered[ $name ] = $tool;
						break;
					}
				}
			}
		}

		return $filtered;
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
		if ( $agent_id <= 0 ) {
			return null;
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return null;
		}

		$config = $agent['agent_config'] ?? array();

		if ( empty( $config['tool_policy'] ) || ! is_array( $config['tool_policy'] ) ) {
			return null;
		}

		$policy = $config['tool_policy'];

		// Validate structure: must have 'mode'.
		if ( ! isset( $policy['mode'] ) ) {
			return null;
		}

		// Validate mode is one of the allowed values.
		if ( ! in_array( $policy['mode'], array( 'deny', 'allow' ), true ) ) {
			return null;
		}

		// Ensure tools is present and an array (may be empty — see note below).
		if ( ! isset( $policy['tools'] ) || ! is_array( $policy['tools'] ) ) {
			$policy['tools'] = array();
		}

		// Normalize categories: must be an array if present.
		if ( isset( $policy['categories'] ) && ! is_array( $policy['categories'] ) ) {
			return null;
		}

		// An empty tools + empty categories list is only meaningful for allow
		// mode (means "allow nothing"). Deny mode with empty lists is a no-op
		// and we treat it as no policy to avoid a wasted pass through
		// applyAgentPolicy.
		if ( empty( $policy['tools'] ) && empty( $policy['categories'] ?? array() ) && 'allow' !== $policy['mode'] ) {
			return null;
		}

		return $policy;
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
		if ( null === $policy ) {
			return $tools;
		}

		$mode              = $policy['mode'];
		$tool_names        = $policy['tools'] ?? array();
		$policy_categories = $policy['categories'] ?? array();
		$handler_tools     = $this->getPipelineHandlerTools( $tools );
		$optional_tools    = array_diff_key( $tools, $handler_tools );

		// No tool names and no categories = no restrictions (deny) or no optional tools (allow).
		if ( empty( $tool_names ) && empty( $policy_categories ) ) {
			return 'allow' === $mode ? $handler_tools : $tools;
		}

		// Simple case: no categories, just tool names (original behavior).
		if ( empty( $policy_categories ) ) {
			if ( 'deny' === $mode ) {
				return $handler_tools + array_diff_key( $optional_tools, array_flip( $tool_names ) );
			}
			return $handler_tools + array_intersect_key( $optional_tools, array_flip( $tool_names ) );
		}

		// Category-aware filtering: check both tool names and categories.
		$registry        = \WP_Abilities_Registry::get_instance();
		$tool_names_flip = ! empty( $tool_names ) ? array_flip( $tool_names ) : array();
		$categories_flip = array_flip( $policy_categories );
		$filtered        = array();

		foreach ( $optional_tools as $name => $tool ) {
			$matches_tool = isset( $tool_names_flip[ $name ] );
			$matches_cat  = false;

			if ( ! $matches_tool && is_array( $tool ) && $registry ) {
				$ability_slugs = array();

				if ( ! empty( $tool['ability'] ) ) {
					$ability_slugs[] = $tool['ability'];
				}
				if ( ! empty( $tool['abilities'] ) && is_array( $tool['abilities'] ) ) {
					$ability_slugs = array_merge( $ability_slugs, $tool['abilities'] );
				}

				foreach ( $ability_slugs as $slug ) {
					$ability = $registry->get_registered( $slug );
					if ( $ability && isset( $categories_flip[ $ability->get_category() ] ) ) {
						$matches_cat = true;
						break;
					}
				}
			}

			$matches = $matches_tool || $matches_cat;

			if ( 'allow' === $mode && $matches ) {
				$filtered[ $name ] = $tool;
			} elseif ( 'deny' === $mode && ! $matches ) {
				$filtered[ $name ] = $tool;
			}
		}

		return $handler_tools + $filtered;
	}

	/**
	 * Return whether a tool is a pipeline handler tool resolved from adjacency.
	 *
	 * Handler tools are flow plumbing: they are controlled by the adjacent flow
	 * shape and carry handler metadata for completion tracking. Optional/global
	 * tool policy should not remove them.
	 *
	 * @param array $tool Tool definition.
	 * @return bool Whether this is an adjacent handler tool.
	 */
	private static function isPipelineHandlerTool( array $tool ): bool {
		return isset( $tool['handler'] ) && ! isset( $tool['ability'] ) && ! isset( $tool['abilities'] );
	}

	/**
	 * Extract adjacent pipeline handler tools from a resolved tool set.
	 *
	 * @param array $tools Tool definitions keyed by tool name.
	 * @return array Handler tools keyed by tool name.
	 */
	private function getPipelineHandlerTools( array $tools ): array {
		return array_filter(
			$tools,
			static fn( $tool ) => is_array( $tool ) && self::isPipelineHandlerTool( $tool )
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
		$handler_tools  = $this->getPipelineHandlerTools( $tools );
		$optional_tools = array_diff_key( $tools, $handler_tools );

		return $handler_tools + array_intersect_key( $optional_tools, array_flip( $allow_only ) );
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
}
