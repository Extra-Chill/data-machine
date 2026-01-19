<?php
/**
 * Structured data extractor interface.
 *
 * Contract for extractors that parse embedded structured data from HTML pages.
 * Each extractor handles a specific format (Wix Events, JSON-LD, microdata, etc.).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ExtractorInterface {

	/**
	 * Check if this extractor can handle the given HTML content.
	 *
	 * @param string $html HTML content to check
	 * @return bool True if this extractor can extract events from the content
	 */
	public function canExtract( string $html ): bool;

	/**
	 * Extract events from HTML content.
	 *
	 * @param string $html HTML content
	 * @param string $source_url Source URL for context
	 * @return array Array of normalized event objects
	 */
	public function extract( string $html, string $source_url ): array;

	/**
	 * Get the extraction method identifier.
	 *
	 * @return string Method identifier (e.g., 'wix_events', 'jsonld', 'microdata')
	 */
	public function getMethod(): string;
}
