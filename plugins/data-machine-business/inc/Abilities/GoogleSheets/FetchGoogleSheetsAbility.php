<?php
/**
 * Fetch Google Sheets Ability
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\GoogleSheets
 * @since 0.1.0
 */

namespace DataMachineBusiness\Abilities\GoogleSheets;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchGoogleSheetsAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/fetch-googlesheets',
				array(
					'label' => __( 'Fetch Google Sheets', 'data-machine-business' ),
					'description' => __( 'Fetch data from Google Sheets spreadsheets', 'data-machine-business' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'spreadsheet_id', 'access_token' ),
						'properties' => array(
							'spreadsheet_id' => array(
								'type' => 'string',
								'description' => __( 'Google Sheets spreadsheet ID', 'data-machine-business' ),
							),
							'access_token' => array(
								'type' => 'string',
								'description' => __( 'Google OAuth access token', 'data-machine-business' ),
							),
							'worksheet_name' => array(
								'type' => 'string',
								'default' => 'Sheet1',
								'description' => __( 'Name of the worksheet to fetch from', 'data-machine-business' ),
							),
							'processing_mode' => array(
								'type' => 'string',
								'enum' => array( 'by_row', 'by_column', 'full_spreadsheet' ),
								'default' => 'by_row',
								'description' => __( 'How to process the data', 'data-machine-business' ),
							),
							'has_header_row' => array(
								'type' => 'boolean',
								'default' => false,
								'description' => __( 'Whether the first row contains headers', 'data-machine-business' ),
							),
						),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data' => array( 'type' => 'object' ),
							'error' => array( 'type' => 'string' ),
							'logs' => array( 'type' => 'array' ),
							'item_identifier' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta' => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	public function execute( array $input ): array {
		$logs = array();
		$spreadsheet_id = $input['spreadsheet_id'] ?? '';
		$access_token = $input['access_token'] ?? '';
		$worksheet_name = $input['worksheet_name'] ?? 'Sheet1';
		$processing_mode = $input['processing_mode'] ?? 'by_row';
		$has_header_row = $input['has_header_row'] ?? false;

		if ( empty( $spreadsheet_id ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Spreadsheet ID is required.' );
			return array(
				'success' => false,
				'error' => 'Spreadsheet ID is required',
				'logs' => $logs,
			);
		}

		if ( empty( $access_token ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Access token is required.' );
			return array(
				'success' => false,
				'error' => 'Access token is required',
				'logs' => $logs,
			);
		}

		$range_param = urlencode( $worksheet_name );
		$api_url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}/values/{$range_param}";

		$logs[] = array(
			'level' => 'debug',
			'message' => 'Fetching spreadsheet data.',
			'data' => array(
				'spreadsheet_id' => $spreadsheet_id,
				'worksheet_name' => $worksheet_name,
			),
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$logs[] = array( 'level' => 'error', 'message' => $response->get_error_message() );
			return array(
				'success' => false,
				'error' => $response->get_error_message(),
				'logs' => $logs,
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$error_data = json_decode( $body, true );
			$error_message = $error_data['error']['message'] ?? 'Unknown API error';
			$logs[] = array( 'level' => 'error', 'message' => $error_message );
			return array(
				'success' => false,
				'error' => $error_message,
				'logs' => $logs,
			);
		}

		$sheet_data = json_decode( $body, true );
		if ( empty( $sheet_data['values'] ) ) {
			$logs[] = array( 'level' => 'debug', 'message' => 'No data found.' );
			return array(
				'success' => true,
				'data' => array(),
				'logs' => $logs,
			);
		}

		$logs[] = array(
			'level' => 'debug',
			'message' => 'Retrieved spreadsheet data.',
			'data' => array( 'total_rows' => count( $sheet_data['values'] ) ),
		);

		return array(
			'success' => true,
			'data' => $sheet_data,
			'logs' => $logs,
		);
	}
}
