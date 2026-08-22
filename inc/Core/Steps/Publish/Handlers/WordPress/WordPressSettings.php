<?php
/**
 * WordPress Publish Handler Settings
 *
 * Defines settings fields and sanitization for WordPress publish handler.
 * Extends base publish handler settings with WordPress-specific configuration.
 *
 * @package DataMachine
 * @since 1.0.0
 */

namespace DataMachine\Core\Steps\Publish\Handlers\WordPress;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;
use DataMachine\Core\WordPress\WordPressSettingsHandler;

defined( 'ABSPATH' ) || exit;

class WordPressSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for WordPress publish handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		// WordPress publish settings for local WordPress installation only
		$fields = self::get_local_fields();

		// Add common publish handler fields with WordPress-specific alignment
		$fields = array_merge( $fields, self::get_wordpress_common_fields() );

		return $fields;
	}

	/**
	 * Get settings fields specific to local WordPress publishing.
	 *
	 * @return array Settings fields.
	 */
	private static function get_local_fields(): array {
		// Get standard WordPress publish fields
		$standard_fields = WordPressSettingsHandler::get_standard_publish_fields(
			array(
				'domain' => 'data-machine',
			)
		);

		// Get dynamic taxonomy fields
		$taxonomy_fields = WordPressSettingsHandler::get_taxonomy_fields(
			array(
				'field_suffix'         => '_selection',
				'first_options'        => array(
					'skip'       => esc_html__( 'Skip', 'data-machine' ),
					'ai_decides' => esc_html__( 'AI Decides', 'data-machine' ),
				),
				/* translators: 1: taxonomy label, 2: taxonomy term label */
				'description_template' => __( 'Configure %1$s assignment: Skip to exclude from AI instructions, let AI choose, or select specific %2$s.', 'data-machine' ),
			)
		);

		// Merge in dynamic taxonomy fields
		return array_merge( $standard_fields, $taxonomy_fields );
	}


	/**
	 * Get WordPress-specific common fields aligned with base publish handler fields.
	 *
	 * @return array Settings fields.
	 */
	private static function get_wordpress_common_fields(): array {
		$common_fields = parent::get_common_fields();

		// Align field names and add WordPress-specific fields
		return $common_fields;
	}

	/**
	 * Sanitize WordPress publish handler settings.
	 *
	 * @param array $raw_settings Raw settings input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $raw_settings ): array {
		// Sanitize local WordPress settings
		$sanitized = self::sanitize_local_settings( $raw_settings );

		// Sanitize common publish handler fields
		$sanitized = array_merge( $sanitized, parent::sanitize( $raw_settings ) );

		return $sanitized;
	}

	/**
	 * Sanitize local WordPress settings.
	 *
	 * @param array $raw_settings Raw settings array.
	 * @return array Sanitized settings.
	 */
	private static function sanitize_local_settings( array $raw_settings ): array {
		// Sanitize standard fields
		$sanitized = WordPressSettingsHandler::sanitize_standard_publish_fields( $raw_settings );

		// Sanitize dynamic taxonomy selections
		$sanitized = array_merge(
			$sanitized,
			WordPressSettingsHandler::sanitize_taxonomy_fields(
				$raw_settings,
				array(
					'field_suffix'   => '_selection',
					'allowed_values' => array( 'skip', 'ai_decides' ),
					'default_value'  => 'skip',
				)
			)
		);

		return $sanitized;
	}

	/**
	 * Determine if authentication is required based on current configuration.
	 *
	 * @param array $current_config Current configuration values for this handler.
	 * @return bool True if authentication is required, false otherwise.
	 */
	public static function requires_authentication( array $current_config = array() ): bool {
		// Local WordPress does not require authentication
		return false;
	}
}
