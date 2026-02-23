<?php
/**
 * WordPress media library fetch handler with parent content integration.
 *
 * Delegates to FetchWordPressMediaAbility for core functionality.
 *
 * @package Data_Machine
 * @subpackage Core\Steps\Fetch\Handlers\WordPressMedia
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\WordPressMedia;

use DataMachine\Abilities\Fetch\FetchWordPressMediaAbility;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class WordPressMedia extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'wordpress_media' );

		// Self-register with filters
		self::registerHandler(
		'wordpress_media',
		'fetch',
		self::class,
		'WordPress Media Library',
		'Fetch images and media from WordPress media library',
		false,
		null,
		WordPressMediaSettings::class,
		null
		);
	}

	/**
	 * Fetch WordPress media attachments with parent content integration.
	 * Delegates to FetchWordPressMediaAbility for core functionality.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		// Build processed items list from context
		$processed_items = array();
		// Get processed items from the context's processed items tracking
		// The ability expects an array of already processed IDs

		// Build ability input
		$ability_input = array(
			'file_types'             => $config['file_types'] ?? array( 'image' ),
			'timeframe_limit'        => $config['timeframe_limit'] ?? 'all_time',
			'search'                 => trim( $config['search'] ?? '' ),
			'randomize'              => ! empty( $config['randomize_selection'] ),
			'include_parent_content' => ! empty( $config['include_parent_content'] ),
			'processed_items'        => $processed_items,
		);

		$ability = new FetchWordPressMediaAbility();
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

		if ( ! $result['success'] || empty( $result['data'] ) ) {
			return array();
		}

		$data     = $result['data'];
		$media_id = $data['media_id'] ?? null;

		if ( ! $media_id ) {
			return array();
		}

		// Mark as processed
		$context->markItemProcessed( (string) $media_id );

		// Prepare raw data for DataPacket creation
		$raw_data = array(
			'title'     => $data['title'] ?? '',
			'content'   => $data['content'] ?? '',
			'metadata'  => $data['metadata'] ?? array(),
			'file_info' => $data['file_info'] ?? array(),
		);

		// Store URLs in engine_data via centralized filter
		$source_url      = $result['source_url'] ?? '';
		$image_file_path = $result['image_file_path'] ?? '';

		$context->storeEngineData(
		array(
			'source_url'      => $source_url,
			'image_file_path' => $image_file_path,
		)
		);

		return $raw_data;
	}
}
