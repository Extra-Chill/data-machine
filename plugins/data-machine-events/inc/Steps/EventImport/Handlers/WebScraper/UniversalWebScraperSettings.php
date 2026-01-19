<?php
/**
 * Universal Web Scraper Settings
 *
 * Configuration for AI-powered web scraping with venue management.
 * Uses VenueFieldsTrait for standardized venue field definitions and taxonomy integration.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UniversalWebScraperSettings {

	use VenueFieldsTrait;

	/**
	 * Get settings fields for Universal Web Scraper handler
	 *
	 * @param array $current_config Current configuration values for this handler
	 * @return array Associative array defining the settings fields
	 */
	public static function get_fields( array $current_config = array() ): array {
		$handler_fields = array(
			'source_url'       => array(
				'type'        => 'url',
				'label'       => __( 'Scraper Source URL', 'datamachine-events' ),
				'description' => __( 'The specific webpage the AI should analyze to extract events.', 'datamachine-events' ),
				'placeholder' => 'https://venue.com/events',
				'required'    => true,
			),
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

		$venue_fields = self::get_venue_fields();

		return array_merge( $handler_fields, $venue_fields );
	}

	/**
	 * Sanitize Universal Web Scraper handler settings
	 *
	 * @param array $raw_settings Raw settings input
	 * @return array Sanitized settings
	 */
	public static function sanitize( array $raw_settings ): array {
		$handler_settings = array(
			'source_url'       => esc_url_raw( $raw_settings['source_url'] ?? '' ),
			'search'           => sanitize_text_field( $raw_settings['search'] ?? '' ),
			'exclude_keywords' => sanitize_text_field( $raw_settings['exclude_keywords'] ?? '' ),
		);

		$venue_settings = self::sanitize_venue_fields( $raw_settings );

		$settings = array_merge( $handler_settings, $venue_settings );

		return self::save_venue_on_settings_save( $settings );
	}

	/**
	 * Universal Web Scraper doesn't require authentication
	 *
	 * @param array $current_config Current configuration values
	 * @return bool Always false - no authentication required
	 */
	public static function requires_authentication( array $current_config = array() ): bool {
		return false;
	}

	/**
	 * Get default values for all settings
	 *
	 * @return array Default values
	 */
	public static function get_defaults(): array {
		$handler_defaults = array(
			'source_url'       => '',
			'search'           => '',
			'exclude_keywords' => '',
		);

		$venue_defaults = self::get_venue_field_defaults();

		return array_merge( $handler_defaults, $venue_defaults );
	}
}
