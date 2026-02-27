<?php
/**
 * SelectionMode — Three-mode selection pattern utility.
 *
 * Standardizes the skip / AI-decides / pre-selected pattern used across
 * handlers. Provides mode detection, settings field helpers, and sanitization.
 *
 * Usage:
 *   SelectionMode::detect( $value )          → SKIP | AI_DECIDES | PRE_SELECTED
 *   SelectionMode::isSkip( $value )          → bool
 *   SelectionMode::isAiDecides( $value )     → bool
 *   SelectionMode::isPreSelected( $value )   → bool
 *   SelectionMode::buildOptions(...)          → settings field options array
 *   SelectionMode::sanitize(...)             → sanitized value
 *
 * @package DataMachine\Core\Selection
 * @since 0.33.0
 */

namespace DataMachine\Core\Selection;

defined( 'ABSPATH' ) || exit;

class SelectionMode {

	/**
	 * Mode constants.
	 */
	const SKIP         = 'skip';
	const AI_DECIDES   = 'ai_decides';
	const PRE_SELECTED = 'pre_selected';

	/**
	 * Reserved mode values that are not pre-selected values.
	 *
	 * @var array
	 */
	const RESERVED_MODES = array( 'skip', 'ai_decides' );

	/**
	 * Detect the selection mode from a raw value.
	 *
	 * @param mixed $value The selection value from handler config.
	 * @return string One of SKIP, AI_DECIDES, or PRE_SELECTED.
	 */
	public static function detect( $value ): string {
		if ( empty( $value ) || self::SKIP === $value ) {
			return self::SKIP;
		}

		if ( self::AI_DECIDES === $value ) {
			return self::AI_DECIDES;
		}

		return self::PRE_SELECTED;
	}

	/**
	 * Check if the value means "skip this selection entirely".
	 *
	 * @param mixed $value Selection value.
	 * @return bool
	 */
	public static function isSkip( $value ): bool {
		return self::SKIP === self::detect( $value );
	}

	/**
	 * Check if the value means "let AI decide at runtime".
	 *
	 * @param mixed $value Selection value.
	 * @return bool
	 */
	public static function isAiDecides( $value ): bool {
		return self::AI_DECIDES === self::detect( $value );
	}

	/**
	 * Check if the value is a pre-selected concrete value.
	 *
	 * @param mixed $value Selection value.
	 * @return bool
	 */
	public static function isPreSelected( $value ): bool {
		return self::PRE_SELECTED === self::detect( $value );
	}

	/**
	 * Build a settings field options array with standard mode options
	 * followed by a separator and domain-specific options.
	 *
	 * @param array $mode_options  Mode options (e.g., ['skip' => 'Skip', 'ai_decides' => 'AI Decides']).
	 * @param array $value_options Domain-specific pre-select options (e.g., term_id => term_name).
	 * @return array Combined options array for a select field.
	 */
	public static function buildOptions( array $mode_options, array $value_options = array() ): array {
		$options = $mode_options;

		if ( ! empty( $value_options ) ) {
			$options['separator'] = '──────────';
			$options              = $options + $value_options;
		}

		return $options;
	}

	/**
	 * Get default mode options with translatable labels.
	 *
	 * @return array Mode options with 'skip' and 'ai_decides'.
	 */
	public static function getDefaultModeOptions(): array {
		return array(
			self::SKIP       => esc_html__( 'Skip', 'data-machine' ),
			self::AI_DECIDES => esc_html__( 'AI Decides', 'data-machine' ),
		);
	}

	/**
	 * Sanitize a selection value.
	 *
	 * If the value is a reserved mode (skip/ai_decides), returns it directly.
	 * Otherwise, validates the value as a pre-selected option using the
	 * provided callback. Falls back to the default value on invalid input.
	 *
	 * @param mixed    $value             Raw value to sanitize.
	 * @param callable $validate_callback Callback to validate pre-selected values.
	 *                                    Receives the raw value, returns sanitized value or null.
	 * @param string   $default           Default value if validation fails (default: 'skip').
	 * @return mixed Sanitized value.
	 */
	public static function sanitize( $value, callable $validate_callback, string $default = 'skip' ) {
		// Reserved modes pass through directly.
		if ( in_array( $value, self::RESERVED_MODES, true ) ) {
			return $value;
		}

		// Empty values fall back to default.
		if ( empty( $value ) ) {
			return $default;
		}

		// Validate the pre-selected value via callback.
		$validated = $validate_callback( $value );

		return null !== $validated ? $validated : $default;
	}

	/**
	 * Process a list of selectable fields using the three-mode pattern.
	 *
	 * Iterates through fields, detects each mode, and calls the appropriate
	 * handler callback. Skips fields in SKIP mode automatically.
	 *
	 * @param array    $fields          Array of field_key => raw_selection_value.
	 * @param callable $ai_callback     Called for AI_DECIDES fields: fn(string $field_key) → mixed|null.
	 * @param callable $preset_callback Called for PRE_SELECTED fields: fn(string $field_key, mixed $value) → mixed|null.
	 * @return array Results keyed by field_key (only non-null results included).
	 */
	public static function processFields( array $fields, callable $ai_callback, callable $preset_callback ): array {
		$results = array();

		foreach ( $fields as $field_key => $value ) {
			$mode = self::detect( $value );

			if ( self::SKIP === $mode ) {
				continue;
			}

			$result = null;

			if ( self::AI_DECIDES === $mode ) {
				$result = $ai_callback( $field_key );
			} elseif ( self::PRE_SELECTED === $mode ) {
				$result = $preset_callback( $field_key, $value );
			}

			if ( null !== $result ) {
				$results[ $field_key ] = $result;
			}
		}

		return $results;
	}
}
