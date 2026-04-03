<?php
/**
 * Google Sheets publish handler — appends rows to spreadsheets.
 *
 * Thin wrapper that maps pipeline context to ability input and delegates
 * the actual API call to PublishGoogleSheetsAbility.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleSheets
 * @since 0.2.0
 */

namespace DataMachineBusiness\Handlers\GoogleSheets;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineBusiness\Abilities\GoogleSheets\PublishGoogleSheetsAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleSheetsPublish extends PublishHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'googlesheets_publish' );

		self::registerHandler(
			'googlesheets_publish',
			'publish',
			self::class,
			__( 'Google Sheets Publish', 'data-machine-business' ),
			__( 'Append data rows to Google Sheets spreadsheets', 'data-machine-business' ),
			true,
			\DataMachineBusiness\OAuth\Providers\GoogleSheetsAuth::class,
			GoogleSheetsSettings::class,
			null,
			'googlesheets'
		);
	}

	/**
	 * Append a data row to Google Sheets by delegating to the ability.
	 *
	 * Maps pipeline parameters + handler config → ability input,
	 * handles column mapping, then delegates the actual API call.
	 *
	 * @param array $parameters     Tool parameters including content
	 * @param array $handler_config Handler-specific configuration
	 * @return array Result with success, data/error, and tool_name
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$spreadsheet_id = $handler_config['googlesheets_spreadsheet_id'] ?? '';
		if ( empty( $spreadsheet_id ) ) {
			return $this->errorResponse( 'Google Sheets spreadsheet ID is not configured in handler settings' );
		}

		$worksheet_name = $handler_config['googlesheets_worksheet_name'] ?? 'Sheet1';
		$column_mapping = $handler_config['googlesheets_column_mapping'] ?? array();

		// Pipeline-specific: build the row from content using column mapping
		$row = $this->build_row( $parameters, $column_mapping );

		// Build ability input
		$ability_input = array(
			'spreadsheet_id' => $spreadsheet_id,
			'worksheet_name' => $worksheet_name,
			'data'           => array( $row ),
		);

		// Delegate to the ability — single source of API logic
		$ability = new PublishGoogleSheetsAbility();
		$result  = $ability->execute( $ability_input );

		if ( empty( $result['success'] ) ) {
			return $this->errorResponse( $result['error'] ?? 'Unknown Google Sheets error' );
		}

		return $this->successResponse(
			$result['data'] ?? array(),
			'googlesheets_publish'
		);
	}

	/**
	 * Build a row array from pipeline content and column mapping.
	 *
	 * Column mapping is like { "A": "timestamp", "B": "title", "C": "content" }
	 * The row is built in column order (A first, B second, etc).
	 *
	 * @param array $parameters     Pipeline parameters with content/metadata
	 * @param array $column_mapping Column-to-field mapping from settings
	 * @return array Row values in column order
	 */
	private function build_row( array $parameters, array $column_mapping ): array {
		// Available fields from pipeline content
		$fields = array(
			'timestamp'   => wp_date( 'Y-m-d H:i:s' ),
			'title'       => $parameters['title'] ?? '',
			'content'     => $parameters['content'] ?? '',
			'source_url'  => $parameters['source_url'] ?? '',
			'source_type' => $parameters['source_type'] ?? '',
			'job_id'      => $parameters['job_id'] ?? '',
			'created_at'  => wp_date( 'c' ),
		);

		if ( empty( $column_mapping ) ) {
			return array( $fields['title'], $fields['content'] );
		}

		// Determine max column index to size the row correctly
		$max_index   = 0;
		$col_indices = array();
		foreach ( array_keys( $column_mapping ) as $col_letter ) {
			$index                       = $this->column_to_index( $col_letter );
			$col_indices[ $col_letter ]  = $index;
			$max_index                   = max( $max_index, $index );
		}

		// Build the row with empty strings for unmapped columns
		$row = array_fill( 0, $max_index + 1, '' );

		foreach ( $column_mapping as $col_letter => $field_name ) {
			$index = $col_indices[ $col_letter ];
			$value = $fields[ $field_name ] ?? '';

			// Truncate long content for spreadsheet cells
			if ( 'content' === $field_name && strlen( $value ) > 50000 ) {
				$value = substr( $value, 0, 50000 );
			}

			$row[ $index ] = $value;
		}

		return $row;
	}

	/**
	 * Convert a column letter (A, B, ... Z, AA, AB, etc.) to a 0-based index.
	 *
	 * @param string $letter Column letter(s)
	 * @return int 0-based column index
	 */
	private function column_to_index( string $letter ): int {
		$letter = strtoupper( $letter );
		$index  = 0;
		$len    = strlen( $letter );

		for ( $i = 0; $i < $len; $i++ ) {
			$index = $index * 26 + ( ord( $letter[ $i ] ) - ord( 'A' ) + 1 );
		}

		return $index - 1;
	}

	/**
	 * Get handler display label.
	 *
	 * @return string Handler label
	 */
	public static function get_label(): string {
		return __( 'Google Sheets Publish', 'data-machine-business' );
	}
}
