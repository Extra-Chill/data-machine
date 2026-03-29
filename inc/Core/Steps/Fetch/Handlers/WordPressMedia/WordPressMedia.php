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
	use DataMachine\Core\Steps\Fetch\Handlers\Files\Files;

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
	 * Returns all eligible items for batch fan-out.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		// Build processed items list from context
		$processed_items = $this->getProcessedItems( $context );

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
}
