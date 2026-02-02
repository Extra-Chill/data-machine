<?php
/**
 * Settings Display Service
 *
 * Handles the complex logic for displaying handler settings in the UI.
 * Moved from filter-based implementation to proper OOP service class.
 *
 * @package DataMachine\Core\Steps\Settings
 * @since 0.2.1
 */

namespace DataMachine\Core\Steps\Settings;

use DataMachine\Abilities\HandlerAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Display Service
 *
 * Processes handler settings for UI display with smart formatting,
 * label generation, and value transformation.
 */
class SettingsDisplayService {

	/**
	 * Get formatted settings display for a flow step.
	 *
	 * @param string $flow_step_id Flow step ID to get settings for (format: {pipeline_step_id}_{flow_id})
	 * @param string $step_type Step type (for step types with usesHandler: false)
	 * @return array Formatted settings display array
	 */
	public function getDisplaySettings( string $flow_step_id, string $step_type ): array {
		// Get flow step configuration
		$db_flows         = new \DataMachine\Core\Database\Flows\Flows();
		$flow_step_config = $db_flows->get_flow_step_config( $flow_step_id );
		if ( empty( $flow_step_config ) ) {
			return array();
		}

		$handler_slug     = $flow_step_config['handler_slug'] ?? '';
		$current_settings = $flow_step_config['handler_config'] ?? array();

		// For step types with usesHandler: false, fall back to step_type as settings key
		// This allows steps like agent_ping to display their config without a traditional handler
		if ( empty( $handler_slug ) && ! empty( $step_type ) ) {
			$handler_slug = $step_type;
		}

		if ( empty( $handler_slug ) || empty( $current_settings ) ) {
			return array();
		}

		// Get handler Settings class via cached service
		$handler_abilities = new HandlerAbilities();
		$handler_settings  = $handler_abilities->getSettingsClass( $handler_slug );

		if ( ! $handler_settings || ! method_exists( $handler_settings, 'get_fields' ) ) {
			return array();
		}

		// Get field definitions
		$fields = $handler_settings::get_fields();

		return $this->buildDisplayArray( $fields, $current_settings );
	}

	/**
	 * Build the display array from field definitions and current settings.
	 *
	 * @param array $fields Field definitions from settings class
	 * @param array $current_settings Current settings values
	 * @return array Formatted display array
	 */
	private function buildDisplayArray( array $fields, array $current_settings ): array {
		$acronyms         = $this->getAcronymMappings();
		$settings_display = array();

		// Check if we have a primary venue selected to suppress manual fields in summary
		$has_primary_venue   = ! empty( $current_settings['venue'] );
		$venue_manual_fields = array(
			'venue_name',
			'venue_address',
			'venue_city',
			'venue_state',
			'venue_zip',
			'venue_country',
			'venue_phone',
			'venue_website',
			'venue_capacity',
		);

		// Iterate through fields to respect Settings class order
		foreach ( $fields as $key => $field_config ) {
			// Check if this field has a value in current settings
			if ( ! isset( $current_settings[ $key ] ) ) {
				continue;
			}

			// Suppress manual venue fields if a primary venue term is selected
			if ( $has_primary_venue && in_array( $key, $venue_manual_fields, true ) ) {
				continue;
			}

			$value = $current_settings[ $key ];

			// Skip if no value
			if ( '' === $value || null === $value ) {
				continue;
			}

			$label         = $this->generateFieldLabel( $key, $field_config, $acronyms );
			$display_value = $this->formatDisplayValue( $value, $field_config );

			$settings_display[] = array(
				'key'           => $key,
				'label'         => $label,
				'value'         => $value,
				'display_value' => $display_value,
			);
		}

		return $settings_display;
	}

	/**
	 * Get acronym mappings for smart label generation.
	 *
	 * @return array Acronym mappings
	 */
	private function getAcronymMappings(): array {
		return array(
			'ai'   => 'AI',
			'api'  => 'API',
			'url'  => 'URL',
			'id'   => 'ID',
			'seo'  => 'SEO',
			'rss'  => 'RSS',
			'html' => 'HTML',
			'css'  => 'CSS',
			'json' => 'JSON',
			'xml'  => 'XML',
		);
	}

