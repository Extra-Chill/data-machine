<?php
/**
 * Flow Step Config Utilities
 *
 * Static helpers for reading canonical handler slugs and configs from step
 * configuration arrays.
 *
 * @package DataMachine\Core\Steps
 * @since 0.39.0
 */

namespace DataMachine\Core\Steps;

defined( 'ABSPATH' ) || exit;

class FlowStepConfig {

	/**
	 * Step-type capability fallback used before filters are registered.
	 *
	 * Runtime callers normally see step definitions from `datamachine_step_types`.
	 * Migrations run earlier in bootstrap, so the core step-type contract lives
	 * here too instead of depending on registration timing.
	 *
	 * @var array<string, array{uses_handler: bool, multi_handler: bool}>
	 */
	private const CORE_STEP_CAPABILITIES = array(
		'ai'           => array(
			'uses_handler'  => false,
			'multi_handler' => false,
		),
		'system_task'  => array(
			'uses_handler'  => false,
			'multi_handler' => false,
		),
		'webhook_gate' => array(
			'uses_handler'  => false,
			'multi_handler' => false,
		),
		'fetch'        => array(
			'uses_handler'  => true,
			'multi_handler' => false,
		),
		'publish'      => array(
			'uses_handler'  => true,
			'multi_handler' => true,
		),
		'upsert'       => array(
			'uses_handler'  => true,
			'multi_handler' => true,
		),
	);

	/**
	 * Resolve the effective config slug for a flow step.
	 *
	 * This is the config-settings slug, not necessarily a handler slug. Handler
	 * steps return their configured handler slug; handler-free steps return the
	 * step_type because their settings classes are keyed by step type.
	 *
	 * Priority:
	 * 1. Explicit slug (caller override, e.g. from API input).
	 * 2. Primary configured handler slug.
	 * 3. step_type for handler-free steps.
	 *
	 * @param array  $step_config   Step configuration array.
	 * @param string $explicit_slug Optional caller-provided slug (highest priority).
	 * @return string Resolved slug, or empty string if none available.
	 */
	public static function getEffectiveSlug( array $step_config, string $explicit_slug = '' ): string {
		if ( ! empty( $explicit_slug ) ) {
			return $explicit_slug;
		}

		$primary = self::getPrimaryHandlerSlug( $step_config );
		if ( null !== $primary ) {
			return $primary;
		}

		return self::usesHandler( $step_config ) ? '' : ( $step_config['step_type'] ?? '' );
	}

	/**
	 * Return whether this step type uses handlers.
	 *
	 * @param array $step_config Step configuration array.
	 * @return bool True when the step selects a handler.
	 */
	public static function usesHandler( array $step_config ): bool {
		return self::getCapabilities( $step_config )['uses_handler'];
	}

	/**
	 * Return whether this step type supports more than one handler.
	 *
	 * @param array $step_config Step configuration array.
	 * @return bool True when the step can store multiple handler slugs.
	 */
	public static function isMultiHandler( array $step_config ): bool {
		$capabilities = self::getCapabilities( $step_config );
		return $capabilities['uses_handler'] && $capabilities['multi_handler'];
	}

	/**
	 * Get the primary handler slug.
	 *
	 * Handler-backed steps use the same `handler_slugs` list in storage whether
	 * they support one handler or many. This convenience accessor returns the
	 * first configured handler for callers that operate on a primary handler.
	 *
	 * @param array $step_config Step configuration array.
	 * @return string|null Handler slug, or null when not configured/applicable.
	 */
	public static function getHandlerSlug( array $step_config ): ?string {
		if ( ! self::usesHandler( $step_config ) ) {
			return null;
		}

		$slugs = self::getConfiguredHandlerSlugs( $step_config );
		return $slugs[0] ?? null;
	}

	/**
	 * Get configured handler slugs.
	 *
	 * Handler-free steps return an empty array.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array<int, string> Handler slugs.
	 */
	public static function getHandlerSlugs( array $step_config ): array {
		return self::getConfiguredHandlerSlugs( $step_config );
	}

