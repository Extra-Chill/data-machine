<?php
/**
 * Detail page fetcher for the enrichment stage.
 *
 * Thin transport over BaseExtractor::fetchUrl() so detail-page follows
 * inherit the centralised HttpClient error handling, timeouts, and any
 * pre_http_request guards. Exists so DetailPageEnricher can ship a real
 * fetcher default while tests inject a fake fetcher callable.
 *
 * Not an extractor — the ExtractorInterface methods are inert stubs
 * required by the abstract parent.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\BaseExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DetailPageFetcher extends BaseExtractor {

	/** {@inheritdoc} */
	public function canExtract( string $html ): bool {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed> Always empty; this class only transports.
	 */
	public function extract( string $html, string $source_url ): array {
		return array();
	}

	/** {@inheritdoc} */
	public function getMethod(): string {
		return 'detail_page_fetch';
	}

	/**
	 * Fetch a detail/ticket page for enrichment.
	 *
	 * Browser-mode UA first, 15s timeout. Some servers drop or challenge
	 * browser-shaped requests while answering a plain one, so a failed or
	 * captcha response is retried once in standard mode — the same policy
	 * UniversalWebScraper::fetch_html() applies to listing pages.
	 *
	 * @param string $url URL to fetch.
	 * @return string|null Response body, or null on failure.
	 */
	public function fetch( string $url ): ?string {
		$body = $this->fetchUrl(
			$url,
			array(
				'timeout'      => 15,
				'browser_mode' => true,
			),
			'DetailPageEnricher'
		);

		if ( null !== $body && ! self::isChallengePage( $body ) ) {
			return $body;
		}

		$fallback = $this->fetchUrl(
			$url,
			array(
				'timeout'      => 15,
				'browser_mode' => false,
			),
			'DetailPageEnricher (standard mode)'
		);

		if ( null === $fallback || self::isChallengePage( $fallback ) ) {
			return null;
		}

		return $fallback;
	}

	/**
	 * Whether a response body is a bot challenge rather than page content.
	 *
	 * @param string $body Response body.
	 */
	public static function isChallengePage( string $body ): bool {
		return false !== strpos( $body, 'sgcaptcha' )
			|| false !== strpos( $body, 'cloudflare-challenge' )
			|| false !== strpos( $body, 'Checking your browser' );
	}
}
