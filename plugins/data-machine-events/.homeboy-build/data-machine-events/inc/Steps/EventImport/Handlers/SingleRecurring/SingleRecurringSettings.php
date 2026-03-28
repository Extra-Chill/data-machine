<?php
/**
 * Single Recurring Event Handler Settings
 *
 * Defines settings fields and sanitization for the single recurring event handler.
 * Supports weekly recurring events with configurable day of week and expiration date.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring;

use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SingleRecurringSettings {

	use VenueFieldsTrait;

	public function __construct() {
	}

	/**
	 * Get settings fields for single recurring event handler
	 *
	 * @param array $current_config Current configuration values
	 * @return array Associative array defining the settings fields
	 */
	public static function get_fields( array $current_config = array() ): array {
		$event_fields = array(
			'event_title'       => array(
				'type'        => 'text',
				'label'       => __( 'Event Title', 'data-machine-events' ),
				'description' => __( 'Title for the recurring event.', 'data-machine-events' ),
				'placeholder' => __( 'Open Mic Night', 'data-machine-events' ),
				'required'    => true,
			),
			'event_description' => array(
				'type'        => 'textarea',
				'label'       => __( 'Event Description', 'data-machine-events' ),
				'description' => __( 'Description for the recurring event.', 'data-machine-events' ),
				'placeholder' => __( 'Weekly open mic night featuring local musicians and comedians.', 'data-machine-events' ),
				'required'    => false,
			),
			'day_of_week'       => array(
				'type'        => 'select',
				'label'       => __( 'Day of Week', 'data-machine-events' ),
				'description' => __( 'Which day the event occurs each week.', 'data-machine-events' ),
				'options'     => array(
					'0' => __( 'Sunday', 'data-machine-events' ),
					'1' => __( 'Monday', 'data-machine-events' ),
					'2' => __( 'Tuesday', 'data-machine-events' ),
					'3' => __( 'Wednesday', 'data-machine-events' ),
					'4' => __( 'Thursday', 'data-machine-events' ),
					'5' => __( 'Friday', 'data-machine-events' ),
					'6' => __( 'Saturday', 'data-machine-events' ),
				),
				'required'    => true,
			),
			'start_time'        => array(
				'type'        => 'text',
				'label'       => __( 'Start Time', 'data-machine-events' ),
				'description' => __( 'Event start time in 24-hour format.', 'data-machine-events' ),
				'placeholder' => __( '19:00', 'data-machine-events' ),
				'required'    => false,
			),
			'end_time'          => array(
				'type'        => 'text',
				'label'       => __( 'End Time', 'data-machine-events' ),
				'description' => __( 'Event end time in 24-hour format.', 'data-machine-events' ),
				'placeholder' => __( '22:00', 'data-machine-events' ),
				'required'    => false,
			),
			'expiration_date'   => array(
				'type'        => 'date',
				'label'       => __( 'Expiration Date', 'data-machine-events' ),
				'description' => __( 'Stop creating events after this date. Leave empty for no expiration.', 'data-machine-events' ),
				'required'    => false,
			),
			'ticket_url'        => array(
				'type'        => 'url',
				'label'       => __( 'Ticket/Info URL', 'data-machine-events' ),
				'description' => __( 'Link to tickets or event information.', 'data-machine-events' ),
				'placeholder' => __( 'https://example.com/open-mic', 'data-machine-events' ),
				'required'    => false,
			),
			'price'             => array(
				'type'        => 'text',
				'label'       => __( 'Price', 'data-machine-events' ),
				'description' => __( 'Event price or admission info.', 'data-machine-events' ),
				'placeholder' => __( 'Free', 'data-machine-events' ),
				'required'    => false,
			),
		);

		$venue_fields = self::get_venue_fields();

		$filter_fields = array(
			'search'           => array(
				'type'        => 'text',
				'label'       => __( 'Include Keywords', 'data-machine-events' ),
				'description' => __( 'Only create events when the title matches these keywords (comma-separated). Leave empty to allow all.', 'data-machine-events' ),
				'placeholder' => __( 'open mic, trivia', 'data-machine-events' ),
				'required'    => false,
			),
			'exclude_keywords' => array(
				'type'        => 'text',
				'label'       => __( 'Exclude Keywords', 'data-machine-events' ),
				'description' => __( 'Skip when the title matches these keywords (comma-separated).', 'data-machine-events' ),
				'placeholder' => __( 'closed', 'data-machine-events' ),
				'required'    => false,
			),
		);

		return array_merge( $event_fields, $venue_fields, $filter_fields );
	}

	/**
	 * Sanitize single recurring event handler settings
	 *
	 * @param array $raw_settings Raw settings input
	 * @return array Sanitized settings
	 */
	public static function sanitize( array $raw_settings ): array {
		$event_settings = array(
			'event_title'       => sanitize_text_field( $raw_settings['event_title'] ?? '' ),
			'event_description' => sanitize_textarea_field( $raw_settings['event_description'] ?? '' ),
			'day_of_week'       => absint( $raw_settings['day_of_week'] ?? 0 ),
			'start_time'        => sanitize_text_field( $raw_settings['start_time'] ?? '' ),
			'end_time'          => sanitize_text_field( $raw_settings['end_time'] ?? '' ),
			'expiration_date'   => sanitize_text_field( $raw_settings['expiration_date'] ?? '' ),
			'ticket_url'        => esc_url_raw( $raw_settings['ticket_url'] ?? '' ),
			'price'             => sanitize_text_field( $raw_settings['price'] ?? '' ),
			'search'            => sanitize_text_field( $raw_settings['search'] ?? '' ),
			'exclude_keywords'  => sanitize_text_field( $raw_settings['exclude_keywords'] ?? '' ),
		);

		if ( $event_settings['day_of_week'] > 6 ) {
			$event_settings['day_of_week'] = 0;
		}

		$venue_settings = self::sanitize_venue_fields( $raw_settings );

		$settings = array_merge( $event_settings, $venue_settings );

		return self::save_venue_on_settings_save( $settings );
	}

	/**
	 * Determine if authentication is required
	 *
	 * @param array $current_config Current configuration values
	 * @return bool True if authentication is required
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
		$event_defaults = array(
			'event_title'       => '',
			'event_description' => '',
			'day_of_week'       => 0,
			'start_time'        => '',
			'end_time'          => '',
			'expiration_date'   => '',
			'ticket_url'        => '',
			'price'             => '',
			'search'            => '',
			'exclude_keywords'  => '',
		);

		$venue_defaults = self::get_venue_field_defaults();

		return array_merge( $event_defaults, $venue_defaults );
	}
}
