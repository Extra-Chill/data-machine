<?php
/**
 * Fetch Google Sheets Ability
 *
 * This is the bottom layer — pure business logic, no handler config,
 * no engine data, no pipeline context. Any caller (REST, CLI, chat tool,
 * pipeline handler) can invoke this directly.
 *
 * Resolves its own authentication via the registered auth provider.
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
					'label'               => __( 'Fetch Google Sheets', 'data-machine-business' ),
					'description'         => __( 'Fetch data from Google Sheets spreadsheets', 'data-machine-business' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'spreadsheet_id' ),
						'properties' => array(
							'spreadsheet_id' => array(
								'type'        => 'string',
								'description' => __( 'Google Sheets spreadsheet ID', 'data-machine-business' ),
							),
							'worksheet_name' => array(
								'type'        => 'string',
								'default'     => 'Sheet1',
								'description' => __( 'Name of the worksheet to fetch from', 'data-machine-business' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
							'logs'    => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
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

	/**
	 * Fetch raw spreadsheet data from Google Sheets.
	 *
	 * Resolves auth automatically. Returns the raw Sheets API response
	 * with all values. Processing (by_row, by_column, etc.) is the
	 * handler's responsibility, not the ability's.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success, data (raw sheet values), and logs.
	 */
	public function execute( array $input ): array {
		$logs           = array();
		$spreadsheet_id = $input['spreadsheet_id'] ?? '';
		$worksheet_name = $input['worksheet_name'] ?? 'Sheet1';

		if ( empty( $spreadsheet_id ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Spreadsheet ID is required.' );
			return array(
				'success' => false,
				'error'   => 'Spreadsheet ID is required',
				'logs'    => $logs,
			);
		}

		$auth_provider = self::get_auth_provider();
		if ( ! $auth_provider ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Google Sheets authentication not configured.' );
			return array(
				'success' => false,
				'error'   => 'Google Sheets authentication not configured',
				'logs'    => $logs,
			);
		}

		$access_token = $auth_provider->get_service();
		if ( is_wp_error( $access_token ) ) {
			$logs[] = array( 'level' => 'error', 'message' => $access_token->get_error_message() );
			return array(
				'success' => false,
				'error'   => $access_token->get_error_message(),
				'logs'    => $logs,
			);
		}

		$range_param = urlencode( $worksheet_name );
		$api_url     = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}/values/{$range_param}";

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Fetching spreadsheet data.',
			'data'    => array(
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
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$logs[] = array( 'level' => 'error', 'message' => $response->get_error_message() );
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'logs'    => $logs,
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$error_data    = json_decode( $body, true );
			$error_message = $error_data['error']['message'] ?? 'Unknown API error';
			$logs[]        = array( 'level' => 'error', 'message' => $error_message );
			return array(
				'success' => false,
				'error'   => $error_message,
				'logs'    => $logs,
			);
		}

		$sheet_data = json_decode( $body, true );
		if ( empty( $sheet_data['values'] ) ) {
			$logs[] = array( 'level' => 'debug', 'message' => 'No data found.' );
			return array(
				'success' => true,
				'data'    => array(),
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Retrieved spreadsheet data.',
			'data'    => array( 'total_rows' => count( $sheet_data['values'] ) ),
		);

		return array(
			'success' => true,
			'data'    => $sheet_data,
			'logs'    => $logs,
		);
	}

	/**
	 * Get the Google Sheets auth provider instance.
	 *
	 * @return \DataMachineBusiness\OAuth\Providers\GoogleSheetsAuth|null
	 */
	private static function get_auth_provider(): ?\DataMachineBusiness\OAuth\Providers\GoogleSheetsAuth {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		return $providers['googlesheets'] ?? null;
	}
}
