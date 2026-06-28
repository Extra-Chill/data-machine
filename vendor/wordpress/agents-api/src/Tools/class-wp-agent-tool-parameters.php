<?php
/**
 * Tool parameter normalization helpers.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the final parameter array passed to a tool executor.
 */
class WP_Agent_Tool_Parameters {

	/**
	 * Context roots a tool declaration may explicitly bind from.
	 */
	private const ALLOWED_CONTEXT_SOURCES = array(
		'context'         => true,
		'client_context'  => true,
		'caller_context'  => true,
		'runtime_context' => true,
	);

	/**
	 * Redacted placeholder used for sensitive parameter values.
	 */
	public const REDACTED_VALUE = '[redacted]';

	/**
	 * Merge declared client-context bindings with runtime tool parameters.
	 *
	 * Caller-supplied parameters always win. Values from `$context` are only
	 * pulled in for parameter slots that the tool declaration explicitly opts
	 * into via `client_context_bindings`. This keeps sensitive parameters
	 * auditable: a context key can never silently satisfy a required tool
	 * argument the tool author didn't expect to come from context.
	 *
	 * Two declaration shapes are accepted:
	 *
	 *   - Associative: `[ 'user_phone' => 'sender_id' ]` — pull
	 *     `context['sender_id']` into the `user_phone` parameter slot.
	 *   - Flat list:   `[ 'sender_id', 'connector_id' ]`  — same-name
	 *     binding, equivalent to `[ 'sender_id' => 'sender_id', ... ]`.
	 *
	 * Empty / null context values are ignored so they don't silently
	 * satisfy a required-parameter check.
	 *
	 * @param array<mixed> $tool_parameters Runtime tool-call parameters.
	 * @param array<mixed> $context         Host runtime context for this invocation.
	 * @param array<mixed> $tool_definition Normalized tool declaration.
	 * @return array<mixed> Complete parameters for execution.
	 */
	public static function buildParameters( array $tool_parameters, array $context = array(), array $tool_definition = array() ): array {
		$parameters = array();

		foreach ( self::parameterDefaults( $tool_definition ) as $parameter_name => $value ) {
			$parameters[ $parameter_name ] = $value;
		}

		foreach ( self::parameterBindings( $tool_definition ) as $parameter_name => $binding ) {
			$resolved = self::resolveBindingValue( $binding, $context );
			if ( ! $resolved['found'] ) {
				if ( array_key_exists( 'default', $binding ) ) {
					$parameters[ $parameter_name ] = $binding['default'];
				}
				continue;
			}
			$value = $resolved['value'];
			if ( '' === $value || null === $value ) {
				continue;
			}
			$parameters[ $parameter_name ] = $value;
		}

		foreach ( $tool_parameters as $key => $value ) {
			$parameters[ $key ] = $value;
		}

		return $parameters;
	}

	/**
	 * Normalize explicit parameter bindings, including legacy client-context bindings.
	 *
	 * @param array<mixed> $tool_definition Normalized tool declaration.
	 * @return array<string, array{source: string, path: string, sensitive?: bool, default?: mixed}>
	 */
	public static function normalizeParameterBindings( array $tool_definition ): array {
		$normalized = array();

		if ( array_key_exists( 'client_context_bindings', $tool_definition ) ) {
			$bindings = $tool_definition['client_context_bindings'];
			if ( ! is_array( $bindings ) ) {
				throw new \InvalidArgumentException( 'invalid_parameter_bindings: client_context_bindings' );
			}

			foreach ( $bindings as $parameter_name => $context_key ) {
				if ( is_int( $parameter_name ) && is_string( $context_key ) && '' !== trim( $context_key ) ) {
					$normalized[ $context_key ] = array(
						'source' => 'context',
						'path'   => trim( $context_key ),
					);
					continue;
				}

				if ( is_string( $parameter_name ) && '' !== trim( $parameter_name ) && is_string( $context_key ) && '' !== trim( $context_key ) ) {
					$normalized[ trim( $parameter_name ) ] = array(
						'source' => 'context',
						'path'   => trim( $context_key ),
					);
					continue;
				}

				throw new \InvalidArgumentException( 'invalid_parameter_bindings: client_context_bindings' );
			}
		}

		if ( ! array_key_exists( 'parameter_bindings', $tool_definition ) ) {
			return $normalized;
		}

		$bindings = $tool_definition['parameter_bindings'];
		if ( ! is_array( $bindings ) ) {
			throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_bindings' );
		}

		foreach ( $bindings as $parameter_name => $binding ) {
			if ( ! is_string( $parameter_name ) || '' === trim( $parameter_name ) ) {
				throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_bindings' );
			}

			$normalized[ trim( $parameter_name ) ] = self::normalizeParameterBinding( $binding );
		}

		return $normalized;
	}

