<?php
/**
 * Base Settings Handler with Auto-Sanitization
 *
 * Provides automatic field sanitization based on field schema, eliminating
 * duplicated sanitization code across all handler Settings classes.
 *
 * @package DataMachine\Core\Steps
 * @since 0.2.1
 */

namespace DataMachine\Core\Steps\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SettingsHandler {

	/**
	 * Get settings fields for the handler.
	 *
	 * Must be implemented by child classes to define their field schema.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	abstract public static function get_fields(): array;

	/**
	 * Sanitize handler settings based on field schema.
	 *
	 * Automatically sanitizes all fields based on their type definition.
	 * Child classes can override this method for complex sanitization logic,
	 * and call parent::sanitize() to handle simple fields automatically.
	 *
	 * @param array $raw_settings Raw settings input from user.
	 * @return array Sanitized settings.
	 * @throws \InvalidArgumentException If required field is missing.
	 */
	public static function sanitize( array $raw_settings ): array {
		$fields    = static::get_fields();
		$sanitized = array();

		foreach ( $fields as $key => $config ) {
			$sanitized[ $key ] = self::sanitizeField( $raw_settings, $key, $config );
		}

		return $sanitized;
	}

	/**
	 * Sanitize a single field based on its type configuration.
	 *
	 * @param array  $raw_settings Raw settings array.
	 * @param string $key          Field key.
	 * @param array  $config       Field configuration array.
	 * @return mixed Sanitized field value.
	 * @throws \InvalidArgumentException If required field is missing.
	 */
	protected static function sanitizeField( array $raw_settings, string $key, array $config ) {
		$type     = $config['type'] ?? 'text';
		$default  = $config['default'] ?? '';
		$required = $config['required'] ?? false;

		// Check required fields
		if ( $required && empty( $raw_settings[ $key ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: Field key */
					esc_html__( '%s is required.', 'data-machine' ),
					esc_html( $key )
				)
			);
		}

		// Type-based sanitization
		switch ( $type ) {
			case 'url':
				return self::sanitizeUrl( $raw_settings, $key, $default );

			case 'url_list':
				return self::sanitizeUrlList( $raw_settings, $key );

			case 'checkbox':
				return self::sanitizeCheckbox( $raw_settings, $key );

			case 'select':
				return self::sanitizeSelect( $raw_settings, $key, $config );

			case 'number':
				return self::sanitizeNumber( $raw_settings, $key, $config );

			case 'textarea':
				return self::sanitizeTextarea( $raw_settings, $key, $default );

			case 'text':
			default:
				return self::sanitizeText( $raw_settings, $key, $default );
		}
	}

	/**
	 * Sanitize text field.
	 *
	 * @param array  $raw_settings Raw settings array.
	 * @param string $key          Field key.
	 * @param mixed  $default      Default value.
	 * @return string Sanitized text value.
	 */
	protected static function sanitizeText( array $raw_settings, string $key, $default_value ): string {
		$value = $raw_settings[ $key ] ?? $default_value;
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Sanitize URL field.
	 *
	 * @param array  $raw_settings Raw settings array.
	 * @param string $key          Field key.
	 * @param mixed  $default      Default value.
	 * @return string Sanitized URL value.
	 */
	protected static function sanitizeUrl( array $raw_settings, string $key, $default_value ): string {
		$value = $raw_settings[ $key ] ?? $default_value;
		return esc_url_raw( wp_unslash( $value ) );
	}

	/**
	 * Sanitize URL list field (array of URLs).
	 *
	 * @param array  $raw_settings Raw settings array.
	 * @param string $key          Field key.
	 * @return array Sanitized array of URLs.
	 */
	protected static function sanitizeUrlList( array $raw_settings, string $key ): array {
		$value = $raw_settings[ $key ] ?? array();

		// Handle string input (newline-separated URLs for backward compat)
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $value as $url ) {
			$url = esc_url_raw( wp_unslash( trim( $url ) ) );
			if ( ! empty( $url ) ) {
				$sanitized[] = $url;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize textarea field.
	 *
	 * @param array  $raw_settings Raw settings array.
	 * @param string $key          Field key.
	 * @param mixed  $default      Default value.
	 * @return string Sanitized textarea value.
	 */
	protected static function sanitizeTextarea( array $raw_settings, string $key, $default_value ): string {
		$value = $raw_settings[ $key ] ?? $default_value;
		return sanitize_textarea_field( wp_unslash( $value ) );
	}

	/**
	 * Sanitize checkbox field.
	 *
	 * @param array  $raw_settings Raw settings array.
	 * @param string $key          Field key.
	 * @return bool Checkbox value as boolean.
	 */
	protected static function sanitizeCheckbox( array $raw_settings, string $key ): bool {
		return isset( $raw_settings[ $key ] ) && '1' === $raw_settings[ $key ];
	}

	/**
	 * Sanitize select field with option validation.
	 *
	 * @param array  $raw_settings Raw settings array.
	 * @param string $key          Field key.
	 * @param array  $config       Field configuration with 'options' and 'default'.
	 * @return mixed Validated select value or default.
	 */
	protected static function sanitizeSelect( array $raw_settings, string $key, array $config ) {
		$default = $config['default'] ?? '';
		$value   = $raw_settings[ $key ] ?? $default;
		$options = $config['options'] ?? array();

		// Get valid option keys
		$allowed_values = is_array( $options ) ? array_keys( $options ) : array();

		// Validate against allowed values
		if ( in_array( $value, $allowed_values, true ) ) {
			return $value;
		}

		return $default;
	}

	/**
	 * Sanitize number field with min/max validation.
	 *
	 * @param array  $raw_settings Raw settings array.
	 * @param string $key          Field key.
	 * @param array  $config       Field configuration with optional 'min', 'max', 'default'.
	 * @return int Sanitized integer value within constraints.
	 */
	protected static function sanitizeNumber( array $raw_settings, string $key, array $config ): int {
		$default = $config['default'] ?? 0;
		$min     = $config['min'] ?? null;
		$max     = $config['max'] ?? null;

		$value = isset( $raw_settings[ $key ] ) ? absint( $raw_settings[ $key ] ) : $default;

		// Apply min constraint
		if ( null !== $min && $value < $min ) {
			$value = $min;
		}

		// Apply max constraint
		if ( null !== $max && $value > $max ) {
			$value = $max;
		}

		return $value;
	}
}
