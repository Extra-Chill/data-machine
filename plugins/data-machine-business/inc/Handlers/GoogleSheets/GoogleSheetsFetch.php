<?php
/**
 * Google Sheets fetch handler with OAuth2 authentication.
 *
 * Thin wrapper that delegates the API call to FetchGoogleSheetsAbility,
 * then handles pipeline-specific concerns like processing modes (by_row,
 * by_column, full_spreadsheet) and deduplication.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleSheets
 * @since 0.1.0
 */

namespace DataMachineBusiness\Handlers\GoogleSheets;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineBusiness\Abilities\GoogleSheets\FetchGoogleSheetsAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleSheetsFetch extends FetchHandler {

	use HandlerRegistrationTrait;

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
	 * Fetch Google Sheets data as structured rows.
	 *
	 * Delegates the API call to FetchGoogleSheetsAbility, then processes
	 * the raw data according to the configured processing mode.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$spreadsheet_id  = trim( $config['spreadsheet_id'] ?? '' );
		if ( empty( $spreadsheet_id ) ) {
			$context->log( 'error', 'GoogleSheets: Spreadsheet ID is required.' );
			return array();
		}

		$worksheet_name  = trim( $config['worksheet_name'] ?? 'Sheet1' );
		$processing_mode = $config['processing_mode'] ?? 'by_row';
		$has_header_row  = ! empty( $config['has_header_row'] );

		// Delegate to the ability — single source of API logic
		$ability = new FetchGoogleSheetsAbility();
		$result  = $ability->execute(
			array(
				'spreadsheet_id' => $spreadsheet_id,
				'worksheet_name' => $worksheet_name,
			)
		);

		if ( empty( $result['success'] ) ) {
			$context->log( 'error', 'GoogleSheets: ' . ( $result['error'] ?? 'Unknown error' ) );
			return array();
		}

		$sheet_data = $result['data'];
		if ( empty( $sheet_data['values'] ) ) {
			$context->log( 'debug', 'GoogleSheets: No data found in specified range.' );
			return array();
		}

		$rows = $sheet_data['values'];
		$context->log(
			'debug',
			'GoogleSheets: Retrieved spreadsheet data via ability.',
			array(
				'total_rows'      => count( $rows ),
				'processing_mode' => $processing_mode,
			)
		);

		// Pipeline-specific: header extraction and processing modes
		$headers          = array();
		$data_start_index = 0;

		if ( $has_header_row && ! empty( $rows ) ) {
			$headers          = array_map( 'trim', $rows[0] );
			$data_start_index = 1;
			$context->log(
				'debug',
				'GoogleSheets: Using header row.',
				array( 'headers' => $headers )
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
				array( 'sheet_identifier' => $sheet_identifier )
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
					$column_key            = $headers[ $col_index ] ?? 'Column_' . chr( 65 + $col_index );
					$row_data[ $column_key ] = $cell_value;
				}
			}

			if ( ! empty( $row_data ) ) {
				$all_data[] = $row_data;
			}
		}

		$context->storeEngineData(
			array(
				'source_url' => '',
				'image_url'  => '',
			)
		);

		$context->log(
			'debug',
			'GoogleSheets: Processed full spreadsheet.',
			array( 'total_rows' => count( $all_data ) )
		);

		return array(
			'title'    => 'Google Sheets Data: ' . $worksheet_name,
			'content'  => wp_json_encode( $all_data, JSON_PRETTY_PRINT ),
			'metadata' => array(
				'source_type'     => 'googlesheets_fetch',
				'processing_mode' => 'full_spreadsheet',
				'spreadsheet_id'  => $spreadsheet_id,
				'worksheet_name'  => $worksheet_name,
				'headers'         => $headers,
				'total_rows'      => count( $all_data ),
			),
		);
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
					$column_key            = $headers[ $col_index ] ?? 'Column_' . chr( 65 + $col_index );
					$row_data[ $column_key ] = $cell_value;
				}
			}

			if ( empty( $row_data ) ) {
				continue;
			}

			$context->storeEngineData(
				array(
					'source_url' => '',
					'image_url'  => '',
				)
			);

			$context->log(
				'debug',
				'GoogleSheets: Processed row.',
				array( 'row_number' => $i + 1 )
			);

			return array(
				'title'    => 'Row ' . ( $i + 1 ) . ' Data',
				'content'  => wp_json_encode( $row_data, JSON_PRETTY_PRINT ),
				'metadata' => array(
					'source_type'     => 'googlesheets_fetch',
					'processing_mode' => 'by_row',
					'spreadsheet_id'  => $spreadsheet_id,
					'worksheet_name'  => $worksheet_name,
					'row_number'      => $i + 1,
					'headers'         => $headers,
				),
			);
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
			$column_letter     = chr( 65 + $col_index );
			$column_identifier = $spreadsheet_id . '_' . $worksheet_name . '_col_' . $column_letter;

			if ( $context->isItemProcessed( $column_identifier ) ) {
				continue;
			}

			$column_data   = array();
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

			$context->storeEngineData(
				array(
					'source_url' => '',
					'image_url'  => '',
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

			return array(
				'title'    => 'Column: ' . $column_header,
				'content'  => wp_json_encode( array( $column_header => $column_data ), JSON_PRETTY_PRINT ),
				'metadata' => array(
					'source_type'     => 'googlesheets_fetch',
					'processing_mode' => 'by_column',
					'spreadsheet_id'  => $spreadsheet_id,
					'worksheet_name'  => $worksheet_name,
					'column_letter'   => $column_letter,
					'column_header'   => $column_header,
					'headers'         => $headers,
				),
			);
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
