<?php
/**
 * WordPress REST API Fetch Handler Settings
 *
 * Defines settings fields and sanitization for WordPress REST API fetch handler.
 * Part of the modular handler architecture.
 *
 * @package    DataMachine
 * @subpackage Core\Steps\Fetch\Handlers\WordPressAPI
 * @since      1.0.0
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\WordPressAPI;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WordPressAPISettings extends FetchHandlerSettings {

	/**
	 * Get settings fields for WordPress REST API fetch handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		// Handler-specific fields
		$fields = array(
			'endpoint_url' => array(
				'type'        => 'text',
				'label'       => __( 'API Endpoint URL', 'data-machine' ),
				'description' => __( 'Enter the complete REST API endpoint URL (e.g., https://sxsw.com/wp-json/wp/v2/posts)', 'data-machine' ),
				'placeholder' => __( 'https://example.com/wp-json/wp/v2/posts', 'data-machine' ),
				'required'    => true,
			),
		);

		// Merge with common fetch handler fields
		return array_merge( $fields, parent::get_common_fields() );
	}

	/**
	 * Sanitize WordPress REST API fetch handler settings.
	 *
	 * @param array $raw_settings Raw settings input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $raw_settings ): array {
		$sanitized = array(
			'endpoint_url'     => esc_url_raw( trim( $raw_settings['endpoint_url'] ?? '' ) ),
			'timeframe_limit'  => sanitize_text_field( $raw_settings['timeframe_limit'] ?? 'all_time' ),
			'search'           => sanitize_text_field( $raw_settings['search'] ?? '' ),
			'exclude_keywords' => sanitize_text_field( $raw_settings['exclude_keywords'] ?? '' ),
		);

		// Validate endpoint URL
		if ( ! empty( $sanitized['endpoint_url'] ) && ! filter_var( $sanitized['endpoint_url'], FILTER_VALIDATE_URL ) ) {
			$sanitized['endpoint_url'] = '';
		}

		return $sanitized;
	}

	/**
	 * Determine if authentication is required based on current configuration.
	 *
	 * @param array $current_config Current configuration values for this handler.
	 * @return bool True if authentication is required, false otherwise.
	 */
	public static function requires_authentication( array $current_config = array() ): bool {
		// Public REST API does not require authentication
		return false;
	}
}
