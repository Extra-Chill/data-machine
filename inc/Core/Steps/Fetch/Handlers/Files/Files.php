<?php
/**
* Handles file uploads as a data source.
*
* @package Data_Machine
* @subpackage Core\Steps\Fetch\Handlers\Files
* @since 0.7.0
*/
namespace DataMachine\Core\Steps\Fetch\Handlers\Files;

use DataMachine\Abilities\Fetch\FetchFilesAbility;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Files extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'files' );

		// Self-register with filters
		self::registerHandler(
			'files',
			'fetch',
			self::class,
			'File Upload',
			'Process uploaded files and images',
			false,
			null,
			FilesSettings::class,
			null
		);
	}

	/**
	 * Process uploaded files with universal image handling.
	 * For images: stores image_file_path via datamachine_engine_data filter.
	 *
	 * Delegates to FetchFilesAbility for core logic.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$uploaded_files = $config['uploaded_files'] ?? array();

		// Build processed items array from context
		$processed_items = array();

		// Delegate to ability
		$ability_input = array(
			'uploaded_files'  => $uploaded_files,
			'file_context'    => $context->getFileContext(),
			'processed_items' => $processed_items,
		);

		$ability = new FetchFilesAbility();
		$result  = $ability->execute( $ability_input );

		// Log ability logs
		if ( ! empty( $result['logs'] ) && is_array( $result['logs'] ) ) {
			foreach ( $result['logs'] as $log_entry ) {
				$context->log(
					$log_entry['level'] ?? 'debug',
					$log_entry['message'] ?? '',
					$log_entry['data'] ?? array()
				);
			}
		}

		if ( ! $result['success'] ) {
			return array();
		}

		// If no data returned, return empty
		if ( empty( $result['data'] ) ) {
			return array();
		}

		$data            = $result['data'];
		$item_identifier = $result['item_identifier'] ?? ( $data['metadata']['original_id'] ?? '' );

		// Mark item as processed
		if ( $item_identifier ) {
			$context->markItemProcessed( $item_identifier );
		}

		// Store engine data if present
		if ( ! empty( $data['engine_data'] ) ) {
			$context->storeEngineData( $data['engine_data'] );
		}

		return $data;
	}
}
