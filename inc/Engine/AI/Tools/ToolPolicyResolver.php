<?php
/**
 * Tool Policy Resolver
 *
 * Single entry point for determining which tools are available for any
 * execution context. Reads from the unified `datamachine_tools` registry
 * and filters by context (pipeline/chat/standalone/system).
 *
 * Resolution precedence (highest to lowest):
 * 1. Explicit deny list (always wins)
 * 2. Surface preset (pipeline/chat/standalone/system)
 * 3. Global enablement settings
 * 4. Tool configuration requirements
 *
 * @package DataMachine\Engine\AI\Tools
 * @since 0.39.0
 */

namespace DataMachine\Engine\AI\Tools;

defined( 'ABSPATH' ) || exit;

class ToolPolicyResolver {

	/**
	 * Surface presets define which tool pools are available.
	 */
	public const SURFACE_PIPELINE   = 'pipeline';
	public const SURFACE_CHAT       = 'chat';
	public const SURFACE_STANDALONE = 'standalone';
	public const SURFACE_SYSTEM     = 'system';

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
	 *     @type string      $surface             Required. One of the SURFACE_* constants.
	 *     @type array|null  $previous_step_config Pipeline only: previous step config with handler_slugs.
	 *     @type array|null  $next_step_config     Pipeline only: next step config with handler_slugs.
	 *     @type string|null $pipeline_step_id     Pipeline only: current pipeline step ID for per-step filtering.
	 *     @type array       $engine_data          Engine data snapshot for dynamic tool generation.
	 *     @type array       $deny                 Tool names to explicitly deny (highest precedence).
	 *     @type array       $allow_only           If set, only these tools are allowed (allowlist mode).
	 *     @type string|null $cache_scope          Scope key for tool cache (e.g. flow_step_id).
	 * }
	 * @return array Resolved tools array keyed by tool name.
	 */
	public function resolve( array $context ): array {
		$surface = $context['surface'] ?? self::SURFACE_PIPELINE;
		$deny    = $context['deny'] ?? array();

		// 1. Gather tools based on surface preset.
		$tools = $this->gatherBySurface( $surface, $context );

		// 2. Apply allowlist if specified (narrows to explicit subset).
		$allow_only = $context['allow_only'] ?? array();
		if ( ! empty( $allow_only ) ) {
			$tools = array_intersect_key( $tools, array_flip( $allow_only ) );
		}

		// 3. Apply deny list (always wins).
		if ( ! empty( $deny ) ) {
			$tools = array_diff_key( $tools, array_flip( $deny ) );
		}

		// 4. Allow external filtering of resolved tools.
		$tools = apply_filters( 'datamachine_resolved_tools', $tools, $surface, $context );

		return $tools;
	}

	/**
	 * Gather tools by surface preset.
	 *
	 * @param string $surface Surface preset constant.
	 * @param array  $context Full execution context.
	 * @return array Tools array.
	 */
	private function gatherBySurface( string $surface, array $context ): array {
		return match ( $surface ) {
			self::SURFACE_PIPELINE   => $this->gatherPipelineTools( $context ),
			self::SURFACE_CHAT       => $this->gatherChatTools( $context ),
			self::SURFACE_STANDALONE => $this->gatherStandaloneTools( $context ),
			self::SURFACE_SYSTEM     => $this->gatherSystemTools( $context ),
			default                  => $this->gatherFallbackTools( $context ),
		};
	}

	/**
	 * Filter resolved tools by context.
	 *
	 * @param array  $tools   Resolved tools array.
	 * @param string $context Context string to filter by (e.g. 'chat', 'pipeline').
	 * @return array Filtered tools.
	 */
	private function filterByContext( array $tools, string $context ): array {
		return array_filter(
			$tools,
			function ( $tool ) use ( $context ) {
				$contexts = $tool['contexts'] ?? array();
				return in_array( $context, $contexts, true );
			}
		);
	}

	/**
	 * Pipeline surface: context-filtered tools + handler tools from adjacent steps.
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
	 * Chat surface: all tools with 'chat' context.
	 *
	 * Tools with configuration requirements go through is_tool_available().
	 * Chat-only tools (those without requires_config) are included if they
	 * resolve to a valid definition.
	 */
	private function gatherChatTools( array $context ): array {
		$available_tools = array();

		$all_tools  = $this->tool_manager->get_all_tools();
		$chat_tools = $this->filterByContext( $all_tools, 'chat' );

		foreach ( $chat_tools as $tool_name => $tool_config ) {
			if ( ! is_array( $tool_config ) || empty( $tool_config ) ) {
				continue;
			}

			// Tools with requires_config go through availability checks.
			// Tools without it (chat-only management tools) are always available.
			if ( ! empty( $tool_config['requires_config'] ) ) {
				if ( ! $this->tool_manager->is_tool_available( $tool_name, null ) ) {
					continue;
				}
			} elseif ( ! $this->tool_manager->is_globally_enabled( $tool_name ) ) {
				// Check global enablement for tools that can be disabled.
				// For tools not in the disabled list, is_globally_enabled returns true.
				continue;
			}

			$available_tools[ $tool_name ] = $tool_config;
		}

		return $available_tools;
	}

	/**
	 * Standalone surface: tools with 'standalone' context.
	 *
	 * For standalone jobs that need AI tool access without pipeline context.
	 */
	private function gatherStandaloneTools( array $context ): array {
		$available_tools = array();

		$all_tools        = $this->tool_manager->get_all_tools();
		$standalone_tools = $this->filterByContext( $all_tools, 'standalone' );

		foreach ( $standalone_tools as $tool_name => $tool_config ) {
			if ( is_array( $tool_config ) && $this->tool_manager->is_tool_available( $tool_name, null ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		return $available_tools;
	}

	/**
	 * System surface: tools with 'system' context.
	 *
	 * Only includes tools that system tasks explicitly need.
	 * Today most system tasks call abilities directly, but this provides
	 * the hook point for when system tasks need AI tool access.
	 */
	private function gatherSystemTools( array $context ): array {
		$available_tools = array();

		$all_tools    = $this->tool_manager->get_all_tools();
		$system_tools = $this->filterByContext( $all_tools, 'system' );

		foreach ( $system_tools as $tool_name => $tool_config ) {
			if ( is_array( $tool_config ) && $this->tool_manager->is_tool_available( $tool_name, null ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		return $available_tools;
	}

	/**
	 * Fallback: standalone tools for unknown surfaces.
	 */
	private function gatherFallbackTools( array $context ): array {
		return $this->gatherStandaloneTools( $context );
	}

	/**
	 * Get available surface presets.
	 *
	 * @return array<string, string> Surface name => description.
	 */
	public static function getSurfaces(): array {
		return array(
			self::SURFACE_PIPELINE   => 'Pipeline execution with handler tools from adjacent steps',
			self::SURFACE_CHAT       => 'Chat interaction with full management tools',
			self::SURFACE_STANDALONE => 'Standalone job execution with global tools only',
			self::SURFACE_SYSTEM     => 'System task execution with minimal toolset',
		);
	}
}
