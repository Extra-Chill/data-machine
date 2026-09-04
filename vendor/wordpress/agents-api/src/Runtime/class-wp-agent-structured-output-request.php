<?php
/**
 * Structured provider-output request contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, provider-neutral JSON Schema output request.
 *
 * Strict local validation supports `type`, `enum`, object `properties`,
 * `required`, `additionalProperties`, and array `items`. Providers still receive
 * the complete schema unchanged.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Structured_Output_Request {

	public const FORMAT_JSON_SCHEMA = 'json_schema';

	private string $format;

	private ?string $name;

	/** @var array<string,mixed> */
	private array $schema;

	private bool $strict;

	/**
	 * @param array<string,mixed> $schema JSON Schema object.
	 */
	public function __construct( array $schema, ?string $name = null, bool $strict = true, string $format = self::FORMAT_JSON_SCHEMA ) {
		if ( self::FORMAT_JSON_SCHEMA !== $format ) {
			throw self::invalid( 'format', 'must be json_schema' );
		}
		if ( false === wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) {
			throw self::invalid( 'schema', 'must be JSON serializable' );
		}

		$name = null === $name ? null : trim( $name );
		if ( null !== $name && ( '' === $name || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name ) ) ) {
			throw self::invalid( 'name', 'must match /^[A-Za-z][A-Za-z0-9_-]{0,63}$/' );
		}

		$this->format = $format;
		$this->name   = $name;
		$this->schema = $schema;
		$this->strict = $strict;
	}

	/**
	 * @param mixed $value Raw request configuration.
	 */
	public static function from_array( $value ): self {
		if ( ! is_array( $value ) ) {
			throw self::invalid( 'structured_output', 'must be an array' );
		}
		if ( ! array_key_exists( 'schema', $value ) || ! is_array( $value['schema'] ) ) {
			throw self::invalid( 'schema', 'must be an array' );
		}
		if ( array_key_exists( 'name', $value ) && ! is_string( $value['name'] ) && null !== $value['name'] ) {
			throw self::invalid( 'name', 'must be a string or null' );
		}
		if ( array_key_exists( 'strict', $value ) && ! is_bool( $value['strict'] ) ) {
			throw self::invalid( 'strict', 'must be a boolean' );
		}
		if ( array_key_exists( 'format', $value ) && ! is_string( $value['format'] ) ) {
			throw self::invalid( 'format', 'must be a string' );
		}

		$schema = array();
		foreach ( $value['schema'] as $key => $item ) {
			if ( is_string( $key ) ) {
				$schema[ $key ] = $item;
			}
		}
		$name   = isset( $value['name'] ) && is_string( $value['name'] ) ? $value['name'] : null;
		$strict = isset( $value['strict'] ) && is_bool( $value['strict'] ) ? $value['strict'] : true;
		$format = isset( $value['format'] ) && is_string( $value['format'] ) ? $value['format'] : self::FORMAT_JSON_SCHEMA;

		return new self( $schema, $name, $strict, $format );
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		$result = array(
			'format' => $this->format,
			'schema' => $this->schema,
			'strict' => $this->strict,
		);
		if ( null !== $this->name ) {
			$result['name'] = $this->name;
		}
		return $result;
	}

	/** @return array<string,mixed> */
	public function schema(): array { return $this->schema; }

	public function format(): string { return $this->format; }

	public function name(): ?string { return $this->name; }

	public function strict(): bool { return $this->strict; }

	/**
	 * Validate a parsed JSON value against the supported strict schema subset.
	 *
	 * @param mixed $value Parsed JSON value.
	 * @return string|null Stable failure code, or null when valid.
	 */
	public function validate( $value ): ?string {
		return self::validate_value( $value, $this->schema );
	}

	/**
	 * @param mixed $value
	 * @param array<mixed> $schema
	 */
	private static function validate_value( $value, array $schema ): ?string {
		if ( isset( $schema['type'] ) && ! self::matches_type( $value, $schema['type'] ) ) {
			return 'type_mismatch';
		}
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return 'enum_mismatch';
		}
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				foreach ( $schema['required'] as $name ) {
					if ( ! is_string( $name ) || ! array_key_exists( $name, $properties ) ) {
						return 'required_property_missing';
					}
				}
			}
			$property_schemas = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
			foreach ( $properties as $name => $property ) {
				$property_schema = $property_schemas[ $name ] ?? null;
				if ( is_array( $property_schema ) ) {
					$failure = self::validate_value( $property, self::schema_object( $property_schema ) );
					if ( null !== $failure ) {
						return $failure;
					}
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					return 'additional_property_forbidden';
				}
			}
		}
		if ( is_array( $value ) && array_is_list( $value ) && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $value as $item ) {
				$failure = self::validate_value( $item, self::schema_object( $schema['items'] ) );
				if ( null !== $failure ) {
					return $failure;
				}
			}
		}
		return null;
	}

	/**
	 * @param mixed $value
	 * @param mixed $type
	 */
	private static function matches_type( $value, $type ): bool {
		$types = is_array( $type ) ? $type : array( $type );
		foreach ( $types as $candidate ) {
			if ( ( 'object' === $candidate && $value instanceof \stdClass ) || ( 'array' === $candidate && is_array( $value ) && array_is_list( $value ) ) || ( 'string' === $candidate && is_string( $value ) ) || ( 'number' === $candidate && ( is_int( $value ) || is_float( $value ) ) ) || ( 'integer' === $candidate && is_int( $value ) ) || ( 'boolean' === $candidate && is_bool( $value ) ) || ( 'null' === $candidate && null === $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<mixed> $schema
	 * @return array<string,mixed>
	 */
	private static function schema_object( array $schema ): array {
		$normalized = array();
		foreach ( $schema as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}
		return $normalized;
	}

	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_structured_output_request: ' . $path . ' ' . $reason );
	}
}
