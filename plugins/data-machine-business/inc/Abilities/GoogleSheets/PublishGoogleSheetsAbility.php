<?php
/**
 * Publish Google Sheets Ability
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\GoogleSheets
 * @since 0.1.0
 */

namespace DataMachineBusiness\Abilities\GoogleSheets;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class PublishGoogleSheetsAbility {

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
				'datamachine/publish-googlesheets',
				array(
					'label' => __( 'Publish to Google Sheets', 'data-machine-business' ),
					'description' => __( 'Append data to Google Sheets spreadsheets', 'data-machine-business' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'spreadsheet_id', 'access_token', 'data' ),
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
								'description' => __( 'Name of the worksheet to append to', 'data-machine-business' ),
							),
							'data' => array(
								'type' => 'array',
								'description' => __( 'Array of row data to append', 'data-machine-business' ),
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
		$data = $input['data'] ?? array();

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

		if ( empty( $data ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Data is required.' );
			return array(
				'success' => false,
				'error' => 'Data is required',
				'logs' => $logs,
			);
		}

		$range = $worksheet_name . '!A:Z';
		$api_url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}/values/{$range}:append";

		$body = array(
			'values' => is_array( $data ) ? $data : array( $data ),
		);

		$logs[] = array(
			'level' => 'debug',
			'message' => 'Appending data to spreadsheet.',
			'data' => array(
				'spreadsheet_id' => $spreadsheet_id,
				'worksheet_name' => $worksheet_name,
			),
		);

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( $body ),
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

		$result = json_decode( $body, true );
		$logs[] = array( 'level' => 'debug', 'message' => 'Data appended successfully.' );

		return array(
			'success' => true,
			'data' => $result,
			'logs' => $logs,
		);
	}
}
