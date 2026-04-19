<?php
/**
 * WordPress Upsert Handler Settings
 *
 * Defines settings fields and sanitization for WordPress update handler.
 * Part of the modular handler architecture.
 *
 * @package DataMachine
 * @since 1.0.0
 */

namespace DataMachine\Core\Steps\Upsert\Handlers\WordPress;

use DataMachine\Core\Steps\Settings\SettingsHandler;
use DataMachine\Core\WordPress\WordPressSettingsHandler;

defined( 'ABSPATH' ) || exit;

class WordPressSettings extends SettingsHandler {


	/**
	 * Get settings fields for WordPress update handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		// WordPress update settings for local WordPress installation only
		$fields = self::get_local_fields();

		// Add taxonomy fields
		$fields = array_merge(
			$fields,
			WordPressSettingsHandler::get_taxonomy_fields(
				array(
					'field_suffix'         => '_selection',
					'first_options'        => array(
						'skip'       => esc_html__( 'Skip', 'data-machine' ),
						'ai_decides' => esc_html__( 'AI Decides', 'data-machine' ),
					),
					/* translators: 1: taxonomy label, 2: taxonomy term label */
					'description_template' => __( 'Configure %1$s assignment for updates: Skip to exclude from AI instructions, let AI choose, or select specific %2$s.', 'data-machine' ),
				)
			)
		);

		// Add common fields for all destination types
		$fields = array_merge( $fields, self::get_common_fields() );

		return $fields;
	}

	/**
	 * Get settings fields specific to local WordPress updating.
	 *
	 * @return array Settings fields.
	 */
	private static function get_local_fields(): array {
		return array(
			'allow_title_updates'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Allow Title Updates', 'data-machine' ),
				'description' => __( 'Enable AI to modify post titles. When disabled, titles will remain unchanged.', 'data-machine' ),
			),
			'allow_content_updates' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Allow Content Updates', 'data-machine' ),
				'description' => __( 'Enable AI to modify post content. When disabled, content will remain unchanged.', 'data-machine' ),
			),
		);
	}


	/**
	 * Get common settings fields for all destination types.
	 *
	 * @return array Settings fields.
	 */
	private static function get_common_fields(): array {
		return array();
	}

	/**
	 * Sanitize WordPress update handler settings.
	 *
	 * @param array $raw_settings Raw settings input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $raw_settings ): array {
		// Sanitize local WordPress settings
		return self::sanitize_local_settings( $raw_settings );
	}

	/**
	 * Sanitize local WordPress settings.
	 *
	 * @param array $raw_settings Raw settings array.
	 * @return array Sanitized settings.
	 */
	private static function sanitize_local_settings( array $raw_settings ): array {
		$sanitized = array(
			'allow_title_updates'   => ! empty( $raw_settings['allow_title_updates'] ),
			'allow_content_updates' => ! empty( $raw_settings['allow_content_updates'] ),
		);

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
