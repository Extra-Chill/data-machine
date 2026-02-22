<?php
/**
 * Google Sheets Fetch Handler Settings
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleSheets
 * @since 0.1.0
 */

namespace DataMachineBusiness\Handlers\GoogleSheets;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleSheetsFetchSettings extends SettingsHandler {

	public static function get_fields(): array {
		return array(
			'googlesheets_fetch_spreadsheet_id' => array(
				'type' => 'text',
				'label' => __( 'Spreadsheet ID', 'data-machine-business' ),
				'description' => __( 'Google Sheets ID from the URL (e.g., 1abc...xyz from docs.google.com/spreadsheets/d/1abc...xyz/edit).', 'data-machine-business' ),
				'placeholder' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
				'required' => true,
			),
			'googlesheets_fetch_worksheet_name' => array(
				'type' => 'text',
				'label' => __( 'Worksheet Name', 'data-machine-business' ),
				'description' => __( 'Name of the specific worksheet/tab within the spreadsheet to read data from.', 'data-machine-business' ),
				'placeholder' => 'Sheet1',
				'default' => 'Sheet1',
			),
			'googlesheets_fetch_processing_mode' => array(
				'type' => 'select',
				'label' => __( 'Processing Mode', 'data-machine-business' ),
				'description' => __( 'How to process the spreadsheet data.', 'data-machine-business' ),
				'options' => array(
					'by_row' => __( 'By Row (Sequential)', 'data-machine-business' ),
					'by_column' => __( 'By Column (Sequential)', 'data-machine-business' ),
					'full_spreadsheet' => __( 'Full Spreadsheet (All at Once)', 'data-machine-business' ),
				),
				'default' => 'by_row',
			),
			'googlesheets_fetch_has_header_row' => array(
				'type' => 'checkbox',
				'label' => __( 'First Row Contains Headers', 'data-machine-business' ),
				'description' => __( 'Check if the first row contains column headers. Headers will be used as field names in the processed data.', 'data-machine-business' ),
				'default' => true,
			),
		);
	}

	public static function sanitize( array $raw_settings ): array {
		$sanitized = parent::sanitize( $raw_settings );
		$sanitized['googlesheets_fetch_spreadsheet_id'] = preg_replace( '/[^a-zA-Z0-9_-]/', '', $sanitized['googlesheets_fetch_spreadsheet_id'] );
		return $sanitized;
	}

	public static function validate_authentication( int $user_id ) {
		$auth_abilities = new AuthAbilities();
		$auth_provider = $auth_abilities->getProvider( 'googlesheets' );
		if ( ! $auth_provider ) {
			return new \WP_Error( 'googlesheets_auth_unavailable', __( 'Google Sheets authentication service not available.', 'data-machine-business' ) );
		}

		if ( ! $auth_abilities->isHandlerAuthenticated( 'googlesheets' ) ) {
			return new \WP_Error( 'googlesheets_not_authenticated', __( 'Google Sheets authentication required.', 'data-machine-business' ) );
		}

		return true;
	}
}
