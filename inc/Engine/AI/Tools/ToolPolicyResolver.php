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
	 * Context presets define which tool pools are available.
	 */
	public const CONTEXT_PIPELINE = 'pipeline';
	public const CONTEXT_CHAT     = 'chat';
	public const CONTEXT_SYSTEM   = 'system';

	/**
	 * @deprecated Use CONTEXT_* constants instead.
	 */
	public const SURFACE_PIPELINE = self::CONTEXT_PIPELINE;
	public const SURFACE_CHAT     = self::CONTEXT_CHAT;
	public const SURFACE_SYSTEM   = self::CONTEXT_SYSTEM;

	private ToolManager $tool_manager;

	public function __construct( ?ToolManager $tool_manager = null ) {
		$this->tool_manager = $tool_manager ?? new ToolManager();
	}

	/**
	 * Resolve available tools for a given execution context.
	 *
	 * This is the single entry point. All tool assembly should go through here.
	 *
	 * @param array $context {
	 *     Execution context describing the request.
	 *
	 *     @type string      $context              Required. One of the CONTEXT_* constants.
	 *     @type string      $surface              Deprecated alias for $context.
	 *     @type int|null    $agent_id              Agent ID for per-agent tool policy filtering.
	 *     @type array|null  $previous_step_config  Pipeline only: previous step config with handler_slugs.
	 *     @type array|null  $next_step_config      Pipeline only: next step config with handler_slugs.
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

	public function resolve( array $context ): array {
		// Accept 'context' key, fall back to deprecated 'surface' key.
		$context_type = $context['context'] ?? $context['surface'] ?? self::CONTEXT_PIPELINE;
		$deny         = $context['deny'] ?? array();
		$agent_id     = isset( $context['agent_id'] ) ? (int) $context['agent_id'] : 0;

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
		$require_use_tools_for_chat = apply_filters( 'datamachine_require_use_tools_for_chat_tools', false, $context );

		if ( self::CONTEXT_CHAT === $context_type && $require_use_tools_for_chat && ! PermissionHelper::can( 'use_tools' ) ) {
			return array();
		}

		// 1. Gather tools based on context preset.
		$tools = $this->gatherByContext( $context_type, $context );

		// 2. Filter by linked ability permissions (from Abilities API).
		//    Only applies in chat context — pipeline/system run as admin.
		if ( self::CONTEXT_CHAT === $context_type ) {
			$tools = $this->filterByAbilityPermissions( $tools );
		}

		// 3. Apply per-agent tool policy (from agent_config).
		if ( $agent_id > 0 ) {
			$agent_policy = $this->getAgentToolPolicy( $agent_id );
			$tools        = $this->applyAgentPolicy( $tools, $agent_policy );
		}

		// 4. Filter by ability categories (narrows to tools whose linked ability
		//    belongs to one of the specified categories).
		$categories = $context['categories'] ?? array();
		if ( ! empty( $categories ) ) {
			$tools = $this->filterByAbilityCategories( $tools, $categories );
		}

		// 5. Apply allowlist if specified (narrows to explicit subset).
		$allow_only = $context['allow_only'] ?? array();
		if ( ! empty( $allow_only ) ) {
			$tools = array_intersect_key( $tools, array_flip( $allow_only ) );
		}

		// 6. Apply deny list (always wins).
		if ( ! empty( $deny ) ) {
			$tools = array_diff_key( $tools, array_flip( $deny ) );
		}

		// 7. Allow external filtering of resolved tools.
		$tools = apply_filters( 'datamachine_resolved_tools', $tools, $context_type, $context );

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
		$registry = function_exists( 'WP_Abilities_Registry' )
			? null
			: ( class_exists( 'WP_Abilities_Registry' ) ? \WP_Abilities_Registry::get_instance() : null );

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
	 * Gather tools by context preset.
	 *
	 * @param string $context_type Context preset string.
	 * @param array  $context      Full execution context.
	 * @return array Tools array.
	 */
	private function gatherByContext( string $context_type, array $context ): array {
		// Pipeline has special handling for adjacent step handler tools.
		if ( self::CONTEXT_PIPELINE === $context_type ) {
			return $this->gatherPipelineTools( $context );
		}

		// All other contexts (chat, system, and any custom contexts) use
		// the generic gatherer — filter tools by their declared contexts.
		return $this->gatherToolsForContext( $context_type );
	}

	/**
	 * Filter resolved tools by context.
	 *
	 * @param array  $tools        Resolved tools array.
	 * @param string $context_type Context string to filter by (e.g. 'chat', 'pipeline').
	 * @return array Filtered tools.
	 */
	private function filterByContext( array $tools, string $context_type ): array {
		return array_filter(
			$tools,
			function ( $tool ) use ( $context_type ) {
				$contexts = $tool['contexts'] ?? array();
				return in_array( $context_type, $contexts, true );
			}
		);
	}

	/**
	 * Pipeline context: context-filtered tools + handler tools from adjacent steps.
	 */
	private function gatherPipelineTools( array $context ): array {
		$available_tools  = array();
		$pipeline_step_id = $context['pipeline_step_id'] ?? null;
		$engine_data      = $context['engine_data'] ?? array();

		// Handler tools from adjacent steps (dynamic, not part of the static registry).
		foreach ( array( $context['previous_step_config'] ?? null, $context['next_step_config'] ?? null ) as $step_config ) {
			if ( ! $step_config ) {
				continue;
			}

			$handler_slugs       = $step_config['handler_slugs'] ?? array();
			$handler_configs_map = $step_config['handler_configs'] ?? array();
			$cache_scope         = $step_config['flow_step_id'] ?? ( $context['cache_scope'] ?? '' );

			foreach ( $handler_slugs as $slug ) {
				$handler_config = $handler_configs_map[ $slug ] ?? array();
				$tools          = apply_filters( 'chubes_ai_tools', array(), $slug, $handler_config, $engine_data );
				$tools          = $this->tool_manager->resolveAllTools( $tools, $cache_scope );

				foreach ( $tools as $tool_name => $tool_config ) {
					if ( ! is_array( $tool_config ) ) {
						continue;
					}
					// Handler tools only included if they match the current handler.
					if ( isset( $tool_config['handler'] ) && $tool_config['handler'] === $slug ) {
						$available_tools[ $tool_name ] = $tool_config;
					}
				}
			}
		}

		// Static registry tools filtered for 'pipeline' context.
		$all_tools      = $this->tool_manager->get_all_tools();
		$pipeline_tools = $this->filterByContext( $all_tools, 'pipeline' );

		foreach ( $pipeline_tools as $tool_name => $tool_config ) {
			if ( is_array( $tool_config ) && $this->tool_manager->is_tool_available( $tool_name, $pipeline_step_id ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		return $available_tools;
	}

	/**
	 * Gather tools for any context string.
	 *
	 * Filters the tool registry by declared contexts, then applies availability
	 * and enablement checks. Works with built-in contexts (chat, system) and
	 * any custom context — third parties can register tools with custom context
	 * strings and resolve them through the same path.
	 *
	 * @param string $context_type Context string to filter by (e.g. 'chat', 'system', 'automation').
	 * @return array Available tools for this context.
	 */
	private function gatherToolsForContext( string $context_type ): array {
		$available_tools = array();

		$all_tools     = $this->tool_manager->get_all_tools();
		$context_tools = $this->filterByContext( $all_tools, $context_type );

		foreach ( $context_tools as $tool_name => $tool_config ) {
			if ( ! is_array( $tool_config ) || empty( $tool_config ) ) {
				continue;
			}

			// Tools with requires_config go through availability checks.
			// Tools without it are always available unless globally disabled.
			if ( ! empty( $tool_config['requires_config'] ) ) {
				if ( ! $this->tool_manager->is_tool_available( $tool_name, null ) ) {
					continue;
				}
			} elseif ( ! $this->tool_manager->is_globally_enabled( $tool_name ) ) {
				continue;
			}

			$available_tools[ $tool_name ] = $tool_config;
		}

		return $available_tools;
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

		$registry        = class_exists( 'WP_Abilities_Registry' ) ? \WP_Abilities_Registry::get_instance() : null;
		$categories_flip = array_flip( $categories );
		$filtered        = array();

		foreach ( $tools as $name => $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			// Handler tools bypass category filtering — they're already scoped
			// by the pipeline engine to adjacent step handlers.
			if ( isset( $tool['handler'] ) && ! isset( $tool['ability'] ) && ! isset( $tool['abilities'] ) ) {
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

		// Validate structure: must have 'mode' and at least 'tools' or 'categories'.
		if ( ! isset( $policy['mode'] ) ) {
			return null;
		}

		// Ensure tools is present and an array (may be empty if only categories are used).
		if ( ! isset( $policy['tools'] ) || ! is_array( $policy['tools'] ) ) {
			$policy['tools'] = array();
		}

		// Normalize categories to an array.
		if ( isset( $policy['categories'] ) && ! is_array( $policy['categories'] ) ) {
			return null;
		}

		// Must have at least tools or categories to be a valid policy.
		if ( empty( $policy['tools'] ) && empty( $policy['categories'] ?? array() ) ) {
			return null;
		}

		// Validate mode is one of the allowed values.
		if ( ! in_array( $policy['mode'], array( 'deny', 'allow' ), true ) ) {
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

		// No tool names and no categories = no restrictions (deny) or no tools (allow).
		if ( empty( $tool_names ) && empty( $policy_categories ) ) {
			return 'allow' === $mode ? array() : $tools;
		}

		// Simple case: no categories, just tool names (original behavior).
		if ( empty( $policy_categories ) ) {
			if ( 'deny' === $mode ) {
				return array_diff_key( $tools, array_flip( $tool_names ) );
			}
			return array_intersect_key( $tools, array_flip( $tool_names ) );
		}

		// Category-aware filtering: check both tool names and categories.
		$registry        = class_exists( 'WP_Abilities_Registry' ) ? \WP_Abilities_Registry::get_instance() : null;
		$tool_names_flip = ! empty( $tool_names ) ? array_flip( $tool_names ) : array();
		$categories_flip = array_flip( $policy_categories );
		$filtered        = array();

		foreach ( $tools as $name => $tool ) {
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

		return $filtered;
	}

	/**
	 * Get available context presets.
	 *
	 * @return array<string, string> Context name => description.
	 */
	public static function getContexts(): array {
		return array(
			self::CONTEXT_PIPELINE => 'Pipeline execution with handler tools from adjacent steps',
			self::CONTEXT_CHAT     => 'Chat interaction with full management tools',
			self::CONTEXT_SYSTEM   => 'System task execution with minimal toolset',
		);
	}

	/**
	 * @deprecated Use getContexts() instead.
	 */
	public static function getSurfaces(): array {
		return self::getContexts();
	}
}
