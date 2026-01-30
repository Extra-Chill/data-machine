<?php
/**
 * Vision Extraction Processor
 *
 * Downloads flyer images to persistent storage and stores the path in engine data
 * for downstream AI step processing. Does not perform AI extraction directly -
 * AI processing belongs in the pipeline's AI step.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 * @since   0.9.18
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\VisionExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VisionExtractionProcessor {

	private EventImportHandler $handler;

	public function __construct( EventImportHandler $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Process HTML content for flyer images and prepare for AI step.
	 *
	 * Downloads the first unprocessed image candidate to persistent storage,
	 * stores the path in engine data, and returns a minimal data packet.
	 * AI extraction is handled by the pipeline's AI step.
	 *
	 * @param string           $html               HTML content
	 * @param string           $url                Page URL
	 * @param array            $config             Handler configuration
	 * @param ExecutionContext $context            Execution context
	 * @param array|null       $pre_found_candidates Optional pre-found image candidates (e.g., from SquareOnlineExtractor)
	 * @return array|null Array with single data packet or null if no images found
	 */
	public function process(
		string $html,
		string $url,
		array $config,
		ExecutionContext $context,
		?array $pre_found_candidates = null
	): ?array {
		if ( null !== $pre_found_candidates ) {
			$candidates = $pre_found_candidates;
		} else {
			$extractor  = new VisionExtractor();
			$candidates = $extractor->getCandidates( $html, $url );
		}

		if ( empty( $candidates ) ) {
			$context->log(
				'debug',
				'VisionExtractor: No viable image candidates found',
				array( 'url' => $url )
			);
			return null;
		}

		$context->log(
			'info',
			'VisionExtractor: Found image candidates for vision analysis',
			array(
				'url'             => $url,
				'candidate_count' => count( $candidates ),
				'top_score'       => $candidates[0]['score'] ?? 0,
			)
		);

		foreach ( $candidates as $candidate ) {
			$image_url = $candidate['url'];

			// Generate content-based identifier for cross-run tracking.
			$image_identifier = md5( $url . $image_url );

			// Check if already processed (replaces in-memory tracking).
			if ( $context->isItemProcessed( $image_identifier ) ) {
				$context->log(
					'debug',
					'VisionExtractor: Skipping processed image',
					array( 'image_url' => $image_url )
				);
				continue;
			}

			$context->log(
				'debug',
				'VisionExtractor: Processing image candidate',
				array(
					'image_url' => $image_url,
					'score'     => $candidate['score'],
				)
			);

			// Download to persistent storage.
			$file_path = $this->downloadImageToPersistentStorage( $image_url, $context );

			// Mark as processed AFTER download attempt (success or fail).
			$this->handler->markItemAsProcessed( $context, $image_identifier );

			if ( ! $file_path ) {
				$context->log(
					'warning',
					'VisionExtractor: Failed to download image, will try next candidate on next run',
					array( 'image_url' => $image_url )
				);
				return null;
			}

			// Store in engine data for AI step.
			$context->storeEngineData(
				array(
					'image_file_path' => $file_path,
					'source_url'      => $url,
				)
			);

			$context->log(
				'info',
				'VisionExtractor: Image stored for AI processing',
				array(
					'image_url'  => $image_url,
					'file_path'  => $file_path,
					'source_url' => $url,
				)
			);

			// Return minimal packet - AI step will process the image.
			return array(
				array(
					'source_type' => 'vision_flyer',
					'image_url'   => $image_url,
					'page_url'    => $url,
				),
			);
		}

		// All candidates already processed.
		$context->log(
			'info',
			'VisionExtractor: All image candidates already processed',
			array( 'url' => $url )
		);
		return null;
	}

	/**
	 * Download image to persistent flow-isolated storage.
	 *
	 * Uses ExecutionContext::downloadFile() which delegates to RemoteFileDownloader
	 * for flow-isolated storage via FilesRepository.
	 *
	 * @param string           $url     Image URL
	 * @param ExecutionContext $context Execution context
	 * @return string|null File path on success, null on failure
	 */
	private function downloadImageToPersistentStorage( string $url, ExecutionContext $context ): ?string {
		$filename = $this->getFileName( $url );

		$result = $context->downloadFile(
			$url,
			$filename,
			array(
				'timeout' => 30,
			)
		);

		if ( ! $result || empty( $result['path'] ) ) {
			$context->log(
				'warning',
				'VisionExtractor: Failed to download image to persistent storage',
				array( 'url' => $url )
			);
			return null;
		}

		return $result['path'];
	}

	/**
	 * Generate filename from URL.
	 *
	 * @param string $url Image URL
	 * @return string Safe filename
	 */
	private function getFileName( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( $path ) {
			$basename = basename( $path );
			// Remove query strings that might be in the basename.
			$basename = preg_replace( '/\?.*$/', '', $basename );

			if ( $basename && strlen( $basename ) > 4 ) {
				return sanitize_file_name( $basename );
			}
		}

		// Generate a unique filename with extension based on URL hash.
		$hash = substr( md5( $url ), 0, 12 );
		return "flyer-{$hash}.jpg";
	}
}
