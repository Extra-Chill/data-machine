<?php
/**
 * Flow Step Normalizer
 *
 * Pure data transformation for flow step configurations.
 * Normalizes handler fields between legacy singular and plural formats.
 *
 * @package DataMachine\Abilities\FlowStep
 * @since 0.29.0
 */

namespace DataMachine\Abilities\FlowStep;

defined( 'ABSPATH' ) || exit;

class FlowStepNormalizer {

	/**
	 * Normalize flow step config to use handler_slugs/handler_configs as source of truth.
	 * Migrates legacy singular handler_slug/handler_config to plural format.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array Normalized step configuration.
	 */
	public static function normalizeHandlerFields( array $step_config ): array {
		if ( ! empty( $step_config['handler_slugs'] ) && is_array( $step_config['handler_slugs'] ) ) {
			if ( empty( $step_config['handler_configs'] ) || ! is_array( $step_config['handler_configs'] ) ) {
				$primary                        = $step_config['handler_slugs'][0] ?? '';
				$config                         = $step_config['handler_config'] ?? array();
				$step_config['handler_configs'] = ! empty( $primary ) ? array( $primary => $config ) : array();
			}
			unset( $step_config['handler_slug'], $step_config['handler_config'] );
			return $step_config;
		}

		$slug   = $step_config['handler_slug'] ?? '';
		$config = $step_config['handler_config'] ?? array();

		if ( ! empty( $slug ) ) {
			$step_config['handler_slugs']   = array( $slug );
			$step_config['handler_configs'] = array( $slug => $config );
			unset( $step_config['handler_slug'], $step_config['handler_config'] );
		} else {
			// No handler_slug — use step_type as key for non-handler steps (agent_ping, webhook_gate, etc.)
			// that store config in handler_config without a handler_slug.
			$step_type = $step_config['step_type'] ?? '';
			if ( ! empty( $step_type ) && ! empty( $config ) ) {
				$step_config['handler_slugs']   = array( $step_type );
				$step_config['handler_configs'] = array( $step_type => $config );
			} else {
				$step_config['handler_slugs']   = array();
				$step_config['handler_configs'] = array();
			}
			unset( $step_config['handler_slug'], $step_config['handler_config'] );
		}

		return $step_config;
	}

	/**
	 * Get the primary handler slug from a normalized step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @return string Primary handler slug.
	 */
	public static function getPrimaryHandlerSlug( array $step_config ): string {
		if ( ! empty( $step_config['handler_slugs'] ) && is_array( $step_config['handler_slugs'] ) ) {
			return $step_config['handler_slugs'][0] ?? '';
		}
		return $step_config['handler_slug'] ?? '';
	}

	/**
	 * Resolve the effective config slug for a flow step.
	 *
	 * Single source of truth for determining which slug to use when reading
	 * or writing handler_configs. Works for both handler-based steps (fetch,
	 * publish, update) and self-configuring steps (agent_ping, webhook_gate,
	 * system_task) that use step_type as their config key.
	 *
	 * Priority:
	 * 1. Explicit slug (caller override, e.g. from API input)
	 * 2. Primary slug from handler_slugs[0] (normalized data)
	 * 3. Legacy handler_slug (pre-normalization data)
	 * 4. step_type (self-configuring steps)
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
		if ( ! empty( $primary ) ) {
			return $primary;
		}

		return $step_config['step_type'] ?? '';
	}

	/**
	 * Get the primary handler config from a normalized step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array Primary handler configuration.
	 */
	public static function getPrimaryHandlerConfig( array $step_config ): array {
		$slug = self::getPrimaryHandlerSlug( $step_config );
		if ( ! empty( $slug ) && ! empty( $step_config['handler_configs'][ $slug ] ) ) {
			return $step_config['handler_configs'][ $slug ];
		}
		return $step_config['handler_config'] ?? array();
	}
}
