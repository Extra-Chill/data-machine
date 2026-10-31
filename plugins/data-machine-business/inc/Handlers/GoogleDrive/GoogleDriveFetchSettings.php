<?php
/**
 * Google Drive Fetch Handler Settings.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleDrive
 * @since 0.3.0
 */

namespace DataMachineBusiness\Handlers\GoogleDrive;

use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleDriveFetchSettings extends SettingsHandler {

	/**
	 * Regex used to extract a folder ID from a Drive folder URL.
	 *
	 * Matches both `/drive/folders/<id>` and `/folders/<id>` variants.
	 */
	private const FOLDER_URL_PATTERN = '#/folders/([a-zA-Z0-9_-]+)#';

	public static function get_fields(): array {
		return array(
			'googledrive_fetch_folder' => array(
				'type'        => 'text',
				'label'       => __( 'Folder ID or URL', 'data-machine-business' ),
				'description' => __( 'A Google Drive folder ID, or the full folder URL (e.g. https://drive.google.com/drive/folders/1abc...xyz).', 'data-machine-business' ),
				'placeholder' => '1dnNfeWM6J-d1l6nw8zWqBrX_B3vsC5kR',
				'required'    => true,
			),
			'googledrive_fetch_recursive' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Recurse into subfolders', 'data-machine-business' ),
				'description' => __( 'When enabled, list files inside nested subfolders as well as the top-level folder.', 'data-machine-business' ),
				'default'     => false,
			),
			'googledrive_fetch_mime_filter' => array(
				'type'        => 'text',
				'label'       => __( 'MIME type filter', 'data-machine-business' ),
				'description' => __( 'Optional comma-separated list of MIME types to include (e.g. application/pdf, audio/mpeg). Leave blank to include all files.', 'data-machine-business' ),
				'placeholder' => 'application/pdf, audio/mpeg',
			),
			'googledrive_fetch_modified_since' => array(
				'type'        => 'text',
				'label'       => __( 'Modified since (ISO 8601)', 'data-machine-business' ),
				'description' => __( 'Optional ISO-8601 timestamp. Only files modified after this time will be returned (e.g. 2026-01-01T00:00:00Z).', 'data-machine-business' ),
				'placeholder' => '2026-01-01T00:00:00Z',
			),
		);
	}

	public static function sanitize( array $raw_settings ): array {
		$sanitized = parent::sanitize( $raw_settings );

		$folder_raw = trim( (string) ( $sanitized['googledrive_fetch_folder'] ?? '' ) );
		$sanitized['googledrive_fetch_folder'] = self::extract_folder_id( $folder_raw );

		$mime_raw = (string) ( $sanitized['googledrive_fetch_mime_filter'] ?? '' );
		$sanitized['googledrive_fetch_mime_filter'] = self::sanitize_mime_filter( $mime_raw );

		$modified = trim( (string) ( $sanitized['googledrive_fetch_modified_since'] ?? '' ) );
		$sanitized['googledrive_fetch_modified_since'] = self::sanitize_modified_since( $modified );

		$sanitized['googledrive_fetch_recursive'] = ! empty( $sanitized['googledrive_fetch_recursive'] );

		return $sanitized;
	}

	/**
	 * Accept either a raw folder ID or a Drive folder URL and return the ID.
	 *
	 * @param string $input Raw input (ID or URL).
	 * @return string The folder ID, or an empty string when nothing usable was provided.
	 */
	public static function extract_folder_id( string $input ): string {
		$input = trim( $input );
		if ( '' === $input ) {
			return '';
		}

		if ( preg_match( self::FOLDER_URL_PATTERN, $input, $matches ) ) {
			return $matches[1];
		}

		// Strip any query string accidentally appended to a raw ID and
		// keep only the canonical Drive ID character class.
		$cleaned = preg_replace( '/[^a-zA-Z0-9_-]/', '', $input );
		return is_string( $cleaned ) ? $cleaned : '';
	}

	/**
	 * Sanitize the comma-separated MIME filter into a canonical, trimmed list.
	 *
	 * @param string $raw Comma-separated MIME types.
	 * @return string Sanitized comma-separated list (no spaces, lowercased).
	 */
	private static function sanitize_mime_filter( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}

		$parts  = array_map( 'trim', explode( ',', $raw ) );
		$clean  = array();
		foreach ( $parts as $mime ) {
			if ( '' === $mime ) {
				continue;
			}
			// MIME types are ASCII. Drop anything weird.
			$mime = strtolower( $mime );
			if ( preg_match( '#^[a-z0-9.+\-/]+$#', $mime ) ) {
				$clean[] = $mime;
			}
		}

		return implode( ',', array_values( array_unique( $clean ) ) );
	}

	/**
	 * Validate that a string parses as an ISO-8601 timestamp.
	 *
	 * @param string $value Raw value.
	 * @return string Original value when valid, otherwise empty string.
	 */
	private static function sanitize_modified_since( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return '';
		}

		return $value;
	}

	public static function validate_authentication( int $user_id ) {
		return GoogleDriveSettings::validate_authentication( $user_id );
	}
}
