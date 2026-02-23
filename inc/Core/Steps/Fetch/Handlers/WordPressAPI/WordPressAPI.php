<?php
/**
 * WordPress REST API fetch handler with timeframe and keyword filtering.
 *
 * Delegates to FetchWordPressApiAbility for core REST API operations.
 *
 * @package Data_Machine
 * @subpackage Core\Steps\Fetch\Handlers\WordPressAPI
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\WordPressAPI;

use DataMachine\Abilities\Fetch\FetchWordPressApiAbility;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WordPress REST API Fetch Handler
 *
 * Fetches content from external WordPress sites via REST API.
 * Supports timeframe filtering, keyword search, and structured data extraction.
 * Stores source URLs and image URLs in engine data for downstream handlers.
 */
class WordPressAPI extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'rest_api' );

		// Self-register with filters
		self::registerHandler(
			'wordpress_api',
			'fetch',
			self::class,
			'WordPress REST API',
			'Fetch posts from external WordPress sites via REST API',
			false,
			null,
			WordPressAPISettings::class,
			null
		);
	}

	/**
	 * Fetch WordPress posts via REST API with timeframe and keyword filtering.
	 * Delegates to FetchWordPressApiAbility for core operations.
	 * Engine data (source_url, image_file_path) stored via datamachine_engine_data filter.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$endpoint_url = trim( $config['endpoint_url'] ?? '' );
		if ( empty( $endpoint_url ) ) {
			$context->log( 'error', 'WordPressAPI: Endpoint URL is required.' );
			return array();
		}

		if ( ! filter_var( $endpoint_url, FILTER_VALIDATE_URL ) ) {
			$context->log( 'error', 'WordPressAPI: Invalid endpoint URL format.', array( 'endpoint_url' => $endpoint_url ) );
			return array();
		}

		// Get filtering settings
		$timeframe_limit = $config['timeframe_limit'] ?? 'all_time';
		$search          = trim( $config['search'] ?? '' );

		// Get processed items for deduplication
		$processed_items = $this->getProcessedItems( $context );

		// Prepare input for ability
		$ability_input = array(
			'endpoint_url'    => $endpoint_url,
			'timeframe_limit' => $timeframe_limit,
			'search'          => $search,
			'processed_items' => $processed_items,
			'download_images' => true,
		);

		// Execute ability
		$ability = new FetchWordPressApiAbility();
		$result  = $ability->execute( $ability_input );

		// Log all ability logs
		foreach ( $result['logs'] ?? array() as $log_entry ) {
			$context->log(
				$log_entry['level'] ?? 'debug',
				$log_entry['message'] ?? '',
				$log_entry['data'] ?? array()
			);
		}

		// Handle failure
		if ( ! $result['success'] ) {
			return array();
		}

		// Handle empty result
		if ( empty( $result['data'] ) ) {
			return array();
		}

		$raw_data   = $result['data'];
		$item_id    = $result['item_id'] ?? '';
		$source_url = $result['source_url'] ?? '';

		// Mark item as processed
		if ( ! empty( $item_id ) ) {
			$context->markItemProcessed( $item_id );
		}

		// Download image if present in file_info
		$file_info       = null;
		$download_result = null;
		if ( isset( $raw_data['file_info'] ) && ! empty( $raw_data['file_info']['url'] ) ) {
			$image_url = $raw_data['file_info']['url'];

			// Generate filename from URL
			$url_path  = wp_parse_url( $image_url, PHP_URL_PATH );
			$extension = $url_path ? pathinfo( $url_path, PATHINFO_EXTENSION ) : 'jpg';
			if ( empty( $extension ) ) {
				$extension = 'jpg';
			}
			$filename = 'wp_api_image_' . time() . '_' . sanitize_file_name( basename( $url_path ? $url_path : 'image' ) ) . '.' . $extension;

			$download_result = $context->downloadFile( $image_url, $filename );

			if ( $download_result ) {
				$file_check = wp_check_filetype( $filename );
				$mime_type  = $file_check['type'] ? $file_check['type'] : 'image/jpeg';

				$file_info = array(
					'file_path' => $download_result['path'],
					'mime_type' => $mime_type,
					'file_size' => $download_result['size'],
				);

				$context->log(
					'debug',
					'WordPressAPI: Downloaded remote image for AI processing',
					array(
						'item_id'    => $item_id,
						'source_url' => $image_url,
						'local_path' => $download_result['path'],
						'file_size'  => $download_result['size'],
					)
				);

				// Update raw_data with downloaded file info
				$raw_data['file_info'] = $file_info;
			} else {
				$context->log(
					'warning',
					'WordPressAPI: Failed to download remote image',
					array(
						'item_id'   => $item_id,
						'image_url' => $image_url,
					)
				);
				unset( $raw_data['file_info'] );
			}
		}

		// Store URLs and file path in engine_data via centralized filter
		$image_file_path = '';
		if ( $download_result ) {
			$image_file_path = $download_result['path'];
		}

		$context->storeEngineData(
			array(
				'source_url'      => $source_url,
				'image_file_path' => $image_file_path,
			)
		);

		// Return raw data for DataPacket creation
		return $raw_data;
	}

	/**
	 * Get processed items for deduplication.
	 *
	 * @param ExecutionContext $context Execution context.
	 * @return array Array of processed item IDs.
	 */
	private function getProcessedItems( ExecutionContext $context ): array {
		// In direct mode, no deduplication needed
		if ( $context->isDirect() ) {
			return array();
		}

		// Get from ProcessedItemsAbilities via the database
		$flow_step_id = $context->getFlowStepId();
		if ( empty( $flow_step_id ) ) {
			return array();
		}

		// Query processed items for this flow step
		$processed_items_table = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
		return $processed_items_table->get_processed_item_ids( $flow_step_id );
	}

	/**
	 * Get the user-friendly label for this handler.
	 *
	 * @return string Handler label.
	 */
	public static function get_label(): string {
		return __( 'REST API Endpoint', 'data-machine' );
	}
}
