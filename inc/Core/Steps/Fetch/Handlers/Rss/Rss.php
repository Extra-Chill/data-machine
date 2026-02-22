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
	 * Delegates to FetchRssAbility for core logic.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		// Delegate to ability
		$ability_input = array(
			'feed_url' => $config['feed_url'] ?? '',
			'timeframe_limit' => $config['timeframe_limit'] ?? 'all_time',
			'search' => $config['search'] ?? '',
			'processed_items' => array(),
			'download_images' => true,
		);

		$ability = new FetchRssAbility();
		$result = $ability->execute( $ability_input );

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

		$data = $result['data'];
		$guid = $result['guid'] ?? ( $data['metadata']['original_id'] ?? '' );

		// Mark item as processed
		if ( $guid ) {
			$context->markItemProcessed( $guid );
		}

		// Download image if present
		if ( ! empty( $data['file_info'] ) && ! empty( $data['file_info']['url'] ) ) {
			$filename = 'rss_image_' . time() . '_' . sanitize_file_name( basename( wp_parse_url( $data['file_info']['url'], PHP_URL_PATH ) ) );
			$download_result = $context->downloadFile( $data['file_info']['url'], $filename );

			if ( $download_result ) {
				$data['file_info']['file_path'] = $download_result['path'];
				$data['file_info']['file_size'] = $download_result['size'];
				unset( $data['file_info']['url'] );

				$context->log(
					'debug',
					'Rss: Downloaded remote image for AI processing',
					array(
						'guid' => $guid,
						'source_url' => $data['file_info']['url'] ?? '',
						'local_path' => $download_result['path'],
						'file_size' => $download_result['size'],
					)
				);
			} else {
				$context->log(
					'warning',
					'Rss: Failed to download remote image',
					array(
						'guid' => $guid,
						'enclosure_url' => $data['file_info']['url'] ?? '',
					)
				);
			}
		}

		// Store engine data
		$engine_data = array( 'source_url' => $result['source_url'] ?? '' );
		if ( ! empty( $data['file_info'] ) && isset( $data['file_info']['file_path'] ) ) {
			$engine_data['image_file_path'] = $data['file_info']['file_path'];
		}
		$context->storeEngineData( $engine_data );

		return $data;
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
