<?php
/**
 * Files Fetch Handler Settings
 *
 * Defines settings fields and sanitization for Files fetch handler.
 * Part of the modular handler architecture.
 *
 * @package DataMachine
 * @since 1.0.0
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Files;

use DataMachine\Core\Steps\Settings\SettingsHandler;

defined( 'ABSPATH' ) || exit;

class FilesSettings extends SettingsHandler {

	/**
	 * Get settings fields for Files fetch handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		return array(
			'file_upload_section'  => array(
				'type'        => 'section',
				'label'       => __( 'File Upload', 'data-machine' ),
				'description' => __( 'Upload any file type - the pipeline will handle compatibility.', 'data-machine' ),
			),
			'auto_cleanup_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Auto-cleanup old files', 'data-machine' ),
				'description' => __( 'Automatically delete processed files older than 7 days to save disk space.', 'data-machine' ),
			),
		);
	}

	/**
	 * Sanitize Files fetch handler settings.
	 *
	 * Uses parent auto-sanitization for checkbox, adds custom logic for uploaded files array.
	 *
	 * @param array $raw_settings Raw settings input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $raw_settings ): array {
		// Let parent handle auto_cleanup_enabled checkbox
		$sanitized = parent::sanitize( $raw_settings );

		// Custom handling for uploaded files array (can't be auto-sanitized)
		if ( isset( $raw_settings['uploaded_files'] ) && is_array( $raw_settings['uploaded_files'] ) ) {
			$sanitized['uploaded_files'] = array_map(
				function ( $file ) {
					return array(
						'original_name'   => sanitize_file_name( $file['original_name'] ?? '' ),
						'persistent_path' => sanitize_text_field( $file['persistent_path'] ?? '' ),
						'size'            => absint( $file['size'] ?? 0 ),
						'mime_type'       => sanitize_text_field( $file['mime_type'] ?? 'application/octet-stream' ),
						'uploaded_at'     => sanitize_text_field( $file['uploaded_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
					);
				},
				$raw_settings['uploaded_files']
			);
		} else {
			$sanitized['uploaded_files'] = array();
		}

		return $sanitized;
	}
}
