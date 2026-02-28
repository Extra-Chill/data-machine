<?php
/**
 * Event Flyer Handler Settings
 *
 * Configuration fields for extracting event data from flyer images.
 * All fields follow the "fill OR AI extracts" pattern - if a field is
 * populated, that value is used; if blank, AI extracts from the image.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\EventFlyer
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\EventFlyer;

use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventFlyerSettings {

	use VenueFieldsTrait;

	public static function get_fields( array $current_config = array() ): array {
		$event_fields = array(
			'title' => array(
				'type'        => 'text',
				'label'       => __( 'Event Title', 'data-machine-events' ),
				'description' => __( 'Leave blank for AI to extract from flyer.', 'data-machine-events' ),
				'placeholder' => __( 'AI extracts from flyer', 'data-machine-events' ),
			),
		);

		$venue_fields = self::get_venue_fields();

		$time_fields = array(
			'startDate' => array(
				'type'        => 'text',
				'label'       => __( 'Start Date', 'data-machine-events' ),
				'description' => __( 'YYYY-MM-DD format. Leave blank for AI to extract from flyer.', 'data-machine-events' ),
				'placeholder' => __( 'AI extracts from flyer', 'data-machine-events' ),
			),
			'startTime' => array(
				'type'        => 'text',
				'label'       => __( 'Start Time', 'data-machine-events' ),
				'description' => __( 'HH:MM format. Leave blank for AI to extract from flyer.', 'data-machine-events' ),
				'placeholder' => __( 'AI extracts from flyer', 'data-machine-events' ),
			),
			'endTime'   => array(
				'type'        => 'text',
				'label'       => __( 'End Time', 'data-machine-events' ),
				'description' => __( 'HH:MM format. Leave blank for AI to extract from flyer.', 'data-machine-events' ),
				'placeholder' => __( 'AI extracts from flyer', 'data-machine-events' ),
			),
			'price'     => array(
				'type'        => 'text',
				'label'       => __( 'Price', 'data-machine-events' ),
				'description' => __( 'Leave blank for AI to extract from flyer.', 'data-machine-events' ),
				'placeholder' => __( 'AI extracts from flyer', 'data-machine-events' ),
			),
			'ticketUrl' => array(
				'type'        => 'text',
				'label'       => __( 'Ticket URL', 'data-machine-events' ),
				'description' => __( 'Leave blank for AI to extract from flyer.', 'data-machine-events' ),
				'placeholder' => __( 'AI extracts from flyer', 'data-machine-events' ),
			),
			'performer' => array(
				'type'        => 'text',
				'label'       => __( 'Performer(s)', 'data-machine-events' ),
				'description' => __( 'Supporting acts or additional performers. Leave blank for AI to extract from flyer.', 'data-machine-events' ),
				'placeholder' => __( 'AI extracts from flyer', 'data-machine-events' ),
			),
		);

		return array_merge( $event_fields, $venue_fields, $time_fields );
	}

	public static function sanitize( array $raw_settings ): array {
		$event_settings = array(
			'title'     => sanitize_text_field( $raw_settings['title'] ?? '' ),
			'startDate' => sanitize_text_field( $raw_settings['startDate'] ?? '' ),
			'startTime' => sanitize_text_field( $raw_settings['startTime'] ?? '' ),
			'endTime'   => sanitize_text_field( $raw_settings['endTime'] ?? '' ),
			'price'     => sanitize_text_field( $raw_settings['price'] ?? '' ),
			'ticketUrl' => esc_url_raw( $raw_settings['ticketUrl'] ?? '' ),
			'performer' => sanitize_text_field( $raw_settings['performer'] ?? '' ),
		);

		$venue_settings = self::sanitize_venue_fields( $raw_settings );

		$settings = array_merge( $event_settings, $venue_settings );

		return self::save_venue_on_settings_save( $settings );
	}

	public static function requires_authentication( array $current_config = array() ): bool {
		return false;
	}

	public static function get_defaults(): array {
		$event_defaults = array(
			'title'     => '',
			'startDate' => '',
			'startTime' => '',
			'endTime'   => '',
			'price'     => '',
			'ticketUrl' => '',
			'performer' => '',
		);

		$venue_defaults = self::get_venue_field_defaults();

		return array_merge( $event_defaults, $venue_defaults );
	}

	/**
	 * Get AI extraction field definitions.
	 *
	 * Returns camelCase keys for AI tool parameter format.
	 * Maps to VenueParameterProvider conventions.
	 *
	 * @return array Field descriptions for AI extraction
	 */
	public static function get_ai_extraction_fields(): array {
		return array(
			'title'        => 'Event title or headliner name (usually the largest text on the flyer)',
			'venue'        => 'Venue name where the event takes place',
			'venueAddress' => 'Street address of the venue',
			'venueCity'    => 'City name',
			'venueState'   => 'State or province abbreviation (e.g., SC, NY, CA)',
			'venueZip'     => 'Postal/zip code',
			'venueCountry' => 'Country (two-letter code preferred)',
			'venuePhone'   => 'Venue phone number',
			'venueWebsite' => 'Venue website URL',
			'startDate'    => 'Event date in YYYY-MM-DD format',
			'startTime'    => 'Event start time in HH:MM 24-hour format',
			'endTime'      => 'Event end time in HH:MM 24-hour format (if visible)',
			'price'        => 'Ticket price (e.g., "$20" or "$15 adv / $20 dos")',
			'ticketUrl'    => 'Ticket purchase URL if visible on the flyer',
			'performer'    => 'Supporting acts, opening bands, or additional performers',
			'description'  => 'Any additional event details visible on the flyer (age restrictions, special notes, etc.)',
		);
	}
}