	/**
	 * Normalize top-level parameter defaults.
	 *
	 * @param array<mixed> $tool_definition Normalized tool declaration.
	 * @return array<string,mixed>
	 */
	public static function normalizeParameterDefaults( array $tool_definition ): array {
		if ( ! array_key_exists( 'parameter_defaults', $tool_definition ) ) {
			return array();
		}

		$defaults = $tool_definition['parameter_defaults'];
		if ( ! is_array( $defaults ) ) {
			throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_defaults' );
		}

		$normalized = array();
		foreach ( $defaults as $parameter_name => $value ) {
			if ( ! is_string( $parameter_name ) || '' === trim( $parameter_name ) || ! self::isJsonFriendlyValue( $value ) ) {
				throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_defaults' );
			}

			$normalized[ trim( $parameter_name ) ] = $value;
		}

		return $normalized;
	}

	/**
	 * Normalize parameter bindings from a declaration that has already been validated.
	 *
	 * @param array<mixed> $tool_definition Normalized tool declaration.
	 * @return array<string, array{source: string, path: string, sensitive?: bool, default?: mixed}>
	 */
	private static function parameterBindings( array $tool_definition ): array {
		try {
			return self::normalizeParameterBindings( $tool_definition );
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
			return array();
		}
	}

	/**
	 * Normalize parameter defaults from a declaration that has already been validated.
	 *
	 * @param array<mixed> $tool_definition Normalized tool declaration.
	 * @return array<string,mixed>
	 */
	private static function parameterDefaults( array $tool_definition ): array {
		try {
			return self::normalizeParameterDefaults( $tool_definition );
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
			return array();
		}
	}

	/**
	 * Normalize one parameter binding declaration.
	 *
	 * @param mixed $binding Raw binding declaration.
	 * @return array{source: string, path: string, sensitive?: bool, default?: mixed}
	 */
	private static function normalizeParameterBinding( $binding ): array {
		if ( is_string( $binding ) ) {
			return self::normalizeBindingSourcePath( $binding );
		}

		if ( ! is_array( $binding ) ) {
			throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_bindings' );
		}

		$source = $binding['source'] ?? '';
		$path   = $binding['path'] ?? '';
		if ( ! is_string( $source ) || ! is_string( $path ) || '' === trim( $path ) || ! isset( self::ALLOWED_CONTEXT_SOURCES[ $source ] ) ) {
			throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_bindings' );
		}

		$normalized = array(
			'source' => $source,
			'path'   => trim( $path ),
		);

		if ( array_key_exists( 'sensitive', $binding ) ) {
			if ( ! is_bool( $binding['sensitive'] ) ) {
				throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_bindings' );
			}
			if ( $binding['sensitive'] ) {
				$normalized['sensitive'] = true;
			}
		}

		if ( array_key_exists( 'default', $binding ) ) {
			if ( ! self::isJsonFriendlyValue( $binding['default'] ) ) {
				throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_bindings' );
			}
			$normalized['default'] = $binding['default'];
		}

		return $normalized;
	}

