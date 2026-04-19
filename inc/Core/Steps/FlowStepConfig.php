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
	 * Resolve the effective config slug for a flow step.
	 *
	 * Single source of truth for determining which slug to use when reading
	 * or writing handler_configs. Works for both handler-based steps (fetch,
	 * publish, upsert) and self-configuring steps (agent_ping, webhook_gate,
	 * system_task) that use step_type as their config key.
	 *
	 * Priority:
	 * 1. Explicit slug (caller override, e.g. from API input)
	 * 2. Primary slug from handler_slugs[0]
	 * 3. step_type (self-configuring steps)
	 *
	 * @param array  $step_config   Step configuration array.
	 * @param string $explicit_slug Optional caller-provided slug (highest priority).
	 * @return string Resolved slug, or empty string if none available.
	 */
	public static function getEffectiveSlug( array $step_config, string $explicit_slug = '' ): string {
		if ( ! empty( $explicit_slug ) ) {
			return $explicit_slug;
		}

		if ( ! empty( $step_config['handler_slugs'] ) && is_array( $step_config['handler_slugs'] ) ) {
			$primary = $step_config['handler_slugs'][0] ?? '';
			if ( ! empty( $primary ) ) {
				return $primary;
			}
		}

		return $step_config['step_type'] ?? '';
	}

	/**
	 * Get the primary handler config from a step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array Primary handler configuration.
	 */
	public static function getPrimaryHandlerConfig( array $step_config ): array {
		$slug = $step_config['handler_slugs'][0] ?? '';
		if ( ! empty( $slug ) && ! empty( $step_config['handler_configs'][ $slug ] ) ) {
			return $step_config['handler_configs'][ $slug ];
		}
		return array();
	}
}
