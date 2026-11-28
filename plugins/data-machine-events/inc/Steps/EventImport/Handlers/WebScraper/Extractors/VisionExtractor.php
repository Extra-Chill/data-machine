<?php
/**
 * Vision-based event flyer extractor.
 *
 * Lightweight extractor that detects potential flyer images on a page.
 * Actual AI processing is delegated to VisionExtractionProcessor which
 * has access to ExecutionContext for API calls and logging.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.9.18
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\ImageCandidateFinder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VisionExtractor implements ExtractorInterface {

	private ImageCandidateFinder $candidate_finder;

	public function __construct() {
		$this->candidate_finder = new ImageCandidateFinder();
	}

	/**
	 * Check if this page has potential flyer images to analyze.
	 *
	 * @param string $html HTML content to check
	 * @return bool True if viable image candidates exist
	 */
	public function canExtract( string $html ): bool {
		return $this->candidate_finder->hasViableCandidates( $html, '' );
	}

	/**
	 * Check with page URL for better candidate detection.
	 *
	 * @param string $html HTML content
	 * @param string $url  Page URL for resolving relative image paths
	 * @return bool True if viable image candidates exist
	 */
	public function canExtractWithUrl( string $html, string $url ): bool {
		return $this->candidate_finder->hasViableCandidates( $html, $url );
	}

	/**
	 * Extract returns empty - actual processing requires ExecutionContext.
	 *
	 * VisionExtractionProcessor handles the actual AI calls since it needs
	 * ExecutionContext for API requests and logging.
	 *
	 * @param string $html HTML content
	 * @param string $source_url Source URL for context
	 * @return array Always returns empty array
	 */
	public function extract( string $html, string $source_url ): array {
		return array();
	}

	/**
	 * Get the extraction method identifier.
	 *
	 * @return string Method identifier
	 */
	public function getMethod(): string {
		return 'vision';
	}

	/**
	 * Get image candidates for external processing.
	 *
	 * @param string $html HTML content
	 * @param string $url  Page URL
	 * @return array Scored image candidates
	 */
	public function getCandidates( string $html, string $url ): array {
		return $this->candidate_finder->findCandidates( $html, $url );
	}
}