	/**
	 * Get every configured handler slug regardless of single vs multi shape.
	 *
	 * Use this for generic traversal/filtering callsites that are explicitly
	 * agnostic to the step type's handler cardinality.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array<int, string> Handler slugs.
	 */
	public static function getConfiguredHandlerSlugs( array $step_config ): array {
		if ( ! self::usesHandler( $step_config ) ) {
			return array();
		}

		$slugs = self::sanitizeSlugList( is_array( $step_config['handler_slugs'] ?? null ) ? $step_config['handler_slugs'] : array() );
		if ( ! empty( $slugs ) ) {
			return $slugs;
		}

		return array();
	}

	/**
	 * Get the primary handler slug for handler steps.
	 *
	 * @param array $step_config Step configuration array.
	 * @return string|null Primary handler slug.
	 */
	public static function getPrimaryHandlerSlug( array $step_config ): ?string {
		$slugs = self::getConfiguredHandlerSlugs( $step_config );
		return $slugs[0] ?? null;
	}

	/**
	 * Resolve handler slugs that must execute before an adjacent AI step completes.
	 *
	 * Publish requires every configured handler because it processes all matching
	 * handler results. Upsert may require only a configured subset; when no
	 * explicit subset is set, it requires the primary handler only.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array<int, string> Required handler slugs for AI completion.
	 */
	public static function getRequiredHandlerSlugsForAi( array $step_config ): array {
		$step_type = $step_config['step_type'] ?? '';

		if ( 'publish' === $step_type ) {
			return self::getConfiguredHandlerSlugs( $step_config );
		}

		if ( 'upsert' !== $step_type ) {
			return array();
		}

		$required = $step_config['required_handler_slugs'] ?? array();
		if ( is_array( $required ) ) {
			$required = self::sanitizeSlugList( $required );
			if ( ! empty( $required ) ) {
				return $required;
			}
		}

		$primary = self::getPrimaryHandlerSlug( $step_config );
		return null === $primary ? array() : array( $primary );
	}

	/**
	 * Resolve required handler slugs from the steps adjacent to an AI step.
	 *
	 * @param array|null $previous_step_config Previous adjacent flow step config.
	 * @param array|null $next_step_config     Next adjacent flow step config.
	 * @return array<int, string> Unique required handler slugs.
	 */
	public static function getAdjacentRequiredHandlerSlugsForAi( ?array $previous_step_config, ?array $next_step_config ): array {
		$required_handler_slugs = array();

		foreach ( array( $previous_step_config, $next_step_config ) as $adjacent_step_config ) {
			if ( ! $adjacent_step_config ) {
				continue;
			}

			$required_handler_slugs = array_merge(
				$required_handler_slugs,
				self::getRequiredHandlerSlugsForAi( $adjacent_step_config )
			);
		}

		return array_values( array_unique( self::sanitizeSlugList( $required_handler_slugs ) ) );
	}

	/**
	 * Return required handler slugs that are available as AI-callable tools.
	 *
	 * Completion tracking is keyed by handler slug, not by tool name: a handler
	 * tool may expose a model-facing name that differs from the handler slug while
	 * still carrying `handler => <slug>` metadata for the runtime.
	 *
	 * @param array<int, string> $required_handler_slugs Required handler slugs.
	 * @param array             $available_tools         Resolved tools keyed by tool name.
	 * @return array<int, string> Required handler slugs present in the tool set.
	 */
	public static function getAvailableRequiredHandlerSlugsForAi( array $required_handler_slugs, array $available_tools ): array {
		$required_handler_slugs = array_values( array_unique( self::sanitizeSlugList( $required_handler_slugs ) ) );
		if ( empty( $required_handler_slugs ) ) {
			return array();
		}

		$available_handler_slugs = array();
		foreach ( $available_tools as $tool_config ) {
			if ( ! is_array( $tool_config ) ) {
				continue;
			}

			$handler_slug = $tool_config['handler'] ?? '';
			if ( is_string( $handler_slug ) && '' !== $handler_slug ) {
				$available_handler_slugs[] = $handler_slug;
			}
		}

		return array_values( array_intersect( $required_handler_slugs, array_unique( $available_handler_slugs ) ) );
	}

