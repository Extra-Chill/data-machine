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
	 * Returns a canonical JSON Schema fragment shaped as
	 * `{ properties: {...}, required?: [...] }`. Composers merge multiple
	 * fragments at the registration site to build a full
	 * `{ type: 'object', properties, required }` schema.
	 *
	 * @param array $handler_config Handler configuration
	 * @param array $engine_data Engine data snapshot
	 * @return array Canonical fragment with `properties` and optional `required`.
	 */
	public static function getToolParameters( array $handler_config, array $engine_data = array() ): array {
		return static::filterByEngineData( static::getAllParameters(), $engine_data );
	}

	/**
	 * Filter parameters based on engine data presence.
	 *
	 * Operates on canonical fragments. Properties whose key matches an
	 * engine-aware key with a non-empty engine_data value are removed
	 * from `properties` and the corresponding entry is removed from
	 * `required`.
	 *
	 * @param array $fragment Canonical fragment with `properties` and optional `required`.
	 * @param array $engine_data Engine data snapshot
	 * @return array Filtered canonical fragment
	 */
	protected static function filterByEngineData( array $fragment, array $engine_data ): array {
		if ( empty( $engine_data ) ) {
			return $fragment;
		}

		$properties = $fragment['properties'] ?? array();
		$required   = $fragment['required'] ?? array();

		if ( ! is_array( $properties ) ) {
			return $fragment;
		}

		$engine_aware = static::getEngineAwareKeys();
		$filtered     = array();

		foreach ( $properties as $key => $definition ) {
			if ( in_array( $key, $engine_aware, true ) && ! empty( $engine_data[ $key ] ) ) {
				continue;
			}
			$filtered[ $key ] = $definition;
		}

		$result = array( 'properties' => $filtered );

		if ( is_array( $required ) && ! empty( $required ) ) {
			$still_required = array_values(
				array_filter(
					$required,
					static fn( $name ) => is_string( $name ) && array_key_exists( $name, $filtered )
				)
			);
			if ( ! empty( $still_required ) ) {
				$result['required'] = $still_required;
			}
		}

		return $result;
	}
}
