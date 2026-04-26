<?php
/**
 * Flow Step Config Utilities
 *
 * Static helpers for reading handler slugs and configs from step configuration arrays.
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
	 * Return whether this step type supports multiple handlers.
	 *
	 * @param array $step_config Step configuration array.
	 * @return bool True when the step stores handler_slugs as a list.
	 */
	public static function isMultiHandler( array $step_config ): bool {
		$capabilities = self::getCapabilities( $step_config );
		return $capabilities['uses_handler'] && $capabilities['multi_handler'];
	}

	/**
	 * Get the scalar handler slug for single-handler step types.
	 *
	 * Calling this on a multi-handler step is a contract violation: callers
	 * that need every handler must use getHandlerSlugs(). Handler-free steps
	 * return null.
	 *
	 * @param array $step_config Step configuration array.
	 * @return string|null Handler slug, or null when not configured/applicable.
	 */
	public static function getHandlerSlug( array $step_config ): ?string {
		if ( ! self::usesHandler( $step_config ) ) {
			return null;
		}

		if ( self::isMultiHandler( $step_config ) ) {
			self::warnContractViolation( 'getHandlerSlug() called for a multi-handler step', $step_config );
			return null;
		}

		$slug = $step_config['handler_slug'] ?? null;
		if ( ! is_string( $slug ) || '' === $slug ) {
			return null;
		}

		return $slug;
	}

	/**
	 * Get handler slugs for multi-handler step types.
	 *
	 * Calling this on a single-handler step is a contract violation: callers
	 * that need the single slug must use getHandlerSlug(). Handler-free steps
	 * return an empty array.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array<int, string> Handler slugs.
	 */
	public static function getHandlerSlugs( array $step_config ): array {
		if ( ! self::usesHandler( $step_config ) ) {
			return array();
		}

		if ( ! self::isMultiHandler( $step_config ) ) {
			self::warnContractViolation( 'getHandlerSlugs() called for a single-handler step', $step_config );
			return array();
		}

		$slugs = $step_config['handler_slugs'] ?? array();
		return self::sanitizeSlugList( is_array( $slugs ) ? $slugs : array() );
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

		if ( self::isMultiHandler( $step_config ) ) {
			$slugs = $step_config['handler_slugs'] ?? array();
			return self::sanitizeSlugList( is_array( $slugs ) ? $slugs : array() );
		}

		$slug = $step_config['handler_slug'] ?? '';
		return is_string( $slug ) && '' !== $slug ? array( $slug ) : array();
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
	 * Get the primary handler config from a step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array Primary handler configuration.
	 */
	public static function getPrimaryHandlerConfig( array $step_config ): array {
		if ( self::isMultiHandler( $step_config ) ) {
			$slug = self::getPrimaryHandlerSlug( $step_config );
			if ( ! empty( $slug ) && ! empty( $step_config['handler_configs'][ $slug ] ) && is_array( $step_config['handler_configs'][ $slug ] ) ) {
				return $step_config['handler_configs'][ $slug ];
			}
			return array();
		}

		$config = $step_config['handler_config'] ?? array();
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
		if ( self::isMultiHandler( $step_config ) ) {
			$config = $step_config['handler_configs'][ $slug ] ?? array();
			return is_array( $config ) ? $config : array();
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
		if ( self::isMultiHandler( $step_config ) ) {
			$configs = $step_config['handler_configs'] ?? array();
			return is_array( $configs ) ? $configs : array();
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
		$uses_handler = self::usesHandler( $step_config );
		$is_multi     = self::isMultiHandler( $step_config );
		$step_type    = $step_config['step_type'] ?? '';

		$legacy_slugs   = self::sanitizeSlugList( is_array( $step_config['handler_slugs'] ?? null ) ? $step_config['handler_slugs'] : array() );
		$legacy_configs = is_array( $step_config['handler_configs'] ?? null ) ? $step_config['handler_configs'] : array();
		$config_slugs   = self::sanitizeSlugList( array_keys( $legacy_configs ) );
		$scalar_slug    = is_string( $step_config['handler_slug'] ?? null ) ? $step_config['handler_slug'] : '';
		$scalar_config  = is_array( $step_config['handler_config'] ?? null ) ? $step_config['handler_config'] : array();

		unset( $step_config['handler_slug'], $step_config['handler_slugs'], $step_config['handler_config'], $step_config['handler_configs'] );

		if ( ! $uses_handler ) {
			$config = $scalar_config;
			if ( empty( $config ) && '' !== $step_type && isset( $legacy_configs[ $step_type ] ) && is_array( $legacy_configs[ $step_type ] ) ) {
				$config = $legacy_configs[ $step_type ];
			}
			if ( ! empty( $config ) ) {
				$step_config['handler_config'] = $config;
			}
			return $step_config;
		}

		if ( $is_multi ) {
			$slugs = $legacy_slugs;
			if ( empty( $slugs ) && '' !== $scalar_slug ) {
				$slugs = array( $scalar_slug );
			}
			if ( empty( $slugs ) && ! empty( $config_slugs ) ) {
				$slugs = $config_slugs;
			}

			$configs = $legacy_configs;
			if ( empty( $configs ) && ! empty( $scalar_config ) && ! empty( $slugs ) ) {
				$configs = array( $slugs[0] => $scalar_config );
			}

			if ( ! empty( $slugs ) ) {
				$step_config['handler_slugs'] = $slugs;
			}
			if ( ! empty( $configs ) ) {
				$step_config['handler_configs'] = $configs;
			}
			return $step_config;
		}

		$slug = '' !== $scalar_slug ? $scalar_slug : ( $legacy_slugs[0] ?? ( $config_slugs[0] ?? '' ) );
		if ( '' !== $slug ) {
			$step_config['handler_slug'] = $slug;
		}

		$config = $scalar_config;
		if ( empty( $config ) && '' !== $slug && isset( $legacy_configs[ $slug ] ) && is_array( $legacy_configs[ $slug ] ) ) {
			$config = $legacy_configs[ $slug ];
		}
		if ( ! empty( $config ) || '' !== $slug ) {
			$step_config['handler_config'] = $config;
		}
		return $step_config;
	}

	/**
	 * Get the AI step's enabled tools.
	 *
	 * Reads the dedicated `enabled_tools` field. The pre-Phase 2b shape
	 * (AI tools stored under `handler_slugs`) is migrated on activation
	 * by inc/migrations/ai-enabled-tools.php — there is no runtime
	 * fallback to legacy data.
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

	/**
	 * Log a handler-shape contract violation.
	 *
	 * @param string $message Violation message.
	 * @param array  $step_config Step configuration array.
	 * @return void
	 */
	private static function warnContractViolation( string $message, array $step_config ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'FlowStepConfig handler-shape contract violation',
				array(
					'message'   => $message,
					'step_type' => $step_config['step_type'] ?? '',
				)
			);
		}
	}
}