	/**
	 * Normalize the compact `source.dot.path` binding form.
	 *
	 * @param string $binding Binding expression.
	 * @return array{source: string, path: string}
	 */
	private static function normalizeBindingSourcePath( string $binding ): array {
		$binding = trim( $binding );
		$parts   = explode( '.', $binding, 2 );
		if ( 2 !== count( $parts ) || ! isset( self::ALLOWED_CONTEXT_SOURCES[ $parts[0] ] ) || '' === trim( $parts[1] ) ) {
			throw new \InvalidArgumentException( 'invalid_parameter_bindings: parameter_bindings' );
		}

		return array(
			'source' => $parts[0],
			'path'   => trim( $parts[1] ),
		);
	}

	/**
	 * Resolve a binding value from runtime context.
	 *
	 * @param array{source: string, path: string} $binding Binding declaration.
	 * @param array<mixed>                       $context Runtime context.
	 * @return array{found: bool, value: mixed}
	 */
	private static function resolveBindingValue( array $binding, array $context ): array {
		$source = $binding['source'];
		$value  = 'context' === $source ? $context : ( $context[ $source ] ?? null );
		if ( ! is_array( $value ) ) {
			return array(
				'found' => false,
				'value' => null,
			);
		}

		foreach ( explode( '.', $binding['path'] ) as $segment ) {
			if ( '' === $segment || ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return array(
					'found' => false,
					'value' => null,
				);
			}
			$value = $value[ $segment ];
		}

		return array(
			'found' => true,
			'value' => $value,
		);
	}

	/**
	 * Validate required parameters declared by a tool definition.
	 *
	 * Supports both the compact Agents API shape (`required` as a list of names)
	 * and per-property `required => true` flags.
	 *
	 * @param array<mixed> $tool_parameters Runtime tool-call parameters.
	 * @param array<mixed> $tool_definition Normalized tool declaration.
	 * @return array{valid: bool, required: array<int, string>, missing: array<int, string>}
	 */
	public static function validateRequiredParameters( array $tool_parameters, array $tool_definition ): array {
		$required = self::requiredParameterNames( $tool_definition );
		$missing  = array();

		foreach ( $required as $parameter_name ) {
			if ( ! array_key_exists( $parameter_name, $tool_parameters ) || '' === $tool_parameters[ $parameter_name ] || null === $tool_parameters[ $parameter_name ] ) {
				$missing[] = $parameter_name;
			}
		}

		return array(
			'valid'    => empty( $missing ),
			'required' => $required,
			'missing'  => $missing,
		);
	}

	/**
	 * Build a generic observer-safe exposure envelope for tool parameters.
	 *
	 * @param array<mixed> $tool_parameters Raw tool-call parameters.
	 * @param array<mixed> $tool_definition Normalized tool declaration.
	 * @return array{parameters: array<string, mixed>, parameters_sha256: string, parameters_redacted: bool}
	 */
	public static function exposureEnvelope( array $tool_parameters, array $tool_definition = array() ): array {
		$parameters = self::redactedParameters( $tool_parameters, $tool_definition );

		return array(
			'parameters'          => $parameters,
			'parameters_sha256'   => self::stableSha256( $parameters ),
			'parameters_redacted' => true,
		);
	}

	/**
	 * Return tool parameters with sensitive values redacted.
	 *
	 * Sensitivity is detected from explicit declaration metadata and JSON-schema
	 * annotations, then backed by a conservative key-name fallback.
	 *
	 * @param array<mixed> $tool_parameters Raw tool-call parameters.
	 * @param array<mixed> $tool_definition Normalized tool declaration or schema.
	 * @return array<string,mixed> Redacted parameters.
	 */
	public static function redactedParameters( array $tool_parameters, array $tool_definition = array() ): array {
		$paths           = self::sensitiveParameterPaths( $tool_definition );
		$redacted        = self::redactValue( $tool_parameters, '', $paths );
		$normalized      = is_array( $redacted ) ? self::stringKeyedArray( $redacted ) : array();
		$normalized_keys = self::normalizeArrayKeys( $normalized );

		return is_array( $normalized_keys ) ? self::stringKeyedArray( $normalized_keys ) : array();
	}

