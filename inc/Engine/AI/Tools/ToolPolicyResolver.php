<?php
/**
 * Tool Policy Resolver
 *
 * Single entry point for determining which tools are available for any
 * execution context. Replaces fragmented tool assembly across ToolExecutor,
 * ToolManager, and ChatOrchestrator with one deterministic resolution path.
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
			default                  => $this->gatherGlobalTools( $context ),
		};
	}

	/**
	 * Pipeline surface: global tools + handler tools from adjacent steps.
	 */
	private function gatherPipelineTools( array $context ): array {
		$available_tools     = array();
		$pipeline_step_id    = $context['pipeline_step_id'] ?? null;
		$engine_data         = $context['engine_data'] ?? array();

		// Handler tools from adjacent steps.
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

		// Global tools filtered for pipeline availability.
		$global_tools = $this->tool_manager->get_global_tools();
		foreach ( $global_tools as $tool_name => $tool_config ) {
			if ( is_array( $tool_config ) && $this->tool_manager->is_tool_available( $tool_name, $pipeline_step_id ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		return $available_tools;
	}

	/**
	 * Chat surface: global tools + chat-specific tools.
	 *
	 * Global tools go through is_tool_available() for enablement/config checks.
	 * Chat-specific tools are included if they have a valid definition — they
	 * register via datamachine_chat_tools and are outside the global enablement
	 * system (is_tool_available() only knows about global tools).
	 */
	private function gatherChatTools( array $context ): array {
		$available_tools = array();

		// Global tools filtered for chat availability.
		$global_tools = $this->tool_manager->get_global_tools();
		foreach ( $global_tools as $tool_name => $tool_config ) {
			if ( $this->tool_manager->is_tool_available( $tool_name, null ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		// Chat-specific tools — included if they resolve to a valid definition.
		// These are outside the global enablement system (they have their own
		// registration path via datamachine_chat_tools filter).
		$raw_chat_tools = apply_filters( 'datamachine_chat_tools', array() );
		$chat_tools     = $this->tool_manager->resolveAllTools( $raw_chat_tools );

		foreach ( $chat_tools as $tool_name => $tool_config ) {
			if ( is_array( $tool_config ) && ! empty( $tool_config ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		return $available_tools;
	}

	/**
	 * Standalone surface: global tools only (no handler or chat tools).
	 *
	 * For standalone jobs that need AI tool access without pipeline context.
	 */
	private function gatherStandaloneTools( array $context ): array {
		$available_tools = array();

		$global_tools = $this->tool_manager->get_global_tools();
		foreach ( $global_tools as $tool_name => $tool_config ) {
			if ( is_array( $tool_config ) && $this->tool_manager->is_tool_available( $tool_name, null ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		return $available_tools;
	}

	/**
	 * System surface: minimal toolset for system tasks.
	 *
	 * Only includes tools that system tasks explicitly need.
	 * Today most system tasks call abilities directly, but this provides
	 * the hook point for when system tasks need AI tool access.
	 */
	private function gatherSystemTools( array $context ): array {
		$available_tools = array();

		// System tasks get global tools that are explicitly marked as system-compatible,
		// or all global tools if no system-specific filtering exists.
		$global_tools = $this->tool_manager->get_global_tools();
		foreach ( $global_tools as $tool_name => $tool_config ) {
			if ( is_array( $tool_config ) && $this->tool_manager->is_tool_available( $tool_name, null ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		// System-specific tools (if any register via this filter).
		$raw_system_tools = apply_filters( 'datamachine_system_tools', array() );
		$system_tools     = $this->tool_manager->resolveAllTools( $raw_system_tools );

		foreach ( $system_tools as $tool_name => $tool_config ) {
			if ( is_array( $tool_config ) && ! empty( $tool_config ) ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}

		return $available_tools;
	}

	/**
	 * Fallback: global tools only.
	 */
	private function gatherGlobalTools( array $context ): array {
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
