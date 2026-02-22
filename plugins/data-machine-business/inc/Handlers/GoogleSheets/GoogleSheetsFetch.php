<?php
/**
 * Google Sheets fetch handler with OAuth2 authentication.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleSheets
 * @since 0.1.0
 */

namespace DataMachineBusiness\Handlers\GoogleSheets;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleSheetsFetch extends FetchHandler {

	use HandlerRegistrationTrait;

	private $auth_service;

	public function __construct() {
		parent::__construct( 'googlesheets_fetch' );

		self::registerHandler(
			'googlesheets_fetch',
			'fetch',
			self::class,
			__( 'Google Sheets', 'data-machine-business' ),
			__( 'Fetch data from Google Sheets spreadsheets', 'data-machine-business' ),
			true,
			\DataMachineBusiness\OAuth\Providers\GoogleSheetsAuth::class,
			GoogleSheetsFetchSettings::class,
			null,
			'googlesheets'
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return object|null Auth provider instance or null if unavailable
	 */
	private function get_auth_service() {
		if ( $this->auth_service === null ) {
			$this->auth_service = $this->getAuthProvider( 'googlesheets' );

			if ( $this->auth_service === null ) {
				$auth_abilities = new AuthAbilities();
				do_action(
					'datamachine_log',
					'error',
					'Google Sheets Handler: Authentication service not available',
					array(
						'handler' => 'googlesheets',
						'missing_service' => 'googlesheets',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth_service;
	}

	/**
	 * Fetch Google Sheets data as structured rows.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$spreadsheet_id = trim( $config['spreadsheet_id'] ?? '' );
		if ( empty( $spreadsheet_id ) ) {
			$context->log( 'error', 'GoogleSheets: Spreadsheet ID is required.' );
			return array();
		}

		$worksheet_name = trim( $config['worksheet_name'] ?? 'Sheet1' );
		$processing_mode = $config['processing_mode'] ?? 'by_row';
		$has_header_row = ! empty( $config['has_header_row'] );

		$auth_service = $this->get_auth_service();
		if ( ! $auth_service ) {
			$context->log( 'error', 'GoogleSheets: Authentication not configured' );
			return array();
		}

		$access_token = $auth_service->get_service();
		if ( is_wp_error( $access_token ) ) {
			$context->log(
				'error',
				'GoogleSheets: Authentication failed.',
				array(
					'error' => $access_token->get_error_message(),
				)
			);
			return array();
		}

		$range_param = urlencode( $worksheet_name );
		$api_url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}/values/{$range_param}";

		$context->log(
			'debug',
			'GoogleSheets: Fetching spreadsheet data.',
			array(
				'spreadsheet_id' => $spreadsheet_id,
				'worksheet_name' => $worksheet_name,
				'processing_mode' => $processing_mode,
			)
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
			$context->log(
				'error',
				'GoogleSheets: Failed to fetch data.',
				array(
					'error' => $result['error'],
					'spreadsheet_id' => $spreadsheet_id,
				)
			);
			return array();
		}

		$response_code = $result['status_code'];
		$response_body = $result['data'];

		if ( 200 !== $response_code ) {
			$error_data = json_decode( $response_body, true );
			$error_message = $error_data['error']['message'] ?? 'Unknown API error';

			$context->log(
				'error',
				'GoogleSheets: API request failed.',
				array(
					'status_code' => $response_code,
					'error_message' => $error_message,
					'spreadsheet_id' => $spreadsheet_id,
				)
			);
			return array();
		}

		$sheet_data = json_decode( $response_body, true );
		if ( empty( $sheet_data['values'] ) ) {
			$context->log( 'debug', 'GoogleSheets: No data found in specified range.' );
			return array();
		}

		$rows = $sheet_data['values'];
		$context->log(
			'debug',
			'GoogleSheets: Retrieved spreadsheet data.',
			array(
				'total_rows' => count( $rows ),
				'processing_mode' => $processing_mode,
			)
		);

		$headers = array();
		$data_start_index = 0;

		if ( $has_header_row && ! empty( $rows ) ) {
			$headers = array_map( 'trim', $rows[0] );
			$data_start_index = 1;
			$context->log(
				'debug',
				'GoogleSheets: Using header row.',
				array(
					'headers' => $headers,
				)
			);
		}

		switch ( $processing_mode ) {
			case 'full_spreadsheet':
				return $this->process_full_spreadsheet( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $context );

			case 'by_column':
				return $this->process_by_column( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $context );

			case 'by_row':
			default:
				return $this->process_by_row( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, $context );
		}
	}

	/**
	 * Process entire spreadsheet as single data packet.
	 */
	private function process_full_spreadsheet( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, ExecutionContext $context ) {
		$sheet_identifier = $spreadsheet_id . '_' . $worksheet_name . '_full';

		if ( $context->isItemProcessed( $sheet_identifier ) ) {
			$context->log(
				'debug',
				'GoogleSheets: Full spreadsheet already processed.',
				array(
					'sheet_identifier' => $sheet_identifier,
				)
			);
			return array();
		}

		$context->markItemProcessed( $sheet_identifier );

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

		$context->storeEngineData(
			array(
				'source_url' => '',
				'image_url' => '',
			)
		);

		$context->log(
			'debug',
			'GoogleSheets: Processed full spreadsheet.',
			array(
				'total_rows' => count( $all_data ),
			)
		);

		return $raw_data;
	}

	/**
	 * Process spreadsheet rows individually with deduplication.
	 */
	private function process_by_row( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, ExecutionContext $context ) {
		for ( $i = $data_start_index; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];

			if ( empty( array_filter( $row, 'strlen' ) ) ) {
				continue;
			}

			$row_identifier = $spreadsheet_id . '_' . $worksheet_name . '_row_' . ( $i + 1 );

			if ( $context->isItemProcessed( $row_identifier ) ) {
				continue;
			}

			$context->markItemProcessed( $row_identifier );

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

			$context->storeEngineData(
				array(
					'source_url' => '',
					'image_url' => '',
				)
			);

			$context->log(
				'debug',
				'GoogleSheets: Processed row.',
				array(
					'row_number' => $i + 1,
				)
			);

			return $raw_data;
		}

		$context->log( 'debug', 'GoogleSheets: No unprocessed rows found.' );
		return array();
	}

	/**
	 * Process spreadsheet columns individually with deduplication.
	 */
	private function process_by_column( $rows, $headers, $data_start_index, $spreadsheet_id, $worksheet_name, ExecutionContext $context ) {
		if ( empty( $rows ) ) {
			return array();
		}

		$max_cols = 0;
		foreach ( $rows as $row ) {
			$max_cols = max( $max_cols, count( $row ) );
		}

		for ( $col_index = 0; $col_index < $max_cols; $col_index++ ) {
			$column_letter = chr( 65 + $col_index );
			$column_identifier = $spreadsheet_id . '_' . $worksheet_name . '_col_' . $column_letter;

			if ( $context->isItemProcessed( $column_identifier ) ) {
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

			$context->markItemProcessed( $column_identifier );

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

			$context->storeEngineData(
				array(
					'source_url' => '',
					'image_url' => '',
				)
			);

			$context->log(
				'debug',
				'GoogleSheets: Processed column.',
				array(
					'column_letter' => $column_letter,
					'column_header' => $column_header,
				)
			);

			return $raw_data;
		}

		$context->log( 'debug', 'GoogleSheets: No unprocessed columns found.' );
		return array();
	}

	/**
	 * Get handler display label.
	 *
	 * @return string Handler label
	 */
	public static function get_label(): string {
		return __( 'Google Sheets Fetch', 'data-machine-business' );
	}
}
