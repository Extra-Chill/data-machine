<?php
/**
 * JSON-LD event enrichment source.
 *
 * Reuses JsonLdExtractor's Schema.org parsing on a fetched detail page and
 * surfaces the first event start time found. Detail pages typically carry
 * a single Event node; listing-style pages with several events are not a
 * target here (the enrichment stage follows per-event detail/ticket URLs).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\JsonLdExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsonLdEventSource implements SourceInterface {

	/**
	 * Cached extractor instance reused across pages of a fetch run.
	 *
	 * @var JsonLdExtractor|null
	 */
	private ?JsonLdExtractor $json_ld_extractor = null;

	/**
	 * Which event fields this source can fill.
	 *
	 * @return string[]
	 */
	public function provides(): array {
		return array( 'startTime' );
	}

	/**
	 * Source identifier used in enrichment provenance.
	 *
	 * @return string
	 */
	public function getMethod(): string {
		return 'jsonld';
	}

	/**
	 * Extract a start time from Schema.org JSON-LD on a fetched page.
	 *
	 * @param string $html       Raw page HTML.
	 * @param string $source_url URL the HTML was fetched from.
	 * @return array{startTime?: string} Found fields; empty array when none found.
	 */
	public function extract( string $html, string $source_url = '' ): array {
		if ( null === $this->json_ld_extractor ) {
			$this->json_ld_extractor = new JsonLdExtractor();
		}

		$events = $this->json_ld_extractor->extract( $html, $source_url );

		foreach ( $events as $event ) {
			if ( ! empty( $event['startTime'] ) ) {
				return array( 'startTime' => (string) $event['startTime'] );
			}
		}

		return array();
	}
}
