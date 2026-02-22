<?php
/**
 * Fetch Google Sheets Ability
 *
 * Abilities API primitive for fetching Google Sheets data.
 * Centralizes Google Sheets API interaction and data processing.
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchGoogleSheetsAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
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
					'label' => __( 'Fetch Google Sheets', 'data-machine' ),
					'description' => __( 'Fetch data from Google Sheets spreadsheets', 'data-machine' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'spreadsheet_id', 'access_token' ),
						'properties' => array(
							'spreadsheet_id' => array(
								'type' => 'string',
								'description' => __( 'Google Sheets spreadsheet ID', 'data-machine' ),
							),
							'access_token' => array(
								'type' => 'string',
								'description' => __( 'Google OAuth access token', 'data-machine' ),
							),
							'worksheet_name' => array(
								'type' => 'string',
								'default' => 'Sheet1',
								'description' => __( 'Name of the worksheet to fetch from', 'data-machine' ),
							),
							'processing_mode' => array(
								'type' => 'string',
								'enum' => array( 'by_row', 'by_column', 'full_spreadsheet' ),
								'default' => 'by_row',
								'description' => __( 'How to process the data (by_row, by_column, full_spreadsheet)', 'data-machine' ),
							),
							'has_header_row' => array(
								'type' => 'boolean',
								'default' => false,
								'description' => __( 'Whether the first row contains headers', 'data-machine' ),
							),
							'processed_items' => array(
								'type' => 'array',
								'default' => array(),
								'description' => __( 'Array of already processed item identifiers', 'data-machine' ),
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

	/**
	 * Permission callback for ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute Google Sheets fetch ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with fetched data or error.
	 */
	public function execute( array $input ): array {
		$logs = array();
		$config = $this->normalizeConfig( $input );

		$spreadsheet_id = $config['spreadsheet_id'];
		$access_token = $config['access_token'];
		$worksheet_name = $config['worksheet_name'];
		$processing_mode = $config['processing_mode'];
		$has_header_row = $config['has_header_row'];
		$processed_items = $config['processed_items'];

		if ( empty( $spreadsheet_id ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'GoogleSheets: Spreadsheet ID is required.' );
			return array(
				'success' => false,
				'error' => 'Spreadsheet ID is required',
				'logs' => $logs,
			);
		}

		if ( empty( $access_token ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'GoogleSheets: Access token is required.' );
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
			'message' => 'GoogleSheets: Fetching spreadsheet data.',
			'data' => array(
				'spreadsheet_id' => $spreadsheet_id,
				'worksheet_name' => $worksheet_name,
				'processing_mode' => $processing_mode,
			),
		);

		$result = $this->httpGet(
			$api_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept' => 'application/json',
				),
				'context' => 'Google Sheets API',
			)
		);

		if ( ! $result['success'] ) {
			$logs[] = array(
				'level' => 'error',
				'message' => 'GoogleSheets: Failed to fetch data.',
				'data' => array(
					'error' => $result['error'],
					'spreadsheet_id' => $spreadsheet_id,
				),
			);
			return array(
				'success' => false,
				'error' => $result['error'],
				'logs' => $logs,
			);
		}

		$response_code = $result['status_code'];
		$response_body = $result['data'];

		if ( 200 !== $response_code ) {
			$error_data = json_decode( $response_body, true );
			$error_message = $error_data['error']['message'] ?? 'Unknown API error';

			$logs[] = array(
				'level' => 'error',
				'message' => 'GoogleSheets: API request failed.',
				'data' => array(
					'status_code' => $response_code,
					'error_message' => $error_message,
					'spreadsheet_id' => $spreadsheet_id,
				),
			);
			return array(
				'success' => false,
				'error' => $error_message,
				'logs' => $logs,
			);
		}

		$sheet_data = json_decode( $response_body, true );
		if ( empty( $sheet_data['values'] ) ) {
			$logs[] = array( 'level' => 'debug', 'message' => 'GoogleSheets: No data found in specified range.' );
			return array(
				'success' => true,
				'data' => array(),
				'logs' => $logs,
			);
		}

		$rows = $sheet_data['values'];
		$logs[] = array(
			'level' => 'debug',
			'message' => 'GoogleSheets: Retrieved spreadsheet data.',
			'data' => array(
				'total_rows' => count( $rows ),
				'processing_mode' => $processing_mode,
			),
		);

		$headers = array();
		$data_start_index = 0;

		if ( $has_header_row && ! empty( $rows ) ) {
			$headers = array_map( 'trim', $rows[0] );
			$data_start_index = 1;
			$logs[] = array(
				'level' => 'debug',
				'message' => 'GoogleSheets: Using header row.',
				'data' => array( 'headers' => $headers ),
			);
		}

		switch ( $processing_mode ) {
			case 'full_spreadsheet':
				return $this->processFullSpreadsheet( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $processed_items, $logs );

			case 'by_column':
				return $this->processByColumn( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $processed_items, $logs );

			case 'by_row':
			default:
				return $this->processByRow( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $processed_items, $logs );
		}
	}

	/**
	 * Normalize input configuration with defaults.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'spreadsheet_id' => '',
			'access_token' => '',
			'worksheet_name' => 'Sheet1',
			'processing_mode' => 'by_row',
			'has_header_row' => false,
			'processed_items' => array(),
		);

		return array_merge( $defaults, $input );
	}

	/**
	 * Make HTTP GET request.
	 */
	private function httpGet( string $url, array $options ): array {
		$args = array(
			'timeout' => $options['timeout'] ?? 30,
			'headers' => $options['headers'] ?? array(),
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error' => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		return array(
			'success' => $status_code >= 200 && $status_code < 300,
			'status_code' => $status_code,
			'data' => $body,
		);
	}

	/**
	 * Process entire spreadsheet as single data packet.
	 */
	private function processFullSpreadsheet( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $processed_items, $logs ): array {
		$sheet_identifier = $spreadsheet_id . '_' . $worksheet_name . '_full';

		if ( in_array( $sheet_identifier, $processed_items, true ) ) {
			$logs[] = array(
				'level' => 'debug',
				'message' => 'GoogleSheets: Full spreadsheet already processed.',
				'data' => array( 'sheet_identifier' => $sheet_identifier ),
			);
			return array(
				'success' => true,
				'data' => array(),
				'logs' => $logs,
			);
		}

		$all_data = array();
		for ( $i = $data_start_index; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];
			if ( empty( array_filter( $row, 'strlen' ) ) ) {
				continue;
			}

			$row_data = array();
			foreach ( $row as $col_index => $cell_value ) {
				$cell_value = trim( $cell_value );
				if ( ! empty( $cell_value ) ) {
					$column_key = $headers[ $col_index ] ?? 'Column_' . chr( 65 + $col_index );
					$row_data[ $column_key ] = $cell_value;
				}
			}

			if ( ! empty( $row_data ) ) {
				$all_data[] = $row_data;
			}
		}

		$metadata = array(
			'source_type' => 'googlesheets_fetch',
			'processing_mode' => 'full_spreadsheet',
			'spreadsheet_id' => $spreadsheet_id,
			'worksheet_name' => $worksheet_name,
			'headers' => $headers,
			'total_rows' => count( $all_data ),
		);

		$raw_data = array(
			'title' => 'Google Sheets Data: ' . $worksheet_name,
			'content' => wp_json_encode( $all_data, JSON_PRETTY_PRINT ),
			'metadata' => $metadata,
		);

		$logs[] = array(
			'level' => 'debug',
			'message' => 'GoogleSheets: Processed full spreadsheet.',
			'data' => array( 'total_rows' => count( $all_data ) ),
		);

		return array(
			'success' => true,
			'data' => $raw_data,
			'item_identifier' => $sheet_identifier,
			'logs' => $logs,
		);
	}

	/**
	 * Process spreadsheet rows individually with deduplication.
	 */
	private function processByRow( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $processed_items, $logs ): array {
		for ( $i = $data_start_index; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];

			if ( empty( array_filter( $row, 'strlen' ) ) ) {
				continue;
			}

			$row_identifier = $spreadsheet_id . '_' . $worksheet_name . '_row_' . ( $i + 1 );

			if ( in_array( $row_identifier, $processed_items, true ) ) {
				continue;
			}

			$row_data = array();
			foreach ( $row as $col_index => $cell_value ) {
				$cell_value = trim( $cell_value );
				if ( ! empty( $cell_value ) ) {
					$column_key = $headers[ $col_index ] ?? 'Column_' . chr( 65 + $col_index );
					$row_data[ $column_key ] = $cell_value;
				}
			}

			if ( empty( $row_data ) ) {
				continue;
			}

			$metadata = array(
				'source_type' => 'googlesheets_fetch',
				'processing_mode' => 'by_row',
				'spreadsheet_id' => $spreadsheet_id,
				'worksheet_name' => $worksheet_name,
				'row_number' => $i + 1,
				'headers' => $headers,
			);

			$raw_data = array(
				'title' => 'Row ' . ( $i + 1 ) . ' Data',
				'content' => wp_json_encode( $row_data, JSON_PRETTY_PRINT ),
				'metadata' => $metadata,
			);

			$logs[] = array(
				'level' => 'debug',
				'message' => 'GoogleSheets: Processed row.',
				'data' => array( 'row_number' => $i + 1 ),
			);

			return array(
				'success' => true,
				'data' => $raw_data,
				'item_identifier' => $row_identifier,
				'logs' => $logs,
			);
		}

		$logs[] = array( 'level' => 'debug', 'message' => 'GoogleSheets: No unprocessed rows found.' );
		return array(
			'success' => true,
			'data' => array(),
			'logs' => $logs,
		);
	}

	/**
	 * Process spreadsheet columns individually with deduplication.
	 */
	private function processByColumn( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $processed_items, $logs ): array {
		if ( empty( $rows ) ) {
			return array(
				'success' => true,
				'data' => array(),
				'logs' => $logs,
			);
		}

		$max_cols = 0;
		foreach ( $rows as $row ) {
			$max_cols = max( $max_cols, count( $row ) );
		}

		for ( $col_index = 0; $col_index < $max_cols; $col_index++ ) {
			$column_letter = chr( 65 + $col_index );
			$column_identifier = $spreadsheet_id . '_' . $worksheet_name . '_col_' . $column_letter;

			if ( in_array( $column_identifier, $processed_items, true ) ) {
				continue;
			}

			$column_data = array();
			$column_header = $headers[ $col_index ] ?? 'Column_' . $column_letter;

			for ( $i = $data_start_index; $i < count( $rows ); $i++ ) {
				$cell_value = trim( $rows[ $i ][ $col_index ] ?? '' );
				if ( ! empty( $cell_value ) ) {
					$column_data[] = $cell_value;
				}
			}

			if ( empty( $column_data ) ) {
				continue;
			}

			$metadata = array(
				'source_type' => 'googlesheets_fetch',
				'processing_mode' => 'by_column',
				'spreadsheet_id' => $spreadsheet_id,
				'worksheet_name' => $worksheet_name,
				'column_letter' => $column_letter,
				'column_header' => $column_header,
				'headers' => $headers,
			);

			$raw_data = array(
				'title' => 'Column: ' . $column_header,
				'content' => wp_json_encode( array( $column_header => $column_data ), JSON_PRETTY_PRINT ),
				'metadata' => $metadata,
			);

			$logs[] = array(
				'level' => 'debug',
				'message' => 'GoogleSheets: Processed column.',
				'data' => array(
					'column_letter' => $column_letter,
					'column_header' => $column_header,
				),
			);

			return array(
				'success' => true,
				'data' => $raw_data,
				'item_identifier' => $column_identifier,
				'logs' => $logs,
			);
		}

		$logs[] = array( 'level' => 'debug', 'message' => 'GoogleSheets: No unprocessed columns found.' );
		return array(
			'success' => true,
			'data' => array(),
			'logs' => $logs,
		);
	}
}
