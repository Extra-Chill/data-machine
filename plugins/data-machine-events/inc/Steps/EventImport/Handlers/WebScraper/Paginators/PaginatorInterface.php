<?php
/**
 * Paginator interface for Universal Web Scraper.
 *
 * Contract for paginators that discover and construct next page URLs.
 * Each paginator handles a specific pagination strategy (JSON API, HTML links, etc.).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PaginatorInterface {

	/**
	 * Check if this paginator can handle the given URL/content combination.
	 *
	 * @param string $url     Current page URL.
	 * @param string $content Page content (HTML or JSON).
	 * @return bool True if this paginator can handle pagination for this content.
	 */
	public function canPaginate( string $url, string $content ): bool;

	/**
	 * Get the next page URL, or null if no more pages.
	 *
	 * @param string $current_url Current page URL.
	 * @param string $content     Current page content.
	 * @return string|null Next page URL, or null if no more pages.
	 */
	public function getNextPageUrl( string $current_url, string $content ): ?string;

	/**
	 * Get paginator identifier for logging.
	 *
	 * @return string Method identifier (e.g., 'json_api', 'html_link').
	 */
	public function getMethod(): string;
}
