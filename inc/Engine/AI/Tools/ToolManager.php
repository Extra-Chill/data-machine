<?php
/**
 * Centralized tool management for Data Machine AI system.
 *
 * Use-case agnostic tool management serving both Chat and Pipeline agents.
 * Handles tool discovery, configuration, enablement, and validation.
 *
 * Tool definitions support lazy loading via callables to prevent translation
 * timing issues in WordPress 6.7+. Definitions are only resolved when first
 * accessed, ensuring translations are available.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since 0.2.1
 */

namespace DataMachine\Engine\AI\Tools;

use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class ToolManager {

	// ============================================
	// LAZY RESOLUTION CACHE
	// ============================================

	/**
	 * Resolved tool definition cache.
	 * Stores resolved definitions to avoid repeated callable invocations.
	 *
	 * @var array<string, array>
	 */
	private static array $resolved_cache = array();

	/**
	 * Flag indicating init hook has fired.
	 * Used to warn about early resolution attempts.
	 *
	 * @var bool
	 */
	private static bool $translations_ready = false;

	/**
	 * Flag indicating init tracking has been set up.
	 *
	 * @var bool
	 */
	private static bool $init_tracking_registered = false;

	/**
	 * Initialize translation readiness tracking.
	 * Should be called during plugin initialization.
	 */
	public static function init(): void {
		if ( self::$init_tracking_registered ) {
			return;
		}

		self::$init_tracking_registered = true;

		// Check if init has already fired
		if ( did_action( 'init' ) ) {
			self::$translations_ready = true;
			return;
		}

		// Register for init hook
		add_action(
			'init',
			function () {
				self::$translations_ready = true;
			},
			1
		);
	}

	/**
	 * Clear resolved tool cache.
	 * Call when handlers, step types, or tools are dynamically registered.
	 */
	public static function clearCache(): void {
		self::$resolved_cache = array();
	}

	/**
	 * Resolve a tool definition if it's a callable.
	 *
	 * Handles lazy evaluation of tool definitions. Callables are invoked
	 * and their results cached. Arrays are returned as-is.
	 *
	 * Supports the unified registry wrapper format where a callable is stored
	 * as `['_callable' => callable, 'modes' => [...]]`. The callable is
	 * resolved and modes are merged into the result.
	 *
	 * Cache keys are scoped: when a $cache_scope is provided, the key
	 * becomes "$cache_scope|$tool_id" so that the same tool_id can hold
	 * different definitions for different modes (e.g. different flows
	 * configure upsert_event with different taxonomy selections).
	 *
	 * @param string $tool_id     Tool identifier.
	 * @param mixed  $definition  Tool definition (array, callable, or wrapper with _callable key).
	 * @param string $cache_scope Optional scope prefix for the cache key.
	 * @return array Resolved tool definition.
	 */
	private function resolveToolDefinition( string $tool_id, mixed $definition, string $cache_scope = '' ): array {
		$cache_key = $cache_scope ? $cache_scope . '|' . $tool_id : $tool_id;

		// Return cached if available
		if ( isset( self::$resolved_cache[ $cache_key ] ) ) {
			return self::$resolved_cache[ $cache_key ];
		}

		// Log warning if resolving before translations ready
		if ( ! self::$translations_ready && ! did_action( 'init' ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Tool definition resolved before init hook',
				array(
					'tool_id'        => $tool_id,
					'current_action' => current_action(),
					'suggestion'     => 'Use callable pattern: [$this, \'getToolDefinition\'] instead of $this->getToolDefinition()',
				)
			);
		}

		// Handle unified registry wrapper: ['_callable' becomes callable, 'modes' becomes [...]]
		$modes = array();
		if ( is_array( $definition ) && isset( $definition['_callable'] ) ) {
			$modes      = $definition['modes'] ?? array();
			$definition = $definition['_callable'];
		}

		// Resolve callable or use array directly
		if ( is_callable( $definition ) ) {
			$resolved = $definition();
		} else {
			$resolved = $definition;
		}

		// Ensure result is an array
		$resolved = is_array( $resolved ) ? $resolved : array();

		// Merge modes into resolved definition (registry modes take precedence)
		if ( ! empty( $modes ) ) {
			$resolved['modes'] = $modes;
		}

		// Cache the resolved definition
		self::$resolved_cache[ $cache_key ] = $resolved;

		return $resolved;
	}

	/**
	 * Resolve all tool definitions in an array.
	 *
	 * @param array  $tools       Raw tools array (may contain callables).
	 * @param string $cache_scope Optional scope prefix for cache isolation.
	 *                            Use a flow_step_id or similar context key so
	 *                            that handler-specific tools from different
	 *                            flows are cached independently.
	 * @return array Resolved tools array.
	 */
	public function resolveAllTools( array $tools, string $cache_scope = '' ): array {
		$resolved = array();
		foreach ( $tools as $tool_id => $definition ) {
			$resolved[ $tool_id ] = $this->resolveToolDefinition( $tool_id, $definition, $cache_scope );
		}
		return $resolved;
	}

	// ============================================
	// TOOL DISCOVERY
	// ============================================

	/**
	 * Get all registered tools from the unified registry.
	 * Resolves any callable definitions before returning.
	 *
	 * @return array All tools with resolved definitions.
	 */
	public function get_all_tools(): array {
		$raw_tools = apply_filters( 'datamachine_tools', array() );
		return $this->resolveAllTools( $raw_tools );
	}

	/**
	 * Get all registered tools (raw, unresolved).
	 *
	 * Returns the raw registry including callables and wrapper arrays.
	 * Useful when you need to check modes without resolving all definitions.
	 *
	 * @return array Raw tools array.
	 */
	public function get_raw_tools(): array {
		return apply_filters( 'datamachine_tools', array() );
	}

	/**
	 * Resolve handler tools for a specific adjacent-step handler.
	 *
	 * Iterates the unified `datamachine_tools` registry and resolves every
	 * entry whose `_handler_callable` matches the given handler slug. Two
	 * matching modes are supported:
	 *
	 * - **Exact slug**: `['handler' => 'wordpress_publish']` — the entry only
	 *   applies when the adjacent step's handler_slug equals `'wordpress_publish'`.
	 * - **Type match**: `['handler_types' => ['fetch', 'event_import']]` — the
	 *   entry applies to any handler whose registered type is in the list
	 *   (used by cross-cutting tools like `skip_item`).
	 *
	 * Callbacks receive `(handler_slug, handler_config, engine_data)` and
	 * return an `['tool_name' => $tool_definition]` array (empty to opt out).
	 * Multiple tools per handler are allowed.
	 *
	 * Results are cached per `flow_step_id|handler_slug` scope so repeated
	 * lookups within the same pipeline execution don't re-invoke callbacks.
	 *
	 * @since NEXT
	 *
	 * @param string $handler_slug   Adjacent step handler slug.
	 * @param array  $handler_config Handler configuration from flow step.
	 * @param array  $engine_data    Engine data snapshot for dynamic generation.
	 * @param string $cache_scope    Scope key (e.g. flow_step_id + handler_slug).
	 * @return array Resolved tools keyed by tool name.
	 */
	public function resolveHandlerTools(
		string $handler_slug,
		array $handler_config,
		array $engine_data,
		string $cache_scope = ''
	): array {
		$cache_key = $cache_scope . '|handler_tools|' . $handler_slug;

		if ( '' !== $cache_scope && isset( self::$resolved_cache[ $cache_key ] ) ) {
			return self::$resolved_cache[ $cache_key ];
		}

		$raw_tools        = $this->get_raw_tools();
		$resolved         = array();
		$handlers_by_slug = null; // Lazy-loaded only when handler_types matching is needed.

		foreach ( $raw_tools as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			if ( ! isset( $definition['_handler_callable'] ) || ! is_callable( $definition['_handler_callable'] ) ) {
				continue;
			}

			$matches = false;

			// Exact-slug match.
			if ( isset( $definition['handler'] ) && $definition['handler'] === $handler_slug ) {
				$matches = true;
			}

			// Handler-type match (cross-cutting tools).
			if ( ! $matches && ! empty( $definition['handler_types'] ) && is_array( $definition['handler_types'] ) ) {
				if ( null === $handlers_by_slug ) {
					$handlers_by_slug = apply_filters( 'datamachine_handlers', array() );
				}
				$handler_meta = $handlers_by_slug[ $handler_slug ] ?? null;
				$handler_type = $handler_meta['type'] ?? '';
				if ( $handler_type && in_array( $handler_type, $definition['handler_types'], true ) ) {
					$matches = true;
				}
			}

			if ( ! $matches ) {
				continue;
			}

			// Handler callables follow two conventions:
			// 1. Filter-style: ($tools, $handler_slug, $handler_config, $engine_data) — 4 params
			// 2. Direct-style: ($handler_slug, $handler_config, $engine_data) — 3 params
			// Detect by parameter count so both work correctly.
			$callable         = $definition['_handler_callable'];
			$callable_params  = $this->get_callable_param_count( $callable );
			$uses_filter_convention = ( $callable_params >= 4 );

			if ( $uses_filter_convention ) {
				$tool_map = call_user_func(
					$callable,
					array(),           // $tools — filter callbacks expect this as first arg.
					$handler_slug,
					$handler_config,
					$engine_data
				);
			} else {
				$tool_map = call_user_func(
					$callable,
					$handler_slug,
					$handler_config,
					$engine_data
				);
			}

			if ( ! is_array( $tool_map ) ) {
				continue;
			}

			$access_level = $definition['access_level'] ?? 'admin';
			$ability      = $definition['ability'] ?? null;

			foreach ( $tool_map as $tool_name => $tool_def ) {
				if ( ! is_string( $tool_name ) || '' === $tool_name || ! is_array( $tool_def ) ) {
					continue;
				}

				// Ensure a handler link is set so downstream filters (handler
				// context, permission resolution) know which handler owns
				// the tool.
				if ( ! isset( $tool_def['handler'] ) ) {
					$tool_def['handler'] = $handler_slug;
				}

				// Apply registry-level meta unless the resolved tool
				// explicitly overrides.
				if ( ! isset( $tool_def['modes'] ) ) {
					$tool_def['modes'] = array( ToolPolicyResolver::MODE_PIPELINE );
				}
				if ( null !== $ability && ! isset( $tool_def['ability'] ) ) {
					$tool_def['ability'] = $ability;
				}
				if ( ! isset( $tool_def['access_level'] ) && ! isset( $tool_def['ability'] ) ) {
					$tool_def['access_level'] = $access_level;
				}

				$resolved[ $tool_name ] = $tool_def;
			}
		}

		if ( '' !== $cache_scope ) {
			self::$resolved_cache[ $cache_key ] = $resolved;
		}

		return $resolved;
	}

	/**
	 * Get raw registry entries for handler tools.
	 *
	 * Returns unresolved entries from `datamachine_tools` that have a
	 * `_handler_callable` key. Useful for admin UIs that need to enumerate
	 * registered handler tools without invoking their callbacks.
	 *
	 * @since NEXT
	 *
	 * @return array Raw entries keyed by registry key.
	 */
	public function get_handler_tool_entries(): array {
		$raw_tools = $this->get_raw_tools();

		return array_filter(
			$raw_tools,
			function ( $definition ) {
				return is_array( $definition ) && isset( $definition['_handler_callable'] );
			}
		);
	}

	/**
	 * Get agent modes for a tool from its raw (unresolved) definition.
	 *
	 * Extracts modes without resolving callable definitions.
	 *
	 * @param mixed $definition Raw tool definition (array, callable, or wrapper).
	 * @return array Modes array.
	 */
	public static function get_tool_modes( mixed $definition ): array {
		if ( is_array( $definition ) ) {
			return $definition['modes'] ?? array();
		}
		return array();
	}

	/**
	 * Get globally disabled tools (opt-out pattern).
	 *
	 * @return array Globally disabled tool IDs
	 */
	public function get_globally_disabled_tools(): array {
		$disabled_tools = PluginSettings::get( 'disabled_tools', array() );
		return array_keys( $disabled_tools );
	}

	// ============================================
	// CONFIGURATION STATUS
	// ============================================

	/**
	 * Check if tool is configured.
	 *
	 * @param string $tool_id Tool identifier
	 * @return bool True if configured
	 */
	public function is_tool_configured( string $tool_id ): bool {
		// If tool doesn't require configuration, it's always configured
		if ( ! $this->requires_configuration( $tool_id ) ) {
			return true;
		}
		return apply_filters( 'datamachine_tool_configured', false, $tool_id );
	}

	/**
	 * Check if tool requires configuration.
	 *
	 * @param string $tool_id Tool identifier
	 * @return bool True if requires config
	 */
	public function requires_configuration( string $tool_id ): bool {
		$tools = $this->get_all_tools();
		return ! empty( $tools[ $tool_id ]['requires_config'] );
	}

	// ============================================
	// GLOBAL ENABLEMENT (OPT-OUT PATTERN)
	// ============================================

	/**
	 * Check if tool is globally enabled (opt-out).
	 * Configured tools enabled by default unless explicitly disabled.
	 *
	 * @param string $tool_id Tool identifier
	 * @return bool True if globally enabled
	 */
	public function is_globally_enabled( string $tool_id ): bool {
		$disabled_tools = PluginSettings::get( 'disabled_tools', array() );

		// Present in disabled_tools = disabled
		if ( isset( $disabled_tools[ $tool_id ] ) ) {
			return false;
		}

		// Not disabled — enabled if configured or doesn't require config
		return $this->is_tool_configured( $tool_id ) || ! $this->requires_configuration( $tool_id );
	}



	// ============================================
	// CONTEXT-AWARE ENABLEMENT
	// ============================================

	/**
	 * Get step-disabled tools for specific context.
	 * Use-case agnostic - works for pipeline steps or any context ID.
	 *
	 * @param string|null $context_id Context identifier (pipeline_step_id or null)
	 * @return array Disabled tool IDs for context
	 */
	public function get_step_disabled_tools( ?string $context_id = null ): array {
		if ( empty( $context_id ) ) {
			return array();
		}

		$db_pipelines      = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$saved_step_config = $db_pipelines->get_pipeline_step_config( $context_id );
		$step_tools        = $saved_step_config['disabled_tools'] ?? array();

		return is_array( $step_tools ) ? $step_tools : array();
	}


	// ============================================
	// AVAILABILITY CHECK (REPLACES datamachine_tool_enabled FILTER)
	// ============================================

	/**
	 * Check if tool is available for use.
	 * Direct logic replacement for datamachine_tool_enabled filter.
	 *
	 * @param string      $tool_id Tool identifier
	 * @param string|null $context_id Context ID (pipeline_step_id for pipeline, null for chat)
	 * @return bool True if tool is available
	 */
	public function is_tool_available( string $tool_id, ?string $context_id = null ): bool {
		$tools       = $this->get_all_tools();
		$tool_config = $tools[ $tool_id ] ?? null;

		if ( ! $tool_config ) {
			return false; // Tool doesn't exist
		}

		// Pipeline context: check step-specific selections
		if ( $context_id ) {
			$disabled = $this->get_step_disabled_tools( $context_id );
			if ( in_array( $tool_id, $disabled, true ) ) {
				return false;
			}
			// Fall through to global checks
		}

		// Chat context (no context_id): check global enablement + configuration
		if ( ! $this->is_globally_enabled( $tool_id ) ) {
			return false; // Globally disabled
		}

		$requires_config = $this->requires_configuration( $tool_id );
		$configured      = $this->is_tool_configured( $tool_id );

		return ! $requires_config || $configured;
	}

	// ============================================
	// VALIDATION & SAVING
	// ============================================

	/**
	 * Validate tool selection against rules.
	 *
	 * @param string $tool_id Tool identifier
	 * @return bool True if valid selection
	 */
	public function validate_tool_selection( string $tool_id ): bool {
		$tools = $this->get_all_tools();
		if ( ! isset( $tools[ $tool_id ] ) ) {
			return false; // Tool doesn't exist
		}

		$tool_config     = $tools[ $tool_id ];
		$requires_config = ! empty( $tool_config['requires_config'] );
		$configured      = $this->is_tool_configured( $tool_id );

		// Must be configured if configuration required
		if ( $requires_config && ! $configured ) {
			return false;
		}

		// Must not be globally disabled (opt-out check)
		if ( ! $this->is_globally_enabled( $tool_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Filter valid tools from array of tool IDs.
	 *
	 * @param array $tool_ids Array of tool identifiers
	 * @return array Valid tool IDs only
	 */
	public function filter_valid_tools( array $tool_ids ): array {
		return array_values( array_filter( $tool_ids, array( $this, 'validate_tool_selection' ) ) );
	}

	/**
	 * Save tool selections for context.
	 *
	 * @param string $context_id Context identifier
	 * @param array  $tool_ids Tool IDs to save
	 * @return array Validated and saved tool IDs
	 */
	public function save_step_tool_selections( string $context_id, array $tool_ids ): array {
		return $this->filter_valid_tools( $tool_ids );
	}

	// ============================================
	// DATA AGGREGATION FOR UI
	// ============================================

	/**
	 * Get tools data for step configuration modal.
	 *
	 * @param string $context_id Context identifier
	 * @return array Tools data for modal rendering
	 */
	public function get_tools_for_step_modal( string $context_id ): array {
		return array(
			'global_tools'         => $this->get_all_tools(),
			'modal_disabled_tools' => $this->get_step_disabled_tools( $context_id ),
			'pipeline_step_id'     => $context_id,
		);
	}

	/**
	 * Get tools data for settings page.
	 *
	 * @return array All global tools with status
	 */
	public function get_tools_for_settings_page(): array {
		$tools = $this->get_all_tools();
		$data  = array();

		foreach ( $tools as $tool_id => $tool_config ) {
			$data[ $tool_id ] = array(
				'config'           => $tool_config,
				'configured'       => $this->is_tool_configured( $tool_id ),
				'globally_enabled' => $this->is_globally_enabled( $tool_id ),
				'requires_config'  => $this->requires_configuration( $tool_id ),
			);
		}

		return $data;
	}

	/**
	 * Get tools for REST API response.
	 *
	 * @param string|null $mode Optional agent mode to filter tools ('pipeline', 'chat', 'system').
	 *                          When null, returns all tools.
	 * @return array Tools formatted for API
	 */
	public function get_tools_for_api( ?string $mode = null ): array {
		// If mode specified, use ToolPolicyResolver to filter appropriately.
		if ( null !== $mode ) {
			$resolver = new ToolPolicyResolver( $this );
			$tools    = $resolver->resolve( array( 'mode' => $mode ) );
		} else {
			$tools = $this->get_all_tools();
		}

		$formatted = array();

		foreach ( $tools as $tool_id => $tool_config ) {
			$is_globally_enabled = $this->is_globally_enabled( $tool_id );

			$formatted[ $tool_id ] = array(
				'label'            => $tool_config['label'] ?? ucfirst( str_replace( '_', ' ', $tool_id ) ),
				'description'      => $tool_config['description'] ?? '',
				'requires_config'  => $this->requires_configuration( $tool_id ),
				'configured'       => $this->is_tool_configured( $tool_id ),
				'globally_enabled' => $is_globally_enabled,
				'modes'            => $tool_config['modes'] ?? array(),
			);
		}

		return $formatted;
	}

	/**
	 * Get the number of parameters a callable accepts.
	 *
	 * @param callable $callable Callable to inspect.
	 * @return int Number of required+optional parameters, or 0 if unresolvable.
	 */
	private function get_callable_param_count( $callable ): int {
		try {
			if ( is_array( $callable ) ) {
				$ref = new \ReflectionMethod( $callable[0], $callable[1] );
			} elseif ( $callable instanceof \Closure || is_string( $callable ) ) {
				$ref = new \ReflectionFunction( $callable );
			} else {
				return 0;
			}
			return $ref->getNumberOfParameters();
		} catch ( \ReflectionException $e ) {
			return 0;
		}
	}
}
