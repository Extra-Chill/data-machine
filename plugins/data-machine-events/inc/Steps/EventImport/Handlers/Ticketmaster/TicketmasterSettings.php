<?php
/**
 * Ticketmaster Event Import Handler Settings
 *
 * Defines settings fields and sanitization for Ticketmaster event import handler.
 * Part of the modular handler architecture for Data Machine integration.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TicketmasterSettings class
 *
 * Provides configuration fields for Ticketmaster Discovery API integration
 * including location, classification, and API parameter settings.
 *
 * @since 1.0.0
 */
class TicketmasterSettings {

	/**
	 * Constructor
	 * Pure filter-based architecture - no dependencies.
	 */
	public function __construct() {
		// No constructor dependencies - all services accessed via filters
	}

	/**
	 * Get settings fields for Ticketmaster event import handler
	 *
	 * @param array $current_config Current configuration values for this handler
	 * @return array Associative array defining the settings fields
	 */
	public static function get_fields( array $current_config = array() ): array {
		return array(
			'classification_type' => array(
				'type'        => 'select',
				'label'       => __( 'Event Type', 'data-machine-events' ),
				'description' => __( 'Select the type of events to import. Options are fetched dynamically from Ticketmaster API.', 'data-machine-events' ),
				'options'     => array_merge(
					array( '' => __( 'Select an event type...', 'data-machine-events' ) ),
					Ticketmaster::get_classifications_for_dropdown( $current_config )
				),
			),
			'location'            => array(
				'type'        => 'text',
				'label'       => __( 'Location Coordinates', 'data-machine-events' ),
				'description' => __( 'Enter coordinates as "latitude,longitude" (e.g., "32.7765,-79.9311"). To get coordinates: Go to maps.google.com, find your location, right-click, and copy the numbers.', 'data-machine-events' ),
				'placeholder' => __( '32.7765,-79.9311', 'data-machine-events' ),
			),
			'radius'              => array(
				'type'        => 'text',
				'label'       => __( 'Search Radius (Miles)', 'data-machine-events' ),
				'description' => __( 'Search radius in miles around the specified location. Default is 50 miles.', 'data-machine-events' ),
				'placeholder' => __( '50', 'data-machine-events' ),
			),
			'genre'               => array(
				'type'        => 'text',
				'label'       => __( 'Genre ID (Advanced)', 'data-machine-events' ),
				'description' => __( 'Optional: Specific Ticketmaster Genre ID for sub-filtering within the selected event type (e.g., KnvZfZ7vAeA for Rock music). Leave empty for all genres within the event type.', 'data-machine-events' ),
				'placeholder' => __( 'KnvZfZ7vAeA', 'data-machine-events' ),
			),
			'venue_id'            => array(
				'type'        => 'text',
				'label'       => __( 'Venue ID', 'data-machine-events' ),
				'description' => __( 'Specific Ticketmaster Venue ID to search. Leave empty to search all venues.', 'data-machine-events' ),
				'placeholder' => __( 'KovZpZAJledA', 'data-machine-events' ),
			),
			'search'              => array(
				'type'        => 'text',
				'label'       => __( 'Include Keywords', 'data-machine-events' ),
				'description' => __( 'Only import events containing any of these keywords (comma-separated). Leave empty to import all.', 'data-machine-events' ),
				'placeholder' => __( 'concert, live music, band', 'data-machine-events' ),
				'required'    => false,
			),
			'exclude_keywords'    => array(
				'type'        => 'text',
				'label'       => __( 'Exclude Keywords', 'data-machine-events' ),
				'description' => __( 'Skip events containing any of these keywords (comma-separated).', 'data-machine-events' ),
				'placeholder' => __( 'trivia, karaoke, brunch, bingo', 'data-machine-events' ),
				'required'    => false,
			),
		);
	}

	/**
	 * Sanitize Ticketmaster handler settings.
	 *
	 * @param array $raw_settings Raw settings input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $raw_settings ): array {
		return array(
			'classification_type' => sanitize_text_field( $raw_settings['classification_type'] ?? '' ),
			'location'            => sanitize_text_field( $raw_settings['location'] ?? '' ),
			'radius'              => sanitize_text_field( $raw_settings['radius'] ?? '50' ),
			'genre'               => sanitize_text_field( $raw_settings['genre'] ?? '' ),
			'venue_id'            => sanitize_text_field( $raw_settings['venue_id'] ?? '' ),
			'search'              => sanitize_text_field( $raw_settings['search'] ?? '' ),
			'exclude_keywords'    => sanitize_text_field( $raw_settings['exclude_keywords'] ?? '' ),
		);
	}

	/**
	 * Determine if authentication is required.
	 *
	 * @param array $current_config Current configuration values.
	 * @return bool True if authentication is required.
	 */
	public static function requires_authentication( array $current_config = array() ): bool {
		return true; // Ticketmaster requires API key authentication
	}

	/**
	 * Get default values for all settings.
	 *
	 * @return array Default values.
	 */
	public static function get_defaults(): array {
		return array(
			'classification_type' => 'music',
			'location'            => '32.7765,-79.9311',
			'radius'              => '50',
			'genre'               => '',
			'venue_id'            => '',
			'search'              => '',
			'exclude_keywords'    => '',
		);
	}
}
