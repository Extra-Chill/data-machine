<?php
// phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * Music Item extractor.
 *
 * Extracts event data from websites using the .music__item / .music__artist
 * HTML pattern commonly found on custom venue websites for live music calendars.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MusicItemExtractor extends BaseExtractor {

	public function canExtract( string $html ): bool {
		return strpos( $html, 'music__item' ) !== false
			&& strpos( $html, 'music__artist' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$loaded      = $this->loadDom( $html );
		$xpath       = $loaded['xpath'];
		$event_nodes = $this->queryElements( $xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' music__item ')]" );

		if ( empty( $event_nodes ) ) {
			return array();
		}
		$page_venue   = PageVenueExtractor::extract( $html, $source_url );
		$current_year = (int) date( 'Y' );
		$events       = array();

		foreach ( $event_nodes as $event_node ) {
			$normalized = $this->normalizeEvent( $xpath, $event_node, $current_year, $source_url );
			if ( ! empty( $normalized['title'] ) ) {
				$normalized = $this->mergePageVenueData( $normalized, $page_venue );
				$events[]   = $normalized;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'music_item';
	}

	// mergePageVenueData() is inherited from BaseExtractor.

	/**
	 * Normalize music item event node to standardized format.
	 */
	private function normalizeEvent( \DOMXPath $xpath, \DOMElement $node, int $year, string $source_url ): array {
		$event = array(
			'title'       => $this->extractArtist( $xpath, $node ),
			'description' => $this->extractDescription( $xpath, $node ),
		);

		$this->parseDate( $event, $xpath, $node, $year );
		$this->parseTime( $event, $xpath, $node );
		$this->parseImage( $event, $xpath, $node, $source_url );
		$this->parseVideoUrl( $event, $xpath, $node );

		return $event;
	}

	/**
	 * Extract artist/band name as event title.
	 */
	private function extractArtist( \DOMXPath $xpath, \DOMElement $node ): string {
		$artist_node = $this->queryFirstElement( $xpath, ".//*[contains(@class, 'music__artist')]", $node );
		if ( $artist_node ) {
			return $this->sanitizeText( $artist_node->textContent );
		}

		return '';
	}

	/**
	 * Extract event description.
	 */
	private function extractDescription( \DOMXPath $xpath, \DOMElement $node ): string {
		$desc_node = $this->queryFirstElement( $xpath, ".//*[contains(@class, 'music__description')]", $node );
		if ( $desc_node ) {
			return $this->cleanHtml( $desc_node->textContent );
		}

		return '';
	}

	/**
	 * Parse date from event node.
	 *
	 * Format: "Friday, January 9" (day of week, month, day - no year)
	 */
	private function parseDate( array &$event, \DOMXPath $xpath, \DOMElement $node, int $year ): void {
		$date_node = $this->queryFirstElement( $xpath, ".//*[contains(@class, 'music__date')]", $node );
		if ( ! $date_node ) {
			return;
		}

		$date_text = '';
		foreach ( $date_node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$date_text .= $child->textContent;
			}
		}
		$date_text = trim( $date_text );

		if ( empty( $date_text ) ) {
			$date_text = trim( $date_node->textContent );
			$date_text = preg_replace( '/\d{1,2}\s*-\s*\d{1,2}/', '', $date_text );
			$date_text = trim( $date_text );
		}

		if ( preg_match( '/(\w+),?\s+(\w+)\s+(\d{1,2})/', $date_text, $matches ) ) {
			$month = $matches[2];
			$day   = $matches[3];

			$date_string = "{$month} {$day}, {$year}";
			$timestamp   = strtotime( $date_string );

			if ( false !== $timestamp ) {
				if ( $timestamp < strtotime( '-1 day' ) ) {
					$next_year = strtotime( "{$month} {$day}, " . ( $year + 1 ) );
					if ( false !== $next_year ) {
						$timestamp = $next_year;
					}
				}
				$event['startDate'] = date( 'Y-m-d', $timestamp );
			}
		}
	}

	/**
	 * Parse time from event node.
	 *
	 * Format: "8-11" (start-end hours, assumes PM for evening venues)
	 */
	private function parseTime( array &$event, \DOMXPath $xpath, \DOMElement $node ): void {
		$time_node = $this->queryFirstElement( $xpath, ".//*[contains(@class, 'music__time')]", $node );
		if ( ! $time_node ) {
			return;
		}

		$time_text = trim( $time_node->textContent );

		if ( preg_match( '/(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?/', $time_text, $matches ) ) {
			$start_hour = (int) $matches[1];
			$start_min  = strlen( $matches[2] ) > 0 ? $matches[2] : '00';
			$end_hour   = (int) $matches[3];
			$end_min    = strlen( $matches[4] ) > 0 ? $matches[4] : '00';

			if ( $start_hour < 12 && $start_hour >= 1 ) {
				$start_hour += 12;
			}
			if ( $end_hour < 12 && $end_hour >= 1 ) {
				$end_hour += 12;
			}

			$event['startTime'] = sprintf( '%02d:%s', $start_hour, $start_min );
			$event['endTime']   = sprintf( '%02d:%s', $end_hour, $end_min );
		}
	}

	/**
	 * Parse image URL from event node.
	 */
	private function parseImage( array &$event, \DOMXPath $xpath, \DOMElement $node, string $source_url ): void {
		$selectors = array(
			".//*[contains(@class, 'music__image')]//img",
			".//*[contains(@class, 'music__video')]//img",
			'.//img',
		);

		foreach ( $selectors as $selector ) {
			$img_node = $this->queryFirstElement( $xpath, $selector, $node );
			if ( $img_node && $img_node->hasAttribute( 'src' ) ) {
				$src               = $img_node->getAttribute( 'src' );
				$event['imageUrl'] = $this->resolveUrl( $src, $source_url );
				return;
			}
		}
	}

	/**
	 * Parse video/event URL from event node.
	 */
	private function parseVideoUrl( array &$event, \DOMXPath $xpath, \DOMElement $node ): void {
		$selectors = array(
			".//*[contains(@class, 'music__video')][@href]",
			".//a[contains(@href, 'youtu')]",
			".//a[contains(@href, 'vimeo')]",
		);

		foreach ( $selectors as $selector ) {
			$link_node = $this->queryFirstElement( $xpath, $selector, $node );
			if ( $link_node && $link_node->hasAttribute( 'href' ) ) {
				$event['eventUrl'] = esc_url_raw( $link_node->getAttribute( 'href' ) );
				return;
			}
		}
	}

	/**
	 * Resolve relative URL to absolute.
	 */
	protected function resolveUrl( string $url, string $source_url ): string {
		if ( strpos( $url, 'http' ) === 0 ) {
			return esc_url_raw( $url );
		}
		$parsed = wp_parse_url( $source_url );
		$base   = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		if ( strpos( $url, '/' ) === 0 ) {
			return esc_url_raw( $base . $url );
		}

		return esc_url_raw( $base . '/' . ltrim( $url, '/' ) );
	}
}
