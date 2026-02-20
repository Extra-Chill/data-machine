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
				$primary = $step_config['handler_slugs'][0] ?? '';
				$config  = $step_config['handler_config'] ?? array();
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
			$step_config['handler_slugs']   = array();
			$step_config['handler_configs'] = array();
			unset( $step_config['handler_slug'] );
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
