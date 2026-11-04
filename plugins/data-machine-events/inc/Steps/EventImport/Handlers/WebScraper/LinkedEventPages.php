<?php
/**
 * Bounded, platform-independent discovery of JSON-LD events on linked pages.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\DetailPageFetcher;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\JsonLdExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LinkedEventPages {

	public const MAX_FETCHES = 25;

	/** @var array<string, bool> URLs attempted during this fetch cycle. */
	private array $visited = array();

	public function resetBudget(): void {
		$this->visited = array();
	}

	/**
	 * Follow listing links once (never recursively), sharing a budget across
	 * pagination. Transport failures and non-Event pages are simply skipped.
	 */
	public function extract( string $html, string $source_url ): array {
		$events    = array();
		$fetcher   = new DetailPageFetcher();
		$extractor = new JsonLdExtractor();

		foreach ( $this->candidates( $html, $source_url ) as $url ) {
			if ( count( $this->visited ) >= self::MAX_FETCHES ) {
				break;
			}
			if ( isset( $this->visited[ $url ] ) ) {
				continue;
			}
			$this->visited[ $url ] = true;
			$body                  = $fetcher->fetch( $url );
			if ( null === $body || ! $extractor->canExtract( $body ) ) {
				continue;
			}
			foreach ( $extractor->extract( $body, $url ) as $event ) {
				if ( empty( $event['ticketUrl'] ) ) {
					$event['ticketUrl'] = $url;
				}
				$events[] = $event;
			}
		}

		return $events;
	}

	/** @return string[] Absolute HTTP(S) links, excluding navigation and assets. */
	private function candidates( string $html, string $source_url ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<meta charset="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new \DOMXPath( $dom );
		$urls  = array();

		$links = $xpath->query( '//a[@href][not(ancestor::nav or ancestor::header or ancestor::footer)]' );
		if ( false === $links ) {
			return array();
		}

		foreach ( $links as $link ) {
			if ( ! $link instanceof \DOMElement ) {
				continue;
			}
			$url = trim( $link->getAttribute( 'href' ) );
			if ( ! preg_match( '~^https?://~i', $url ) ) {
				continue;
			}
			// Fragments do not identify separate detail pages.
			$url   = explode( '#', $url, 2 )[0];
			$parts = wp_parse_url( $url );
			if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || explode( '#', $source_url, 2 )[0] === $url ) {
				continue;
			}
			$host = strtolower( $parts['host'] );
			if ( preg_match( '~(^|\.)(facebook\.com|instagram\.com|twitter\.com|x\.com|tiktok\.com|youtube\.com|youtu\.be|linkedin\.com|pinterest\.com|threads\.net)$~', $host ) ) {
				continue;
			}
			$path = rawurldecode( $parts['path'] ?? '/' );
			if ( preg_match( '~\.(?:jpe?g|png|gif|webp|svg|ico|pdf|zip|css|js|woff2?|ttf|mp[34]|webm|ics)/?$~i', $path ) ) {
				continue;
			}
			if ( preg_match( '~^/(?:about(?:-us)?|contact(?:-us)?|privacy(?:-policy)?|terms(?:-of-service)?|login|sign-?in|sign-?up|faq|menu|events|calendar)?/?$~i', $path ) ) {
				continue;
			}
			$urls[ $url ] = true;
		}

		return array_keys( $urls );
	}
}