	/**
	 * Generate a smart label from field key and config.
	 *
	 * @param string $key Field key
	 * @param array  $field_config Field configuration
	 * @param array  $acronyms Acronym mappings
	 * @return string Generated label
	 */
	private function generateFieldLabel( string $key, array $field_config, array $acronyms ): string {
		// Use field label if available
		if ( ! empty( $field_config['label'] ) ) {
			return $field_config['label'];
		}

		// Generate smart label from key
		$label_words = explode( '_', $key );
		$label_words = array_map(
			function ( $word ) use ( $acronyms ) {
				$word_lower = strtolower( $word );
				return $acronyms[ $word_lower ] ?? ucfirst( $word );
			},
			$label_words
		);

		return implode( ' ', $label_words );
	}

	/**
	 * Get field state for API consumption.
	 *
	 * Provides field schema with current values and formatted options.
	 * Frontend looks up display labels from options as needed.
	 *
	 * @param string $handler_slug Handler slug to get fields for
	 * @param array  $current_settings Current saved settings (optional)
	 * @return array Field state array
	 */
	public function getFieldState( string $handler_slug, array $current_settings = array() ): array {
		// Get handler Settings class via cached service
		$handler_abilities = new HandlerAbilities();
		$handler_settings  = $handler_abilities->getSettingsClass( $handler_slug );

		if ( ! $handler_settings || ! method_exists( $handler_settings, 'get_fields' ) ) {
			return array();
		}

		// Get field definitions
		$fields = $handler_settings::get_fields();

		$field_state = array();
		foreach ( $fields as $key => $field_config ) {
			// Get current value (saved setting or default)
			$current_value = $current_settings[ $key ] ?? $field_config['default'] ?? '';

			// Ensure select field values are strings for frontend compatibility
			if ( ( $field_config['type'] ?? 'text' ) === 'select' ) {
				$current_value = (string) $current_value;
			}

			// Format options for frontend consumption
			$formatted_options = $this->formatOptionsForFrontend( $field_config['options'] ?? array() );

			$field_state[ $key ] = array(
				'type'          => $field_config['type'] ?? 'text',
				'label'         => $field_config['label'] ?? $this->generateFieldLabel( $key, $field_config, $this->getAcronymMappings() ),
				'description'   => $field_config['description'] ?? '',
				'options'       => $formatted_options,
				'default'       => $field_config['default'] ?? '',
				'current_value' => $current_value,
			);
		}

		return $field_state;
	}

	/**
	 * Format options array for frontend consumption.
	 *
	 * Converts associative array ['value' => 'label'] to [{'value': 'value', 'label': 'label'}]
	 * Ensures all values are strings for consistent frontend handling.
	 *
	 * @param array $options Raw options array
	 * @return array Formatted options array
	 */
	private function formatOptionsForFrontend( array $options ): array {
		$formatted = array();
		foreach ( $options as $value => $label ) {
			$formatted[] = array(
				'value' => (string) $value,
				'label' => (string) $label,
			);
		}
		return $formatted;
	}

	/**
	 * Format display value based on field configuration.
	 *
	 * Handles type-flexible matching for option labels (e.g., integer 1 vs string "1").
	 *
	 * @param mixed $value Raw value
	 * @param array $field_config Field configuration
	 * @return mixed Formatted display value
	 */
	private function formatDisplayValue( $value, array $field_config ) {
		// Use option label if available
		if ( isset( $field_config['options'][ $value ] ) ) {
			return $field_config['options'][ $value ];
		}

		// Try type coercion for numeric values (handles int/string mismatch)
		if ( is_numeric( $value ) ) {
			// Try as integer
			$int_value = (int) $value;
			if ( isset( $field_config['options'][ $int_value ] ) ) {
				return $field_config['options'][ $int_value ];
			}

			// Try as string
			$string_value = (string) $value;
			if ( isset( $field_config['options'][ $string_value ] ) ) {
				return $field_config['options'][ $string_value ];
			}
		}

		// Handle boolean values for checkbox fields
		if ( is_bool( $value ) ) {
			return $value ? __( 'True', 'data-machine' ) : __( 'False', 'data-machine' );
		}

		return $value;
	}
}
