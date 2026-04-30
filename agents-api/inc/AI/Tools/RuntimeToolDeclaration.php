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
class RuntimeToolDeclaration {

	public const SOURCE_CLIENT   = 'client';
	public const EXECUTOR_CLIENT = 'client';
	public const SCOPE_RUN       = 'run';

	/**
	 * Normalize a runtime tool declaration or throw a field-scoped error.
	 *
	 * @param array $declaration Raw runtime tool declaration.
	 * @return array Normalized declaration.
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

		$name   = (string) $declaration['name'];
		$source = self::sourceFromName( $name );

		return array(
			'name'        => $name,
			'source'      => $source,
			'description' => trim( (string) $declaration['description'] ),
			'parameters'  => $declaration['parameters'] ?? array(),
			'executor'    => self::EXECUTOR_CLIENT,
			'scope'       => self::SCOPE_RUN,
		);
	}

	/**
	 * Validate a runtime tool declaration without throwing.
	 *
	 * @param array $declaration Raw runtime tool declaration.
	 * @return string[] Machine-readable invalid field names.
	 */
	public static function validate( array $declaration ): array {
		$errors = array();

		$name = $declaration['name'] ?? null;
		if (
			! is_string( $name )
			|| '' === $name
			|| ! preg_match( '/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/', $name )
		) {
			$errors[] = 'name';
		}

		$source = is_string( $name ) ? self::sourceFromName( $name ) : '';
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

		return array_values( array_unique( $errors ) );
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
