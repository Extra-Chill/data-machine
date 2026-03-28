<?php
/**
 * Trait for engine-aware AI tool parameter generation.
 *
 * Filters tool parameters at definition time based on engine data presence.
 * If a parameter value exists in engine data, it's excluded from the tool
 * definition so the AI never sees or provides it.
 *
 * @package DataMachineEvents\Core
 * @since 0.3.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DynamicToolParametersTrait {

	/**
	 * Get all possible tool parameters.
	 *
	 * @return array Complete parameter definitions
	 */
	abstract protected static function getAllParameters(): array;

	/**
	 * Get parameter keys that should check engine data.
	 *
	 * @return array List of parameter keys that are engine-aware
	 */
	abstract protected static function getEngineAwareKeys(): array;

	/**
	 * Get tool parameters filtered by engine data.
	 *
	 * Excludes parameters that already have values in engine data,
	 * preventing the AI from seeing or providing redundant values.
	 *
	 * @param array $handler_config Handler configuration
	 * @param array $engine_data Engine data snapshot
	 * @return array Filtered parameter definitions
	 */
	public static function getToolParameters( array $handler_config, array $engine_data = array() ): array {
		return static::filterByEngineData( static::getAllParameters(), $engine_data );
	}

	/**
	 * Filter parameters based on engine data presence.
	 *
	 * @param array $parameters All available parameters
	 * @param array $engine_data Engine data snapshot
	 * @return array Filtered parameters
	 */
	protected static function filterByEngineData( array $parameters, array $engine_data ): array {
		if ( empty( $engine_data ) ) {
			return $parameters;
		}

		$engine_aware = static::getEngineAwareKeys();
		$filtered     = array();

		foreach ( $parameters as $key => $definition ) {
			if ( in_array( $key, $engine_aware, true ) && ! empty( $engine_data[ $key ] ) ) {
				continue;
			}
			$filtered[ $key ] = $definition;
		}

		return $filtered;
	}
}
