<?php
/**
 * Event Import Admin Assets
 *
 * Enqueues admin assets for the Event Import step type.
 * Step type registration is handled by EventImportStep using StepTypeRegistrationTrait.
 *
 * @package DataMachineEvents\Steps\EventImport
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use const DataMachineEvents\Api\API_NAMESPACE;

/**
 * Enqueue venue autocomplete and selector assets on Data Machine admin pages
 *
 * Loads Nominatim-powered address autocomplete and venue selector dropdown
 * JavaScript/CSS only on Data Machine settings pages where venue fields are displayed.
 */
add_action(
	'admin_enqueue_scripts',
	function () {
		// Only load on Data Machine settings pages
		if ( ! is_admin() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'datamachine-pipelines' ) === false ) {
			return;
		}

		// Enqueue venue autocomplete JavaScript
		wp_enqueue_script(
			'data-machine-events-venue-autocomplete',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/js/venue-autocomplete.js',
			array( 'jquery' ),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/js/venue-autocomplete.js' ),
			true
		);

		// Enqueue venue selector JavaScript
		wp_enqueue_script(
			'data-machine-events-venue-selector',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/js/venue-selector.js',
			array( 'jquery', 'data-machine-events-venue-autocomplete' ),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/js/venue-selector.js' ),
			true
		);

		// Localize script with REST API configuration
		wp_localize_script(
			'data-machine-events-venue-selector',
			'dmEventsVenue',
			array(
				'restUrl' => rest_url( API_NAMESPACE ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Enqueue CSS
		wp_enqueue_style(
			'data-machine-events-venue-autocomplete',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/css/venue-autocomplete.css',
			array(),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/css/venue-autocomplete.css' )
		);

		// Enqueue pipeline hooks JavaScript (extends core React via @wordpress/hooks)
		wp_enqueue_script(
			'data-machine-events-pipeline-hooks',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/js/pipeline-hooks.js',
			array( 'datamachine-pipelines-react', 'wp-hooks', 'wp-api-fetch' ),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/js/pipeline-hooks.js' ),
			true
		);

		// Enqueue pipeline React components (custom field types like address-autocomplete)
		wp_enqueue_script(
			'data-machine-events-pipeline-components',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/js/pipeline-components.js',
			array( 'datamachine-pipelines-react', 'wp-element', 'wp-components', 'wp-hooks', 'wp-i18n' ),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/js/pipeline-components.js' ),
			true
		);
	}
);