	/**
	 * Resolve sensitive parameter paths from a tool declaration or JSON schema.
	 *
	 * @param array<mixed> $tool_definition Normalized tool declaration or schema.
	 * @return array<string,bool> Dot paths keyed to true.
	 */
	public static function sensitiveParameterPaths( array $tool_definition ): array {
		$paths = array();
		self::addSensitivePaths( $paths, $tool_definition['sensitive_parameters'] ?? array() );
		self::addSensitivePaths( $paths, $tool_definition['parameter_sensitivity'] ?? array() );
		foreach ( self::parameterBindings( $tool_definition ) as $parameter_name => $binding ) {
			if ( ! empty( $binding['sensitive'] ) ) {
				$paths[ $parameter_name ] = true;
			}
		}

		$schema = isset( $tool_definition['parameters'] ) && is_array( $tool_definition['parameters'] )
			? $tool_definition['parameters']
			: $tool_definition;
		self::collectSchemaPaths( $schema, '', $paths );

		return $paths;
	}

	/**
	 * Extract required parameter names from known declaration shapes.
	 *
	 * @param array<mixed> $tool_definition Normalized tool declaration.
	 * @return array<int, string>
	 */
	private static function requiredParameterNames( array $tool_definition ): array {
		$parameters = $tool_definition['parameters'] ?? array();
		if ( ! is_array( $parameters ) ) {
			return array();
		}

		$required = array();
		if ( isset( $parameters['required'] ) && is_array( $parameters['required'] ) ) {
			foreach ( $parameters['required'] as $parameter_name ) {
				if ( is_string( $parameter_name ) && '' !== $parameter_name ) {
					$required[] = $parameter_name;
				}
			}
		}

		$properties = $parameters['properties'] ?? $parameters;
		if ( is_array( $properties ) ) {
			foreach ( $properties as $parameter_name => $parameter_config ) {
				if ( is_string( $parameter_name ) && is_array( $parameter_config ) && ! empty( $parameter_config['required'] ) ) {
					$required[] = $parameter_name;
				}
			}
		}

		return array_values( array_unique( $required ) );
	}

	/**
	 * Add explicit meta-defined sensitive paths.
	 *
	 * @param array<string,bool> $paths Existing path map.
	 * @param mixed              $value Meta value.
	 */
	private static function addSensitivePaths( array &$paths, $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$paths[ trim( $item ) ] = true;
				continue;
			}

