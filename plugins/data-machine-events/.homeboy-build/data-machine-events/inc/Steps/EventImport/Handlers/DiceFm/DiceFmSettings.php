<?php
/**
 * Dice.fm Event Import Handler Settings
 *
 * Defines settings fields and sanitization for Dice.fm event import handler.
 * Part of the modular handler architecture for Data Machine integration.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\DiceFm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiceFmSettings {

	public function __construct() {
	}

	/**
	 * Get settings fields for Dice.fm event import handler
	 *
	 * @param array $current_config Current configuration values for this handler
	 * @return array Associative array defining the settings fields
	 */
	public static function get_fields( array $current_config = array() ): array {
		$handler_fields = array(
			'city' => array(
				'type'        => 'text',
				'label'       => __( 'City', 'data-machine-events' ),
				'description' => __( 'City name to search for events (required). This is the primary filter for Dice.fm API.', 'data-machine-events' ),
				'placeholder' => __( 'Charleston', 'data-machine-events' ),
				'required'    => true,
			),
		);

		$filter_fields = array(
			'search'           => array(
				'type'        => 'text',
				'label'       => __( 'Include Keywords', 'data-machine-events' ),
				'description' => __( 'Only import events containing any of these keywords (comma-separated). Leave empty to import all.', 'data-machine-events' ),
				'placeholder' => __( 'concert, live music, band', 'data-machine-events' ),
				'required'    => false,
			),
			'exclude_keywords' => array(
				'type'        => 'text',
				'label'       => __( 'Exclude Keywords', 'data-machine-events' ),
				'description' => __( 'Skip events containing any of these keywords (comma-separated).', 'data-machine-events' ),
				'placeholder' => __( 'trivia, karaoke, brunch, bingo', 'data-machine-events' ),
				'required'    => false,
			),
		);

		return array_merge( $handler_fields, $filter_fields );
	}

	/**
	 * Sanitize Dice.fm handler settings.
	 *
	 * @param array $raw_settings Raw settings input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $raw_settings ): array {
		return array(
			'city'             => sanitize_text_field( $raw_settings['city'] ?? '' ),
			'search'           => sanitize_text_field( $raw_settings['search'] ?? '' ),
			'exclude_keywords' => sanitize_text_field( $raw_settings['exclude_keywords'] ?? '' ),
		);
	}

	/**
	 * Determine if authentication is required.
	 *
	 * @param array $current_config Current configuration values.
	 * @return bool True if authentication is required.
	 */
	public static function requires_authentication( array $current_config = array() ): bool {
		return true;
	}

	/**
	 * Get default values for all settings.
	 *
	 * @return array Default values.
	 */
	public static function get_defaults(): array {
		return array(
			'city'             => '',
			'search'           => '',
			'exclude_keywords' => '',
		);
	}
}
