<?php
/**
 * Runtime tool declaration validator.
 *
 * Runtime tools are declared by a client or transport for one agent run and
 * are executed by the client. This class only validates the declaration
 * shape; it intentionally does not register, expose, or execute those tools.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Validates scoped runtime tool declarations before policy integration.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Tool_Declaration {

	public const SOURCE_CLIENT   = 'client';
	public const EXECUTOR_CLIENT = 'client';
	public const EXECUTOR_HOST   = 'host';
	public const SCOPE_RUN       = 'run';

	/**
	 * Fields owned by the canonical declaration envelope.
	 */
	private const CANONICAL_FIELDS = array(
		'name'        => true,
		'source'      => true,
		'description' => true,
		'parameters'  => true,
		'executor'    => true,
		'scope'       => true,
		'runtime'     => true,
	);

	/**
	 * Generic runtime metadata key for duplicate-call behavior.
	 */
	public const RUNTIME_DUPLICATE_POLICY = 'duplicate_policy';

	/**
	 * Generic runtime metadata key for progress/completion signaling.
	 */
	public const RUNTIME_COMPLETION_SIGNAL = 'completion_signal';

	/**
	 * Generic runtime metadata key for where a tool may be exposed.
	 */
	public const RUNTIME_CAPABILITY_SCOPE = 'capability_scope';

	/**
	 * Tool may be exposed inside a delegated runtime.
	 */
	public const CAPABILITY_SCOPE_RUNTIME_LOCAL = 'runtime_local';

	/**
	 * Tool belongs to the parent/control-plane runtime.
	 */
	public const CAPABILITY_SCOPE_CONTROL_PLANE = 'control_plane';

	/**
	 * Generic runtime metadata key for the target execution environment.
	 */
	public const RUNTIME_ENVIRONMENT = 'environment';

	/**
	 * Tool declaration targets a delegated runtime.
	 */
	public const ENVIRONMENT_RUNTIME_LOCAL = 'runtime_local';

	/**
	 * Tool declaration targets a parent/control-plane runtime.
	 */
	public const ENVIRONMENT_CONTROL_PLANE = 'control_plane';

	/**
	 * Normalize a runtime tool declaration or throw a field-scoped error.
	 *
	 * @param array<mixed> $declaration Raw runtime tool declaration.
	 * @return array<mixed> Normalized declaration.
	 */
	public static function normalize( array $declaration ): array {
		$errors = self::validate( $declaration );
		if ( ! empty( $errors ) ) {
			$message = sprintf(
				'invalid_runtime_tool_declaration: %s',
				implode( ', ', self::sanitizeErrorKeys( $errors ) )
			);

			throw new \InvalidArgumentException(
				$message
			);
		}

		$name   = is_string( $declaration['name'] ?? null ) ? $declaration['name'] : '';
		$source = self::sourceFromName( $name );
		if ( '' === $source && is_string( $declaration['source'] ?? null ) ) {
			$source = trim( $declaration['source'] );
		}
		if ( '' === $source ) {
			$source = self::SOURCE_CLIENT;
		}
		$description = $declaration['description'] ?? '';

		$normalized = array(
			'name'        => $name,
			'source'      => $source,
			'description' => is_string( $description ) ? trim( $description ) : '',
			'parameters'  => $declaration['parameters'] ?? array(),
			'executor'    => self::EXECUTOR_CLIENT,
			'scope'       => self::SCOPE_RUN,
		);

		$provider_safe_name = self::providerSafeName( $name );
		if ( $provider_safe_name !== $name ) {
			$normalized['provider_safe_name'] = $provider_safe_name;
		}

		$runtime = self::normalizeRuntimeMetadata( $declaration['runtime'] ?? array() );
		if ( ! empty( $runtime ) ) {
			$normalized['runtime'] = $runtime;
		}

		$normalized = array_merge( $normalized, self::normalizeExtensionFields( $declaration ), self::normalizeParameterBindingFields( $declaration ) );

		return $normalized;
	}

	/**
	 * Normalize a conversation-request tool declaration.
	 *
	 * Client runtime tools keep the strict `normalize()` contract. Host-owned
	 * declarations are request/replay catalog entries for tools executed by the
	 * host runtime rather than the client transport.
	 *
	 * @param array<mixed> $declaration Raw request tool declaration.
	 * @return array<mixed> Normalized declaration.
	 */
	public static function normalizeForConversationRequest( array $declaration ): array {
		$name     = is_string( $declaration['name'] ?? null ) ? $declaration['name'] : '';
		$executor = $declaration['executor'] ?? null;

		if ( self::EXECUTOR_CLIENT === $executor || self::SOURCE_CLIENT === self::sourceFromName( $name ) ) {
			$declaration = self::applyClientRequestDefaults( $declaration );
			return self::normalize( $declaration );
		}

		try {
			return self::normalizeForServer( $declaration );
		} catch ( \InvalidArgumentException $error ) {
			throw new \InvalidArgumentException(
				str_replace( 'invalid_server_tool_declaration:', 'invalid_conversation_tool_declaration:', $error->getMessage() )
			);
		}
	}

	/**
	 * Normalize a host/server-mediated tool declaration.
	 *
	 * Server declarations describe model-facing tools that the host mediates via
	 * `WP_Agent_Tool_Executor`. The declaration is intentionally neutral: Agents
	 * API validates the envelope, while the host still owns concrete execution,
	 * authorization, and product-specific routing.
	 *
	 * @param array<mixed> $declaration Raw server tool declaration.
	 * @return array<mixed> Normalized declaration.
	 */
	public static function normalizeForServer( array $declaration ): array {
		$declaration = self::applyServerDefaults( $declaration );

		$errors = self::validateServerDeclaration( $declaration );
		if ( ! empty( $errors ) ) {
			$message = sprintf(
				'invalid_server_tool_declaration: %s',
				implode( ', ', self::sanitizeErrorKeys( $errors ) )
			);

			throw new \InvalidArgumentException(
				$message
			);
		}

		$name        = is_string( $declaration['name'] ?? null ) ? $declaration['name'] : '';
		$source      = $declaration['source'] ?? '';
		$description = $declaration['description'] ?? '';

		$normalized = array(
			'name'        => $name,
			'source'      => is_string( $source ) ? trim( $source ) : '',
			'description' => is_string( $description ) ? trim( $description ) : '',
			'parameters'  => $declaration['parameters'] ?? array(),
			'executor'    => self::EXECUTOR_HOST,
			'scope'       => self::SCOPE_RUN,
		);

		$provider_safe_name = self::providerSafeName( $name );
		if ( $provider_safe_name !== $name ) {
			$normalized['provider_safe_name'] = $provider_safe_name;
		}

		$runtime = self::normalizeRuntimeMetadata( $declaration['runtime'] ?? array() );
		if ( ! empty( $runtime ) ) {
			$normalized['runtime'] = $runtime;
		}

		$normalized = array_merge( $normalized, self::normalizeExtensionFields( $declaration ), self::normalizeParameterBindingFields( $declaration ) );

		return $normalized;
	}

	/**
	 * Validate a runtime tool declaration without throwing.
	 *
	 * @param array<mixed> $declaration Raw runtime tool declaration.
	 * @return string[] Machine-readable invalid field names.
	 */
	public static function validate( array $declaration ): array {
		$errors = array();

		$name = $declaration['name'] ?? null;
		if ( ! is_string( $name ) || '' === $name || ! self::isValidHostToolName( $name ) ) {
			$errors[] = 'name';
		}

		$source = is_string( $name ) ? self::sourceFromName( $name ) : '';
		if ( '' === $source && is_string( $declaration['source'] ?? null ) ) {
			$source = trim( $declaration['source'] );
		}
		if ( '' === $source ) {
			$source = self::SOURCE_CLIENT;
		}
		if ( self::SOURCE_CLIENT !== $source ) {
			$errors[] = 'source';
		}

		if (
			isset( $declaration['source'] )
			&& $declaration['source'] !== $source
		) {
			$errors[] = 'source';
		}

		$description = $declaration['description'] ?? null;
		if ( ! is_string( $description ) || '' === trim( $description ) ) {
			$errors[] = 'description';
		}

		if (
			isset( $declaration['parameters'] )
			&& ! is_array( $declaration['parameters'] )
		) {
			$errors[] = 'parameters';
		}

		if ( ( $declaration['executor'] ?? null ) !== self::EXECUTOR_CLIENT ) {
			$errors[] = 'executor';
		}

		if ( ( $declaration['scope'] ?? null ) !== self::SCOPE_RUN ) {
			$errors[] = 'scope';
		}

		if ( isset( $declaration['runtime'] ) && ! is_array( $declaration['runtime'] ) ) {
			$errors[] = 'runtime';
		}

		$errors = array_merge( $errors, self::validateParameterBindingFields( $declaration ) );

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Validate a host-owned request/replay tool declaration without throwing.
	 *
	 * @param array<mixed> $declaration Raw host declaration.
	 * @return string[] Machine-readable invalid field names.
	 */
	public static function validateServerDeclaration( array $declaration ): array {
		$errors = array();

		$name = $declaration['name'] ?? null;
		if ( ! is_string( $name ) || '' === $name || ! self::isValidHostToolName( $name ) ) {
			$errors[] = 'name';
		}

		if ( self::SOURCE_CLIENT === ( is_string( $name ) ? self::sourceFromName( $name ) : '' ) ) {
			$errors[] = 'source';
		}

		$source = $declaration['source'] ?? null;
		if (
			! is_string( $source )
			|| '' === $source
			|| ! preg_match( '/^[a-z][a-z0-9_-]*$/', $source )
		) {
			$errors[] = 'source';
		}

		$description = $declaration['description'] ?? null;
		if ( ! is_string( $description ) || '' === trim( $description ) ) {
			$errors[] = 'description';
		}

		if (
			isset( $declaration['parameters'] )
			&& ! is_array( $declaration['parameters'] )
		) {
			$errors[] = 'parameters';
		}

		if ( ( $declaration['executor'] ?? null ) !== self::EXECUTOR_HOST ) {
			$errors[] = 'executor';
		}

		if ( ( $declaration['scope'] ?? null ) !== self::SCOPE_RUN ) {
			$errors[] = 'scope';
		}

		if ( isset( $declaration['runtime'] ) && ! is_array( $declaration['runtime'] ) ) {
			$errors[] = 'runtime';
		}

		$errors = array_merge( $errors, self::validateParameterBindingFields( $declaration ) );

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Apply server declaration defaults before validation.
	 *
	 * @param array<mixed> $declaration Raw declaration.
	 * @return array<mixed> Declaration with server defaults.
	 */
	private static function applyServerDefaults( array $declaration ): array {
		$name = is_string( $declaration['name'] ?? null ) ? $declaration['name'] : '';
		if ( ! isset( $declaration['source'] ) || ! is_string( $declaration['source'] ) || '' === $declaration['source'] ) {
			$declaration['source'] = self::sourceFromName( $name ) ?: 'host';
		}

		if ( ! isset( $declaration['parameters'] ) ) {
			$declaration['parameters'] = array();
		}

		if ( ! isset( $declaration['executor'] ) || self::EXECUTOR_CLIENT !== $declaration['executor'] ) {
			$declaration['executor'] = self::EXECUTOR_HOST;
		}

		if ( ! isset( $declaration['scope'] ) ) {
			$declaration['scope'] = self::SCOPE_RUN;
		}

		return $declaration;
	}

	/**
	 * Apply compatibility defaults for existing client tools at request/catalog boundaries.
	 *
	 * The low-level `normalize()` contract remains strict. Conversation/request
	 * ingestion accepts older `client/*` catalog declarations that omitted fields
	 * already implied by the tool name and loop context.
	 *
	 * @param array<mixed> $declaration Raw declaration.
	 * @return array<mixed> Declaration with request/catalog defaults.
	 */
	private static function applyClientRequestDefaults( array $declaration ): array {
		$name = is_string( $declaration['name'] ?? null ) ? $declaration['name'] : '';

		if ( ! isset( $declaration['source'] ) ) {
			$declaration['source'] = self::SOURCE_CLIENT;
		}

		if ( ! isset( $declaration['description'] ) || ! is_string( $declaration['description'] ) || '' === trim( $declaration['description'] ) ) {
			$declaration['description'] = $name;
		}

		if ( ! isset( $declaration['parameters'] ) ) {
			$declaration['parameters'] = array();
		}

		if ( ! isset( $declaration['executor'] ) ) {
			$declaration['executor'] = self::EXECUTOR_CLIENT;
		}

		if ( ! isset( $declaration['scope'] ) ) {
			$declaration['scope'] = self::SCOPE_RUN;
		}

		return $declaration;
	}

	/**
	 * Preserve JSON-friendly, non-envelope fields used by generic tool mediation.
	 *
	 * @param array<mixed> $declaration Raw declaration.
	 * @return array<string, mixed> Normalized extension fields.
	 */
	private static function normalizeExtensionFields( array $declaration ): array {
		$extensions = array();
		foreach ( $declaration as $key => $value ) {
			if ( ! is_string( $key ) || isset( self::CANONICAL_FIELDS[ $key ] ) ) {
				continue;
			}
			if ( 'parameter_bindings' === $key || 'parameter_defaults' === $key ) {
				continue;
			}

			$normalized_value = self::normalizeRuntimeMetadataValue( $value );
			if ( null !== $normalized_value ) {
				$extensions[ $key ] = $normalized_value;
			}
		}

		return $extensions;
	}

	/**
	 * Validate parameter binding/default metadata.
	 *
	 * @param array<mixed> $declaration Raw declaration.
	 * @return string[] Machine-readable invalid field names.
	 */
	private static function validateParameterBindingFields( array $declaration ): array {
		$errors = array();

		try {
			WP_Agent_Tool_Parameters::normalizeParameterBindings( $declaration );
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
			$errors[] = array_key_exists( 'parameter_bindings', $declaration ) ? 'parameter_bindings' : 'client_context_bindings';
		}

		try {
			WP_Agent_Tool_Parameters::normalizeParameterDefaults( $declaration );
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
			$errors[] = 'parameter_defaults';
		}

		return $errors;
	}

	/**
	 * Normalize validated parameter binding/default metadata.
	 *
	 * @param array<mixed> $declaration Raw declaration.
	 * @return array<string,mixed> Normalized parameter metadata.
	 */
	private static function normalizeParameterBindingFields( array $declaration ): array {
		$fields   = array();
		$bindings = WP_Agent_Tool_Parameters::normalizeParameterBindings( $declaration );
		if ( ! empty( $bindings ) ) {
			$fields['parameter_bindings'] = $bindings;
		}

		$defaults = WP_Agent_Tool_Parameters::normalizeParameterDefaults( $declaration );
		if ( ! empty( $defaults ) ) {
			$fields['parameter_defaults'] = $defaults;
		}

		return $fields;
	}

	/**
	 * Normalize optional product-neutral runtime metadata.
	 *
	 * Runtime metadata is a JSON-friendly object used by agent loops and hosts to
	 * make generic execution decisions without hardcoding product tool names. The
	 * canonical keys are `duplicate_policy` and `completion_signal`, but callers
	 * may include additional product-neutral scalar/list values for future policy.
	 *
	 * @param mixed $runtime Raw runtime metadata.
	 * @return array<string, mixed> Normalized runtime metadata.
	 */
	public static function normalizeRuntimeMetadata( $runtime ): array {
		if ( ! is_array( $runtime ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $runtime as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( WP_Agent_Tool_Parameters::sensitiveKey( $key ) ) {
				$normalized[ $key ] = WP_Agent_Tool_Parameters::REDACTED_VALUE;
				continue;
			}

			$normalized_value = self::normalizeRuntimeMetadataValue( $value );
			if ( null === $normalized_value ) {
				continue;
			}

			$normalized[ $key ] = $normalized_value;
		}

		return $normalized;
	}

	/**
	 * Normalize one JSON-friendly runtime metadata value.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return mixed|null Normalized value, or null when unsupported.
	 */
	private static function normalizeRuntimeMetadataValue( $value ) {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$normalized_item = self::normalizeRuntimeMetadataValue( $item );
			if ( null === $normalized_item ) {
				continue;
			}

			if ( is_string( $key ) ) {
				$normalized[ $key ] = $normalized_item;
			} else {
				$normalized[] = $normalized_item;
			}
		}

		return $normalized;
	}

	/**
	 * Build a namespaced runtime tool name.
	 *
	 * @param string $source Runtime tool source slug.
	 * @param string $tool_slug Tool slug local to the source.
	 * @return string Namespaced tool name.
	 */
	public static function namespacedName( string $source, string $tool_slug ): string {
		return $source . '/' . $tool_slug;
	}

	/**
	 * Build a provider-safe alias for a canonical tool name.
	 *
	 * Some provider APIs reject namespaced names containing `/`. The alias is not
	 * canonical; it is a transport-safe identifier that callers can map back to the
	 * normalized declaration before executing a tool.
	 *
	 * @param string $name Canonical tool name.
	 * @return string Provider-safe tool alias.
	 */
	public static function providerSafeName( string $name ): string {
		$alias = preg_replace( '/[^A-Za-z0-9_]+/', '__', trim( $name ) );
		$alias = is_string( $alias ) ? trim( $alias, '_' ) : '';
		if ( '' === $alias ) {
			return 'tool';
		}

		return 1 === preg_match( '/^[A-Za-z]/', $alias ) ? $alias : 'tool_' . $alias;
	}

	/**
	 * Resolve a provider-emitted tool name to the canonical declaration name.
	 *
	 * @param string       $tool_name Tool name from the provider or caller.
	 * @param array<mixed> $available_tools Normalized tool declarations keyed by canonical name.
	 * @return string Canonical tool name when found, otherwise the original name.
	 */
	public static function canonicalNameForProviderToolName( string $tool_name, array $available_tools ): string {
		if ( isset( $available_tools[ $tool_name ] ) && is_array( $available_tools[ $tool_name ] ) ) {
			return $tool_name;
		}

		foreach ( $available_tools as $canonical_name => $tool_definition ) {
			if ( ! is_string( $canonical_name ) || ! is_array( $tool_definition ) ) {
				continue;
			}

			$aliases = array( $tool_definition['provider_safe_name'] ?? self::providerSafeName( $canonical_name ) );
			if ( is_array( $tool_definition['provider_aliases'] ?? null ) ) {
				$aliases = array_merge( $aliases, $tool_definition['provider_aliases'] );
			}

			foreach ( $aliases as $alias ) {
				if ( is_string( $alias ) && $tool_name === $alias ) {
					return $canonical_name;
				}
			}
		}

		return $tool_name;
	}

	/**
	 * Extract the source prefix from a namespaced runtime tool name.
	 *
	 * @param string $name Runtime tool name.
	 * @return string Source prefix, or empty string when unnamespaced.
	 */
	public static function sourceFromName( string $name ): string {
		$parts = explode( '/', $name, 2 );
		return count( $parts ) === 2 ? $parts[0] : '';
	}

	/**
	 * Whether a host-mediated tool name is valid.
	 *
	 * @param string $name Tool name.
	 * @return bool Whether the name is valid.
	 */
	private static function isValidHostToolName( string $name ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_-]*$/', $name )
			|| 1 === preg_match( '/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/', $name );
	}

	/**
	 * Sanitize validator field names without requiring WordPress functions.
	 *
	 * @param string[] $errors Raw validator field names.
	 * @return string[] Sanitized field names.
	 */
	private static function sanitizeErrorKeys( array $errors ): array {
		return array_map(
			static function ( string $error ): string {
				$sanitized = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $error ) );
				return is_string( $sanitized ) ? $sanitized : '';
			},
			$errors
		);
	}
}
