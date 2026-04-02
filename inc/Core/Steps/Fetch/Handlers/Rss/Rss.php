<?php
/**
 * RSS/Atom feed handler with timeframe and keyword filtering.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\Rss
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Rss;

use DataMachine\Abilities\Fetch\FetchRssAbility;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rss extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'rss' );

		// Self-register with filters
		self::registerHandler(
			'rss',
			'fetch',
			self::class,
			'RSS Feed',
			'Fetch content from RSS and Atom feeds',
			false,
			null,
			RssSettings::class,
			null
		);
	}

	/**
	 * Fetch RSS/Atom content with timeframe and keyword filtering.
	 *
	 * Delegates to FetchRssAbility which returns all eligible items.
	 * Each item is marked as processed and has images downloaded.
	 * Returns { items: [...] } for batch fan-out.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$ability_input = array(
			'feed_url'        => $config['feed_url'] ?? '',
			'timeframe_limit' => $config['timeframe_limit'] ?? 'all_time',
			'search'          => $config['search'] ?? '',
			'processed_items' => array(),
			'download_images' => true,
		);

		$ability = new FetchRssAbility();
		$result  = $ability->execute( $ability_input );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'RSS fetch failed: ' . $result->get_error_message() );
			return array();
		}

		// Log ability logs.
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

		$data = $result['data'];

		// Ability returns { items: [...] } for multi-item.
		$items = $data['items'] ?? array( $data );

		$processed_items = array();

		foreach ( $items as &$item ) {
			$guid = $item['metadata']['guid'] ?? ( $item['metadata']['original_id'] ?? '' );

			// Set item_identifier for centralized dedup in FetchHandler::dedup().
			if ( $guid ) {
				$item['metadata']['item_identifier'] = $guid;
			}

			// Download image if present.
			if ( ! empty( $item['file_info'] ) && ! empty( $item['file_info']['url'] ) ) {
				$image_url       = $item['file_info']['url'];
				$filename        = 'rss_image_' . time() . '_' . sanitize_file_name( basename( wp_parse_url( $image_url, PHP_URL_PATH ) ) );
				$download_result = $context->downloadFile( $image_url, $filename );

				if ( $download_result ) {
					$item['file_info']['file_path'] = $download_result['path'];
					$item['file_info']['file_size'] = $download_result['size'];
					unset( $item['file_info']['url'] );
				} else {
					$context->log(
						'warning',
						'Rss: Failed to download remote image',
						array(
							'guid'          => $guid,
							'enclosure_url' => $image_url,
						)
					);
				}
			}

			$processed_items[] = $item;
		}
		unset( $item );

		if ( empty( $processed_items ) ) {
			return array();
		}

		return array( 'items' => $processed_items );
	}
	/**
	 * Get the display label for the RSS handler.
	 *
	 * @return string Localized handler label
	 */
	public static function get_label(): string {
		return __( 'RSS/Atom Feed', 'data-machine' );
	}
}
