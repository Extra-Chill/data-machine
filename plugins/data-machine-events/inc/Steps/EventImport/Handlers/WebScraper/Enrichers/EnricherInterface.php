<?php
/**
 * Enricher interface for Universal Web Scraper.
 *
 * Contract for pipeline stages that fill missing event fields after
 * extraction (second-hop enrichment). Sibling contract to
 * Extractors\ExtractorInterface and Paginators\PaginatorInterface.
 *
 * Enrichers run between extractor output and StructuredDataProcessor for
 * extractors that opt in via needsDetailEnrichment(). They fill gaps only —
 * a field present on the listing page is never overwritten.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers;

use DataMachine\Core\ExecutionContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface EnricherInterface {

	/**
	 * Which event fields this enricher can fill (e.g. array( 'startTime', 'endTime' )).
	 *
	 * @return string[]
	 */
	public function provides(): array;

	/**
	 * Whether this event needs enrichment (a provided field is empty) and has a candidate URL.
	 *
	 * @param array<string, mixed> $event Extracted event data.
	 * @return bool
	 */
	public function shouldEnrich( array $event ): bool;

	/**
	 * Fetch + parse + return the event with empty fields filled.
	 *
	 * Only fills fields that are empty; never overwrites values present on
	 * the listing page.
	 *
	 * @param array<string, mixed> $event      Extracted event data.
	 * @param string               $source_url Listing page URL the event was extracted from.
	 * @param ExecutionContext     $context    Execution context for logging.
	 * @return array<string, mixed> Event with enrichment applied.
	 */
	public function enrich( array $event, string $source_url, ExecutionContext $context ): array;

	/**
	 * Get enricher identifier for logging.
	 *
	 * @return string Method identifier (e.g. 'detail_page').
	 */
	public function getMethod(): string;
}
