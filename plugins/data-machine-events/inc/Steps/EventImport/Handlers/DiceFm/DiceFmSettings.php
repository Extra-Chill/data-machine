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
				'label'       => __( 'City', 'datamachine-events' ),
				'description' => __( 'City name to search for events (required). This is the primary filter for Dice.fm API.', 'datamachine-events' ),
				'placeholder' => __( 'Charleston', 'datamachine-events' ),
				'required'    => true,
			),
		);

		$filter_fields = array(
			'search'           => array(
				'type'        => 'text',
				'label'       => __( 'Include Keywords', 'datamachine-events' ),
				'description' => __( 'Only import events containing any of these keywords (comma-separated). Leave empty to import all.', 'datamachine-events' ),
				'placeholder' => __( 'concert, live music, band', 'datamachine-events' ),
				'required'    => false,
			),
			'exclude_keywords' => array(
				'type'        => 'text',
				'label'       => __( 'Exclude Keywords', 'datamachine-events' ),
				'description' => __( 'Skip events containing any of these keywords (comma-separated).', 'datamachine-events' ),
				'placeholder' => __( 'trivia, karaoke, brunch, bingo', 'datamachine-events' ),
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
