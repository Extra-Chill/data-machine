<?php
/**
 * Google Sheets Publish Handler Settings
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleSheets
 * @since 0.1.0
 */

namespace DataMachineBusiness\Handlers\GoogleSheets;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleSheetsSettings extends PublishHandlerSettings {

	public static function get_fields(): array {
		return array(
			'googlesheets_spreadsheet_id' => array(
				'type' => 'text',
				'label' => __( 'Spreadsheet ID', 'data-machine-business' ),
				'description' => __( 'Google Sheets ID from the URL.', 'data-machine-business' ),
				'placeholder' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
				'required' => true,
			),
			'googlesheets_worksheet_name' => array(
				'type' => 'text',
				'label' => __( 'Worksheet Name', 'data-machine-business' ),
				'description' => __( 'Name of the worksheet where data will be appended.', 'data-machine-business' ),
				'placeholder' => 'Data Machine Output',
				'default' => 'Data Machine Output',
			),
			'googlesheets_column_mapping' => array(
				'type' => 'textarea',
				'label' => __( 'Column Mapping (JSON)', 'data-machine-business' ),
				'description' => __( 'JSON mapping of data fields to spreadsheet columns.', 'data-machine-business' ),
				'placeholder' => '{"A": "timestamp", "B": "title", "C": "content", "D": "source_url", "E": "source_type", "F": "job_id"}',
				'rows' => 4,
			),
		);
	}

	public static function sanitize( array $raw_settings ): array {
		$sanitized = parent::sanitize( $raw_settings );
		$sanitized['googlesheets_spreadsheet_id'] = preg_replace( '/[^a-zA-Z0-9_-]/', '', $sanitized['googlesheets_spreadsheet_id'] );

		$column_mapping_raw = $raw_settings['googlesheets_column_mapping'] ?? '';
		if ( ! empty( $column_mapping_raw ) ) {
			$decoded = json_decode( $column_mapping_raw, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$valid_mapping = array();
				$valid_fields = array( 'timestamp', 'title', 'content', 'source_url', 'source_type', 'job_id', 'created_at' );

				foreach ( $decoded as $column => $field ) {
					$clean_column = strtoupper( sanitize_text_field( $column ) );
					if ( preg_match( '/^[A-Z]+$/', $clean_column ) ) {
						$clean_field = sanitize_text_field( $field );
						if ( in_array( $clean_field, $valid_fields, true ) ) {
							$valid_mapping[ $clean_column ] = $clean_field;
						}
					}
				}

				$sanitized['googlesheets_column_mapping'] = ! empty( $valid_mapping ) ? $valid_mapping : self::get_default_column_mapping();
			} else {
				$sanitized['googlesheets_column_mapping'] = self::get_default_column_mapping();
			}
		} else {
			$sanitized['googlesheets_column_mapping'] = self::get_default_column_mapping();
		}

		return $sanitized;
	}

	private static function get_default_column_mapping(): array {
		return array(
			'A' => 'timestamp',
			'B' => 'title',
			'C' => 'content',
			'D' => 'source_url',
			'E' => 'source_type',
			'F' => 'job_id',
		);
	}

	public static function validate_authentication( int $user_id ) {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$auth_provider  = $auth_abilities->getProvider( 'googlesheets' );

		if ( ! $auth_provider ) {
			return new \WP_Error( 'googlesheets_auth_unavailable', __( 'Google Sheets authentication service not available.', 'data-machine-business' ) );
		}

		if ( ! $auth_abilities->isHandlerAuthenticated( 'googlesheets' ) ) {
			return new \WP_Error( 'googlesheets_not_authenticated', __( 'Google Sheets authentication required.', 'data-machine-business' ) );
		}

		return true;
	}
}
