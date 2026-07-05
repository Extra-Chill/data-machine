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
use DataMachine\Engine\AI\Tools\Sources\AbilityToolSource;

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
		$meta  = array();
		if ( is_array( $definition ) && isset( $definition['_callable'] ) ) {
			$modes      = $definition['modes'] ?? array();
			$meta       = $definition;
			$definition = $definition['_callable'];
			unset( $meta['_callable'] );
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
		foreach ( array( 'ability', 'abilities', 'access_level', 'requires_opt_in', 'requires_pipeline_opt_in' ) as $key ) {
			if ( isset( $meta[ $key ] ) ) {
				$resolved[ $key ] = $meta[ $key ];
			}
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
	 * Build a normalized handler-tool declaration for the `datamachine_tools` registry.
	 *
	 * Handler declarations are not directly exposed to models. They describe which
	 * adjacent handler(s) can lazily produce tools and which runtime context values
	 * should be bound onto each produced tool definition.
	 *
	 * Existing `_handler_callable` arrays remain supported; this helper is the
	 * first-class shape new handler registrations should use.
	 *
	 * @param callable $handler_callable Callback that returns tools for a handler.
	 * @param array    $args             Declaration args: handler, handler_types, modes,
	 *                                   access_level, ability, client_context_bindings.
	 * @return array<string,mixed> Handler-tool declaration.
	 */
	public static function handlerToolDeclaration( callable $handler_callable, array $args = array() ): array {
		$args['_handler_callable'] = $handler_callable;
		return self::normalizeHandlerToolDeclaration( $args );
	}

	/**
	 * Normalize legacy and first-class handler-tool declarations.
	 *
	 * @param array $definition Raw declaration.
	 * @return array<string,mixed> Normalized declaration.
	 */
	public static function normalizeHandlerToolDeclaration( array $definition ): array {
		if ( ! isset( $definition['modes'] ) ) {
			$definition['modes'] = array( ToolPolicyResolver::MODE_PIPELINE );
		}
		if ( ! isset( $definition['access_level'] ) && ! isset( $definition['ability'] ) ) {
			$definition['access_level'] = 'admin';
		}

		if ( isset( $definition['context_bindings'] ) && ! isset( $definition['client_context_bindings'] ) ) {
			$definition['client_context_bindings'] = $definition['context_bindings'];
		}
		unset( $definition['context_bindings'] );

		if ( isset( $definition['handler_types'] ) && is_string( $definition['handler_types'] ) ) {
			$definition['handler_types'] = array( $definition['handler_types'] );
		}

		return $definition;
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
		$cache_key = self::buildHandlerToolsCacheKey( $cache_scope, $handler_slug, $handler_config, $engine_data );

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
			$definition = self::normalizeHandlerToolDeclaration( $definition );
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
			// 1. Filter-style: ($tools, $handler_slug, $handler_config[, $engine_data])
			// 2. Direct-style: ($handler_slug, $handler_config, $engine_data)
			// Detect by shape so 3-param filter callbacks are not mistaken for direct-style.
			$callable = $definition['_handler_callable'];
			$uses_filter_convention = $this->uses_filter_convention( $callable );

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

				$tool_def = $this->withHandlerToolContext( $tool_def, $definition, $handler_slug, $handler_config );

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
	 * Apply handler ownership and declaration-level context bindings to a tool.
	 *
	 * @param array  $tool_def       Tool definition returned by the handler callback.
	 * @param array  $declaration    Normalized handler-tool declaration.
	 * @param string $handler_slug   Adjacent step handler slug.
	 * @param array  $handler_config Handler configuration from flow step.
	 * @return array<string,mixed> Tool definition with handler context.
	 */
	private function withHandlerToolContext( array $tool_def, array $declaration, string $handler_slug, array $handler_config ): array {
		if ( ! isset( $tool_def['handler'] ) ) {
			$tool_def['handler'] = $handler_slug;
		}
		if ( ! isset( $tool_def['handler_config'] ) ) {
			$tool_def['handler_config'] = $handler_config;
		}

		$declared_bindings = is_array( $declaration['client_context_bindings'] ?? null )
			? $declaration['client_context_bindings']
			: array();
		if ( ! empty( $declared_bindings ) ) {
			$tool_def['client_context_bindings'] = self::mergeContextBindings( $tool_def['client_context_bindings'] ?? array(), $declared_bindings );
		}

		// Compatibility fallback for existing third-party handler declarations.
		// New registrations should declare context bindings on the handler-tool
		// declaration so ownership is visible before the callback is invoked.
		if ( 'handle_tool_call' === ( $tool_def['method'] ?? '' ) ) {
			$tool_def['client_context_bindings'] = self::mergeContextBindings( $tool_def['client_context_bindings'] ?? array(), array( 'job_id' ) );
		}

		return $tool_def;
	}

	/**
	 * Merge context-binding lists/maps without changing existing mappings.
	 *
	 * @param mixed $existing Existing tool-level binding declaration.
	 * @param array $defaults Default bindings from the handler declaration.
	 * @return array<int|string,string> Merged bindings.
	 */
	private static function mergeContextBindings( mixed $existing, array $defaults ): array {
		$merged = is_array( $existing ) ? $existing : array();
		foreach ( $defaults as $parameter => $context_key ) {
			if ( is_int( $parameter ) ) {
				if ( is_string( $context_key ) && '' !== $context_key && ! in_array( $context_key, $merged, true ) && ! array_key_exists( $context_key, $merged ) ) {
					$merged[] = $context_key;
				}
				continue;
			}

			if ( is_string( $parameter ) && '' !== $parameter && is_string( $context_key ) && '' !== $context_key && ! array_key_exists( $parameter, $merged ) ) {
				$merged[ $parameter ] = $context_key;
			}
		}

		return $merged;
	}

	/**
	 * Build a cache key for adjacent handler tool resolution.
	 *
	 * Direct workflows reuse synthetic flow_step_ids like `ephemeral_step_2`
	 * across jobs. Handler tools carry the adjacent step's handler_config, so
	 * the cache key must include the config and job identity to avoid reusing a
	 * previous job's pinned write target.
	 *
	 * @param string $cache_scope    Scope key (usually flow_step_id).
	 * @param string $handler_slug   Adjacent step handler slug.
	 * @param array  $handler_config Handler configuration from flow step.
	 * @param array  $engine_data    Engine data snapshot.
	 * @return string Cache key.
	 */
	private static function buildHandlerToolsCacheKey( string $cache_scope, string $handler_slug, array $handler_config, array $engine_data ): string {
		$job_snapshot = is_array( $engine_data['job'] ?? null ) ? $engine_data['job'] : array();
		$job_id       = (int) ( $engine_data['job_id'] ?? $job_snapshot['job_id'] ?? 0 );
		$config_json  = wp_json_encode( $handler_config );
		$config_hash  = md5( false === $config_json ? '' : $config_json );

		return implode(
			'|',
			array(
				$cache_scope,
				'handler_tools',
				$handler_slug,
				'job:' . $job_id,
				'config:' . $config_hash,
			)
		);
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
		$tools = $this->get_all_tool_declarations();
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
		$tools       = $this->get_all_tool_declarations();
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
		$tools = $this->get_all_tool_declarations();
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
			'global_tools'         => $this->get_all_tool_declarations(),
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
		$tools = $this->get_all_tool_declarations();
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
	 * Get static and ability-projected tool declarations for management surfaces.
	 *
	 * Runtime source resolution keeps legacy registry tools and ability projections
	 * separate. Settings and selection validation need a complete declaration list
	 * so ability-native tools can be configured and saved without retaining a
	 * duplicate class/method registry shadow.
	 *
	 * @return array<string,array<string,mixed>> Tool declarations keyed by tool name.
	 */
	private function get_all_tool_declarations(): array {
		$static_tools   = $this->get_all_tools();
		$ability_source = new AbilityToolSource( $this );
		$ability_tools  = $ability_source(
			array( ToolPolicyResolver::MODE_CHAT, ToolPolicyResolver::MODE_PIPELINE, ToolPolicyResolver::MODE_SYSTEM ),
			array( 'include_unavailable' => true )
		);

		return array_merge( $ability_tools, $static_tools );
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
			$tools    = $resolver->resolve( array( 'modes' => array( $mode ) ) );
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
	 * Determine whether a handler tool callback uses filter-style arguments.
	 *
	 * @param callable $callable Callable to inspect.
	 * @return bool True for ($tools, $handler_slug, ...) callbacks.
	 */
	private function uses_filter_convention( $callable_fn ): bool {
		try {
			$ref = $this->reflect_callable( $callable_fn );
			if ( null === $ref ) {
				return false;
			}

			$params = $ref->getParameters();
			if ( empty( $params ) ) {
				return false;
			}

			if ( count( $params ) >= 4 ) {
				return true;
			}

			$first_param_name = $params[0]->getName();
			return in_array( $first_param_name, array( 'tools', 'all_tools' ), true );
		} catch ( \ReflectionException $e ) {
			return false;
		}
	}

	/**
	 * Reflect a callable into a function-like reflection object.
	 *
	 * @param callable $callable Callable to inspect.
	 * @return \ReflectionFunctionAbstract|null Reflection object, or null when unsupported.
	 * @throws \ReflectionException When reflection fails.
	 */
	private function reflect_callable( $callable_fn ): ?\ReflectionFunctionAbstract {
		if ( is_array( $callable_fn ) ) {
			return new \ReflectionMethod( $callable_fn[0], $callable_fn[1] );
		}

		if ( $callable_fn instanceof \Closure || is_string( $callable_fn ) ) {
			return new \ReflectionFunction( $callable_fn );
		}

		return null;
	}
}