			if ( is_string( $key ) && true === $item && '' !== trim( $key ) ) {
				$paths[ trim( $key ) ] = true;
			}
		}
	}

	/**
	 * Collect sensitive paths from JSON schema property annotations.
	 *
	 * @param array<mixed>       $schema JSON-schema fragment.
	 * @param string             $path   Current dot path.
	 * @param array<string,bool> $paths  Sensitive path map.
	 */
	private static function collectSchemaPaths( array $schema, string $path, array &$paths ): void {
		if ( self::schemaMarksSensitive( $schema ) && '' !== $path ) {
			$paths[ $path ] = true;
		}

		$properties = $schema['properties'] ?? array();
		if ( is_array( $properties ) ) {
			foreach ( $properties as $name => $property_schema ) {
				if ( ! is_string( $name ) || ! is_array( $property_schema ) ) {
					continue;
				}

				self::collectSchemaPaths( $property_schema, '' === $path ? $name : $path . '.' . $name, $paths );
			}
		}

		$items = $schema['items'] ?? null;
		if ( is_array( $items ) ) {
			self::collectSchemaPaths( $items, '' === $path ? '*' : $path . '.*', $paths );
		}
	}

	/**
	 * Determine whether a schema node marks its value as sensitive.
	 *
	 * @param array<mixed> $schema JSON-schema fragment.
	 * @return bool
	 */
	private static function schemaMarksSensitive( array $schema ): bool {
		foreach ( array( 'sensitive', 'x-sensitive', 'secret', 'writeOnly' ) as $key ) {
			if ( true === ( $schema[ $key ] ?? false ) ) {
				return true;
			}
		}

		return isset( $schema['format'] ) && 'password' === $schema['format'];
	}

	/**
	 * Redact sensitive values in nested data.
	 *
	 * @param mixed              $value Value to redact.
	 * @param string             $path  Current dot path.
	 * @param array<string,bool> $paths Sensitive path map.
	 * @return mixed Redacted value.
	 */
	private static function redactValue( $value, string $path, array $paths ) {
		$key = self::lastPathSegment( $path );
		if ( '' !== $path && ( isset( $paths[ $path ] ) || self::sensitiveKey( $key ) ) ) {
			return self::REDACTED_VALUE;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$redacted = array();
		foreach ( $value as $item_key => $item_value ) {
			$segment   = is_string( $item_key ) ? $item_key : '*';
			$next_path = '' === $path ? $segment : $path . '.' . $segment;
			if ( ! isset( $paths[ $next_path ] ) && '*' !== $segment ) {
				$wildcard_path = '' === $path ? '*' : $path . '.*';
				if ( isset( $paths[ $wildcard_path ] ) ) {
					$next_path = $wildcard_path;
				}
			}

			$redacted[ $item_key ] = self::redactValue( $item_value, $next_path, $paths );
		}

		return $redacted;
	}

	/**
	 * Check a parameter key against conservative sensitive-name patterns.
	 *
	 * @param string $key Parameter key.
	 * @return bool
	 */
	private static function sensitiveKey( string $key ): bool {
		return '' !== $key && 1 === preg_match( '/(api[_-]?key|authorization|auth[_-]?token|bearer|cookie|credential|nonce|password|private[_-]?key|secret|session[_-]?id|token)/i', $key );
	}

	/**
	 * Return the final segment from a dot path.
	 *
	 * @param string $path Dot path.
	 * @return string Segment.
	 */
	private static function lastPathSegment( string $path ): string {
		$parts = explode( '.', $path );
		return (string) end( $parts );
	}

	/**
	 * Determine whether a value can be safely carried in JSON-friendly metadata.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private static function isJsonFriendlyValue( $value ): bool {
		if ( null === $value || is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return true;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ! self::isJsonFriendlyValue( $item ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Keep only string-keyed top-level parameters.
	 *
	 * @param array<array-key,mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function stringKeyedArray( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}

	/**
	 * Hash data after recursively sorting array keys for deterministic output.
	 *
	 * @param mixed $data Data to hash.
	 * @return string sha256-prefixed hash.
	 */
	private static function stableSha256( $data ): string {
		$normalized = self::normalizeArrayKeys( $data );
		$json       = wp_json_encode( $normalized );

		if ( ! is_string( $json ) ) {
			$json = serialize( $normalized );
		}

		return 'sha256:' . hash( 'sha256', $json );
	}

	/**
	 * Recursively sort associative array keys for deterministic hashing.
	 *
	 * @param mixed $data Data to normalize.
	 * @return mixed Normalized data.
	 */
	private static function normalizeArrayKeys( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$normalized = array();
		foreach ( $data as $key => $value ) {
			$normalized[ $key ] = self::normalizeArrayKeys( $value );
		}

		if ( self::isAssoc( $normalized ) ) {
			ksort( $normalized );
		}

		return $normalized;
	}

	/**
	 * Determine whether an array has non-sequential keys.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool
	 */
	private static function isAssoc( array $value ): bool {
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