	/**
	 * Return required handler slugs that are not available as AI-callable tools.
	 *
	 * @param array<int, string> $required_handler_slugs Required handler slugs.
	 * @param array             $available_tools         Resolved tools keyed by tool name.
	 * @return array<int, string> Missing required handler slugs.
	 */
	public static function getMissingRequiredHandlerSlugsForAi( array $required_handler_slugs, array $available_tools ): array {
		$required_handler_slugs = array_values( array_unique( self::sanitizeSlugList( $required_handler_slugs ) ) );
		if ( empty( $required_handler_slugs ) ) {
			return array();
		}

		$available_required = self::getAvailableRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools );
		return array_values( array_diff( $required_handler_slugs, $available_required ) );
	}

	/**
	 * Get the primary handler config from a step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array Primary handler configuration.
	 */
	public static function getPrimaryHandlerConfig( array $step_config ): array {
		if ( self::usesHandler( $step_config ) ) {
			$slug = self::getPrimaryHandlerSlug( $step_config );
			if ( null === $slug ) {
				return array();
			}

			return self::getHandlerConfigForSlug( $step_config, $slug );
		}

		$config = $step_config['flow_step_settings'] ?? array();
		return is_array( $config ) ? $config : array();
	}

	/**
	 * Get a handler config by slug regardless of single vs multi shape.
	 *
	 * @param array  $step_config Step configuration array.
	 * @param string $slug Handler or settings slug.
	 * @return array Handler configuration.
	 */
	public static function getHandlerConfigForSlug( array $step_config, string $slug ): array {
		if ( self::usesHandler( $step_config ) ) {
			$configs = is_array( $step_config['handler_configs'] ?? null ) ? $step_config['handler_configs'] : array();
			if ( array_key_exists( $slug, $configs ) ) {
				$config = $configs[ $slug ];
				return is_array( $config ) ? $config : array();
			}

			return array();
		}

		$effective_slug = self::getEffectiveSlug( $step_config );
		if ( $slug !== $effective_slug ) {
			return array();
		}

		return self::getPrimaryHandlerConfig( $step_config );
	}

	/**
	 * Get handler configs as a slug-keyed map for generic display callsites.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array<string, array> Handler/settings configs keyed by slug.
	 */
	public static function getHandlerConfigs( array $step_config ): array {
		if ( self::usesHandler( $step_config ) ) {
			$configs = is_array( $step_config['handler_configs'] ?? null ) ? $step_config['handler_configs'] : array();
			foreach ( self::getConfiguredHandlerSlugs( $step_config ) as $slug ) {
				if ( ! array_key_exists( $slug, $configs ) ) {
					$configs[ $slug ] = self::getHandlerConfigForSlug( $step_config, $slug );
				}
			}

			return array_filter( $configs, 'is_array' );
		}

		$slug   = self::getEffectiveSlug( $step_config );
		$config = self::getPrimaryHandlerConfig( $step_config );
		if ( '' === $slug || empty( $config ) ) {
			return array();
		}

		return array( $slug => $config );
	}

	/**
	 * Normalize a step config to the canonical handler storage shape.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array Canonicalized step configuration.
	 */
	public static function normalizeHandlerShape( array $step_config ): array {
		$uses_handler    = self::usesHandler( $step_config );
		$step_settings   = is_array( $step_config['flow_step_settings'] ?? null ) ? $step_config['flow_step_settings'] : array();
		$handler_slugs   = self::sanitizeSlugList( is_array( $step_config['handler_slugs'] ?? null ) ? $step_config['handler_slugs'] : array() );
		$handler_configs = is_array( $step_config['handler_configs'] ?? null ) ? $step_config['handler_configs'] : array();

		unset( $step_config['handler'], $step_config['handler_slug'], $step_config['handler_slugs'], $step_config['handler_config'], $step_config['handler_configs'], $step_config['flow_step_settings'] );

		if ( ! $uses_handler ) {
			if ( ! empty( $step_settings ) ) {
				$step_config['flow_step_settings'] = $step_settings;
			}
			return $step_config;
		}

		if ( ! empty( $handler_slugs ) ) {
			$step_config['handler_slugs'] = $handler_slugs;
		}
		if ( ! empty( $handler_configs ) ) {
			$step_config['handler_configs'] = array_filter( $handler_configs, 'is_array' );
		}
		return $step_config;
	}

	/**
	 * Get the AI step's enabled tools.
	 *
	 * Reads the dedicated `enabled_tools` field.
	 *
	 * @since 0.81.0
	 *
	 * @param array $step_config Flow step configuration array.
	 * @return array Tool slugs the AI step has enabled. Empty for non-AI steps.
	 */
	public static function getEnabledTools( array $step_config ): array {
		if ( 'ai' !== ( $step_config['step_type'] ?? '' ) ) {
			return array();
		}

		$enabled = $step_config['enabled_tools'] ?? array();
		if ( ! is_array( $enabled ) ) {
			return array();
		}

		return array_values( $enabled );
	}

	/**
	 * Whether an AI step explicitly configured its enabled_tools allowlist.
	 *
	 * Distinguishes "the operator deselected every optional tool"
	 * (`enabled_tools` present and an array, possibly empty) from "this step
	 * predates the field and was never configured" (`enabled_tools` absent or
	 * not an array). The two collapse to the same value under
	 * getEnabledTools(), but they carry opposite policy intent:
	 *
	 *   - Explicit (even empty) => allowlist mode: only the named optional tools
	 *     (none, for an empty list) survive alongside mandatory plumbing tools.
	 *   - Absent => preset mode: the context's default tool pool applies.
	 *
	 * Without this distinction an explicitly-empty enabled_tools silently falls
	 * through to the full preset, exposing global research tools (web_fetch,
	 * local_search, ...) the operator meant to exclude.
	 *
	 * @since 0.139.13
	 *
	 * @param array $step_config Flow step configuration array.
	 * @return bool True when an AI step has an explicit enabled_tools array.
	 */
	public static function isEnabledToolsExplicit( array $step_config ): bool {
		if ( 'ai' !== ( $step_config['step_type'] ?? '' ) ) {
			return false;
		}

		return array_key_exists( 'enabled_tools', $step_config )
			&& is_array( $step_config['enabled_tools'] );
	}

	/**
	 * Resolve capability flags for a step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array{uses_handler: bool, multi_handler: bool} Capability flags.
	 */
	private static function getCapabilities( array $step_config ): array {
		$step_type = $step_config['step_type'] ?? '';
		$defaults  = self::CORE_STEP_CAPABILITIES[ $step_type ] ?? array(
			'uses_handler'  => true,
			'multi_handler' => false,
		);

		$registered = function_exists( 'apply_filters' ) ? apply_filters( 'datamachine_step_types', array() ) : array();
		if ( is_array( $registered ) && isset( $registered[ $step_type ] ) && is_array( $registered[ $step_type ] ) ) {
			$definition = $registered[ $step_type ];
			return array(
				'uses_handler'  => (bool) ( $definition['uses_handler'] ?? $defaults['uses_handler'] ),
				'multi_handler' => (bool) ( $definition['multi_handler'] ?? $defaults['multi_handler'] ),
			);
		}

		return $defaults;
	}

	/**
	 * Normalize and de-duplicate slug lists.
	 *
	 * @param array $slugs Raw slug values.
	 * @return array<int, string> Clean slugs.
	 */
	private static function sanitizeSlugList( array $slugs ): array {
		$clean = array();
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			if ( ! in_array( $slug, $clean, true ) ) {
				$clean[] = $slug;
			}
		}
		return $clean;
	}
}
