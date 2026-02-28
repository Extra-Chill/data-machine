<?php
/**
 * Venue Fields Trait
 *
 * Provides standardized venue field definitions, sanitization, and taxonomy integration
 * for event import handlers. Ensures consistent venue handling across all handlers
 * with venue configuration capabilities.
 *
 * Field naming convention:
 * - Handler settings (forms, config): snake_case (venue_address)
 * - AI tool parameters: camelCase (venueAddress)
 * - Term meta keys: snake_case with prefix (_venue_address)
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers
 */

namespace DataMachineEvents\Steps\EventImport\Handlers;

use DataMachineEvents\Core\Venue_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait VenueFieldsTrait {

	/**
	 * Get venue selector and all venue field definitions.
	 *
	 * @return array Associative array of venue field definitions
	 */
	protected static function get_venue_fields(): array {
		$all_venues = Venue_Taxonomy::get_all_venues();

		usort(
			$all_venues,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$venue_options = array( '' => __( '-- Create New Venue --', 'data-machine-events' ) );
		foreach ( $all_venues as $venue ) {
			$venue_options[ $venue['term_id'] ] = $venue['name'];
		}

		return array(
			'venue'          => array(
				'type'        => 'select',
				'label'       => __( 'Venue', 'data-machine-events' ),
				'description' => __( 'Select an existing venue or choose "Create New Venue" to add a new one.', 'data-machine-events' ),
				'options'     => $venue_options,
				'required'    => false,
			),
			'venue_name'     => array(
				'type'        => 'text',
				'label'       => __( 'Venue Name', 'data-machine-events' ),
				'description' => __( 'Required when creating a new venue.', 'data-machine-events' ),
				'placeholder' => __( 'The Royal American', 'data-machine-events' ),
				'required'    => false,
			),
			'venue_address'  => array(
				'type'        => 'address-autocomplete',
				'label'       => __( 'Venue Address', 'data-machine-events' ),
				'description' => __( 'Start typing to search. Auto-fills city, state, zip, country.', 'data-machine-events' ),
				'placeholder' => __( '970 Morrison Drive', 'data-machine-events' ),
				'required'    => false,
			),
			'venue_city'     => array(
				'type'        => 'text',
				'label'       => __( 'City', 'data-machine-events' ),
				'description' => __( 'Auto-filled from address selection.', 'data-machine-events' ),
				'placeholder' => __( 'Charleston', 'data-machine-events' ),
				'required'    => false,
			),
			'venue_state'    => array(
				'type'        => 'text',
				'label'       => __( 'State', 'data-machine-events' ),
				'description' => __( 'Auto-filled from address selection.', 'data-machine-events' ),
				'placeholder' => __( 'South Carolina', 'data-machine-events' ),
				'required'    => false,
			),
			'venue_zip'      => array(
				'type'        => 'text',
				'label'       => __( 'ZIP Code', 'data-machine-events' ),
				'description' => __( 'Auto-filled from address selection.', 'data-machine-events' ),
				'placeholder' => __( '29403', 'data-machine-events' ),
				'required'    => false,
			),
			'venue_country'  => array(
				'type'        => 'text',
				'label'       => __( 'Country', 'data-machine-events' ),
				'description' => __( 'Auto-filled from address selection. Two-letter country code.', 'data-machine-events' ),
				'placeholder' => __( 'US', 'data-machine-events' ),
				'required'    => false,
			),
			'venue_phone'    => array(
				'type'        => 'text',
				'label'       => __( 'Phone', 'data-machine-events' ),
				'description' => __( 'Venue phone number.', 'data-machine-events' ),
				'placeholder' => __( '(843) 817-6925', 'data-machine-events' ),
				'required'    => false,
			),
			'venue_website'  => array(
				'type'        => 'url',
				'label'       => __( 'Website', 'data-machine-events' ),
				'description' => __( 'Venue website URL.', 'data-machine-events' ),
				'placeholder' => __( 'https://www.theroyalamerican.com', 'data-machine-events' ),
				'required'    => false,
			),
			'venue_capacity' => array(
				'type'        => 'number',
				'label'       => __( 'Capacity', 'data-machine-events' ),
				'description' => __( 'Maximum venue capacity.', 'data-machine-events' ),
				'placeholder' => __( '500', 'data-machine-events' ),
				'required'    => false,
			),
		);
	}

	/**
	 * Get default values for all venue fields.
	 *
	 * @return array Default values keyed by field name
	 */
	protected static function get_venue_field_defaults(): array {
		return array(
			'venue'          => '',
			'venue_name'     => '',
			'venue_address'  => '',
			'venue_city'     => '',
			'venue_state'    => '',
			'venue_zip'      => '',
			'venue_country'  => '',
			'venue_phone'    => '',
			'venue_website'  => '',
			'venue_capacity' => '',
		);
	}

	/**
	 * Sanitize venue fields from raw settings input.
	 *
	 * @param array $raw_settings Raw settings input
	 * @return array Sanitized venue field values
	 */
	protected static function sanitize_venue_fields( array $raw_settings ): array {
		return array(
			'venue'          => sanitize_text_field( $raw_settings['venue'] ?? '' ),
			'venue_name'     => sanitize_text_field( $raw_settings['venue_name'] ?? '' ),
			'venue_address'  => sanitize_text_field( $raw_settings['venue_address'] ?? '' ),
			'venue_city'     => sanitize_text_field( $raw_settings['venue_city'] ?? '' ),
			'venue_state'    => sanitize_text_field( $raw_settings['venue_state'] ?? '' ),
			'venue_zip'      => sanitize_text_field( $raw_settings['venue_zip'] ?? '' ),
			'venue_country'  => sanitize_text_field( $raw_settings['venue_country'] ?? '' ),
			'venue_phone'    => sanitize_text_field( $raw_settings['venue_phone'] ?? '' ),
			'venue_website'  => esc_url_raw( $raw_settings['venue_website'] ?? '' ),
			'venue_capacity' => ! empty( $raw_settings['venue_capacity'] ) ? absint( $raw_settings['venue_capacity'] ) : '',
		);
	}

	/**
	 * Process venue data on settings save.
	 *
	 * Creates new venue term if venue is empty and venue_name is provided.
	 * Updates existing venue term meta if venue has a term_id.
	 * Stores both term_id AND venue fields in handler_config for dual storage.
	 *
	 * @param array $settings Sanitized settings array (modified in place)
	 * @return array Modified settings with venue term_id set
	 */
	protected static function save_venue_on_settings_save( array $settings ): array {
		$venue_term_id = $settings['venue'] ?? '';

		$venue_data = array(
			'address'  => $settings['venue_address'] ?? '',
			'city'     => $settings['venue_city'] ?? '',
			'state'    => $settings['venue_state'] ?? '',
			'zip'      => $settings['venue_zip'] ?? '',
			'country'  => $settings['venue_country'] ?? '',
			'phone'    => $settings['venue_phone'] ?? '',
			'website'  => $settings['venue_website'] ?? '',
			'capacity' => $settings['venue_capacity'] ?? '',
		);

		if ( empty( $venue_term_id ) ) {
			$venue_name = $settings['venue_name'] ?? '';

			if ( ! empty( $venue_name ) ) {
				$result        = Venue_Taxonomy::find_or_create_venue( $venue_name, $venue_data );
				$venue_term_id = $result['term_id'] ?? '';
			}
		} else {
			$original_data  = Venue_Taxonomy::get_venue_data( $venue_term_id );
			$changed_fields = array();

			foreach ( $venue_data as $key => $value ) {
				$original_value = $original_data[ $key ] ?? '';
				if ( trim( (string) $original_value ) !== trim( (string) $value ) ) {
					$changed_fields[ $key ] = $value;
				}
			}

			if ( ! empty( $changed_fields ) ) {
				Venue_Taxonomy::update_venue_meta( $venue_term_id, $changed_fields );
			}
		}

		$settings['venue'] = $venue_term_id;

		return $settings;
	}

	/**
	 * Get venue field keys for settings operations.
	 *
	 * @return array List of venue field keys (snake_case)
	 */
	protected static function get_venue_field_keys(): array {
		return array(
			'venue',
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
	}

	/**
	 * Map handler config venue fields (snake_case) to event data format (camelCase).
	 *
	 * Used by handlers when building standardized event data from config.
	 *
	 * @param array $config Handler configuration
	 * @return array Venue data in camelCase format for event processing
	 */
	protected static function map_venue_config_to_event_data( array $config ): array {
		return array(
			'venue'         => $config['venue_name'] ?? '',
			'venueAddress'  => $config['venue_address'] ?? '',
			'venueCity'     => $config['venue_city'] ?? '',
			'venueState'    => $config['venue_state'] ?? '',
			'venueZip'      => $config['venue_zip'] ?? '',
			'venueCountry'  => $config['venue_country'] ?? '',
			'venuePhone'    => $config['venue_phone'] ?? '',
			'venueWebsite'  => $config['venue_website'] ?? '',
			'venueCapacity' => $config['venue_capacity'] ?? '',
		);
	}
}
