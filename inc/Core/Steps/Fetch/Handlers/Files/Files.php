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
	 * Delegates to FetchFilesAbility for core logic.
	 * Returns all eligible items for batch fan-out.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$uploaded_files = $config['uploaded_files'] ?? array();

		// Build processed items array from context
		$processed_items = $this->getProcessedItems( $context );

		// Delegate to ability
		$ability_input = array(
			'uploaded_files'  => $uploaded_files,
			'file_context'    => $context->getFileContext(),
			'processed_items' => $processed_items,
		);

		$ability = new FetchFilesAbility();
		$result  = $ability->execute( $ability_input );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'Files fetch failed: ' . $result->get_error_message() );
			return array();
		}

		// Log ability logs
		foreach ( $result['logs'] ?? array() as $log_entry ) {
			$context->log(
				$log_entry['level'] ?? 'debug',
				$log_entry['message'] ?? '',
				$log_entry['data'] ?? array()
			);
		}

		if ( ! $result['success'] || empty( $result['data'] ) ) {
			return array();
		}

		$data  = $result['data'];
		$items = $data['items'] ?? array( $data );

		$processed_items = array();

		foreach ( $items as $item ) {
			$processed_items[] = $item;
		}

		if ( empty( $processed_items ) ) {
			return array();
		}

		return array( 'items' => $processed_items );
	}

	/**
	 * Get processed items for deduplication.
	 *
	 * @param ExecutionContext $context Execution context.
	 * @return array Array of processed item IDs.
	 */
	private function getProcessedItems( ExecutionContext $context ): array {
		if ( $context->isDirect() ) {
			return array();
		}

		$flow_step_id = $context->getFlowStepId();
		if ( empty( $flow_step_id ) ) {
			return array();
		}

		$processed_items_table = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
		return $processed_items_table->get_processed_item_ids( $flow_step_id );
	}
}
