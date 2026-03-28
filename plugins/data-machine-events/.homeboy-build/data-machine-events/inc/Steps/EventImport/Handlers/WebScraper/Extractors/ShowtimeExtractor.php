<?php
/**
 * Showtime CMS extractor.
 *
 * Extracts event data from venues using the Showtime/Hybrid Framework CMS,
 * commonly used by convention centers and arenas. Detects the platform via
 * the `hybrid_framework.css` stylesheet or `hybrid-framework--modular-js`
 * asset path, then parses `.eventItem` blocks for structured event data.
 *
 * Known venues: The Classic Center / Akins Ford Arena (Athens, GA).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.17.3
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShowtimeExtractor extends BaseExtractor {

	/**
	 * Check if this HTML uses the Showtime/Hybrid Framework CMS.
	 *
	 * @param string $html HTML content to check.
	 * @return bool True if Showtime CMS markers are detected.
	 */
	public function canExtract( string $html ): bool {
		if ( strpos( $html, 'hybrid-framework' ) === false && strpos( $html, 'hybrid_framework' ) === false ) {
			return false;
		}

		return strpos( $html, 'eventItem' ) !== false;
	}

	/**
	 * Extract events from Showtime CMS HTML.
	 *
	 * @param string $html       HTML content.
	 * @param string $source_url Source URL for context.
	 * @return array Array of normalized event objects.
	 */
	public function extract( string $html, string $source_url ): array {
		$loaded = $this->loadDom( $html );
		$xpath  = $loaded['xpath'];

		$event_nodes = $xpath->query( "//*[contains(@class, 'eventItem')]" );
		if ( ! $event_nodes || 0 === $event_nodes->length ) {
			return array();
		}

		$events = array();

		foreach ( $event_nodes as $node ) {
			$event = $this->parseEventNode( $node, $xpath, $source_url );
			if ( ! empty( $event['title'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Get the extraction method identifier.
	 *
	 * @return string
	 */
	public function getMethod(): string {
		return 'showtime';
	}

	/**
	 * Parse a single .eventItem node into a normalized event array.
	 *
	 * @param \DOMNode  $node       The eventItem DOM node.
	 * @param \DOMXPath $xpath      XPath instance.
	 * @param string    $source_url Source URL for resolving relative links.
	 * @return array Normalized event data.
	 */
	private function parseEventNode( \DOMNode $node, \DOMXPath $xpath, string $source_url ): array {
		$event = array();

		// Title — from h3.title > a
		$title_link = $xpath->query( ".//*[contains(@class, 'title')]//a", $node );
		if ( $title_link && $title_link->length > 0 ) {
			$event['title'] = $this->sanitizeText( $title_link->item( 0 )->textContent );

			$href = $title_link->item( 0 )->getAttribute( 'href' );
			if ( ! empty( $href ) ) {
				$event['sourceUrl'] = $this->resolveUrl( $href, $source_url );
			}
		}

		// Tagline — from h4.tagline (optional subtitle)
		$tagline = $xpath->query( ".//*[contains(@class, 'tagline')]", $node );
		if ( $tagline && $tagline->length > 0 ) {
			$tagline_text = $this->sanitizeText( $tagline->item( 0 )->textContent );
			if ( ! empty( $tagline_text ) ) {
				$event['description'] = $tagline_text;
			}
		}

		// Date — from div.date
		$date_node = $xpath->query( ".//*[contains(@class, 'date')]", $node );
		if ( $date_node && $date_node->length > 0 ) {
			$this->parseDateText( $event, $this->sanitizeText( $date_node->item( 0 )->textContent ) );
		}

		// Venue — from div.location
		$location_node = $xpath->query( ".//*[contains(@class, 'location')]", $node );
		if ( $location_node && $location_node->length > 0 ) {
			$event['venue'] = $this->sanitizeText( $location_node->item( 0 )->textContent );
		}

		// Ticket URL — from a.tickets
		$ticket_link = $xpath->query( ".//a[contains(@class, 'tickets')]", $node );
		if ( $ticket_link && $ticket_link->length > 0 ) {
			$ticket_href = $ticket_link->item( 0 )->getAttribute( 'href' );
			if ( ! empty( $ticket_href ) ) {
				$event['ticketUrl'] = esc_url_raw( $ticket_href );
			}
		}

		// Image — from div.thumb img
		$img = $xpath->query( ".//div[contains(@class, 'thumb')]//img", $node );
		if ( $img && $img->length > 0 ) {
			$img_src = $img->item( 0 )->getAttribute( 'src' );
			if ( ! empty( $img_src ) ) {
				$event['imageUrl'] = esc_url_raw( $this->resolveUrl( $img_src, $source_url ) );
			}
		}

		return $event;
	}

	/**
	 * Parse date text from Showtime format.
	 *
	 * Handles formats like:
	 *   - "Saturday, March, 21, 2026"
	 *   - "March, 25 - 26, 2026" (multi-day — use first date)
	 *   - "Thursday, March, 26, 2026"
	 *
	 * @param array  $event    Event array (modified by reference).
	 * @param string $raw_date Raw date text.
	 */
	private function parseDateText( array &$event, string $raw_date ): void {
		// Remove day-of-week prefix if present (e.g., "Saturday, ")
		$cleaned = preg_replace( '/^(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),?\s*/i', '', $raw_date );

		// Handle date ranges: "March, 25 - 26, 2026" → take first date
		$cleaned = preg_replace( '/\s*-\s*\d{1,2}(?=,\s*\d{4})/', '', $cleaned );

		// Showtime uses "Month, Day, Year" with extra commas — normalize
		// "March, 21, 2026" → "March 21 2026"
		$cleaned = str_replace( ',', '', trim( $cleaned ) );

		// Collapse whitespace
		$cleaned = preg_replace( '/\s+/', ' ', $cleaned );

		$timestamp = strtotime( $cleaned );
		if ( false !== $timestamp ) {
			$event['startDate'] = gmdate( 'Y-m-d', $timestamp );
		}
	}
}
