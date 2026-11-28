<?php
/**
 * Canonical tool schema normalization.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes tool parameter declarations to provider-ready JSON Schema objects.
 */
class ToolSchemaNormalizer {

	/**
	 * Normalize raw tool parameters to canonical JSON Schema object form.
	 *
	 * Tool definitions historically used flat property maps with optional
	 * property-level `required` flags. Providers expect a root object schema.
	 *
	 * @param mixed $parameters Raw tool parameters definition.
	 * @return array<string, mixed>
	 */
	public static function normalize( mixed $parameters ): array {
		if ( ! is_array( $parameters ) || empty( $parameters ) ) {
			return self::canonicalObjectSchema( array() );
		}

		if ( isset( $parameters['type'] ) || isset( $parameters['properties'] ) || isset( $parameters['required'] ) ) {
			$parameters['type'] = $parameters['type'] ?? 'object';
			return self::normalizeRootSchema( $parameters );
		}

		return self::canonicalObjectSchema( $parameters );
	}

	/**
	 * Normalize an existing root object schema.
	 *
	 * @param array<string, mixed> $schema Root schema.
	 * @return array<string, mixed>
	 */
	private static function normalizeRootSchema( array $schema ): array {
		$properties = $schema['properties'] ?? array();

		if ( is_array( $properties ) ) {
			$normalized_properties = self::normalizeProperties( $properties );
			$required              = self::requiredNames( $properties );

			$schema['properties'] = ! empty( $normalized_properties ) ? $normalized_properties : (object) array();

			if ( ! empty( $required ) ) {
				$schema['required'] = self::mergeRequired( $schema['required'] ?? array(), $required );
			}
		} elseif ( ! isset( $schema['properties'] ) ) {
			$schema['properties'] = (object) array();
		}

		return $schema;
	}

	/**
	 * Build a canonical object schema from a flat property map.
	 *
	 * @param array<mixed> $properties Raw property map.
	 * @return array<string, mixed>
	 */
	private static function canonicalObjectSchema( array $properties ): array {
		$normalized_properties = self::normalizeProperties( $properties );
		$required              = self::requiredNames( $properties );

		$schema = array(
			'type'       => 'object',
			'properties' => ! empty( $normalized_properties ) ? $normalized_properties : (object) array(),
		);

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Normalize property schemas and remove legacy property-level required flags.
	 *
	 * @param array<mixed> $properties Raw property map.
	 * @return array<string, mixed>
	 */
	private static function normalizeProperties( array $properties ): array {
		$normalized = array();

		foreach ( $properties as $name => $property_schema ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			$property_schema = is_array( $property_schema ) ? $property_schema : array( 'type' => 'string' );
			unset( $property_schema['required'] );

			$normalized[ $name ] = $property_schema;
		}

		return $normalized;
	}

	/**
	 * Extract required property names from legacy property-level flags.
	 *
	 * @param array<mixed> $properties Raw property map.
	 * @return array<int, string>
	 */
	private static function requiredNames( array $properties ): array {
		$required = array();

		foreach ( $properties as $name => $property_schema ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $property_schema ) ) {
				continue;
			}

			if ( ! empty( $property_schema['required'] ) ) {
				$required[] = $name;
			}
		}

		return $required;
	}

	/**
	 * Merge existing root required names with extracted legacy required flags.
	 *
	 * @param mixed              $existing Existing root required value.
	 * @param array<int,string> $extracted Extracted required names.
	 * @return array<int,string>
	 */
	private static function mergeRequired( mixed $existing, array $extracted ): array {
		$required = array();

		if ( is_array( $existing ) ) {
			foreach ( $existing as $name ) {
				if ( is_string( $name ) && '' !== $name ) {
					$required[] = $name;
				}
			}
		}

		foreach ( $extracted as $name ) {
			$required[] = $name;
		}

		return array_values( array_unique( $required ) );
	}
}
