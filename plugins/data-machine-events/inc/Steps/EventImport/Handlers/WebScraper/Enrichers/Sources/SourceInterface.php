<?php
/**
 * Enrichment source interface.
 *
 * Contract for parsers that pull field values out of a fetched detail or
 * ticket page. DetailPageEnricher applies registered sources in order and
 * merges what they find into empty event fields only.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SourceInterface {

	/**
	 * Which event fields this source can fill (e.g. array( 'startTime' )).
	 *
	 * @return string[]
	 */
	public function provides(): array;

	/**
	 * Source identifier used in enrichment provenance
	 * (e.g. "bandzoogle+detail:time_text").
	 *
	 * @return string
	 */
	public function getMethod(): string;

	/**
	 * Extract field values from a fetched page.
	 *
	 * @param string $html       Raw page HTML.
	 * @param string $source_url URL the HTML was fetched from.
	 * @return array<string, string> Found fields; empty array when none found.
	 */
	public function extract( string $html, string $source_url = '' ): array;
}
