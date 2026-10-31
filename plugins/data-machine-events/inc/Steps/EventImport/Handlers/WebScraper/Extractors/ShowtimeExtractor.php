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

		$event_nodes = $this->queryElements( $xpath, "//*[contains(@class, 'eventItem')]" );
		if ( empty( $event_nodes ) ) {
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
		$title_link = $this->queryFirstElement( $xpath, ".//*[contains(@class, 'title')]//a", $node );
		if ( null !== $title_link ) {
			$event['title'] = $this->sanitizeText( $title_link->textContent );

			$href = $title_link->getAttribute( 'href' );
			if ( ! empty( $href ) ) {
				$event['sourceUrl'] = $this->resolveUrl( $href, $source_url );
			}
		}

		// Tagline — from h4.tagline (optional subtitle)
		$tagline = $this->queryFirstElement( $xpath, ".//*[contains(@class, 'tagline')]", $node );
		if ( null !== $tagline ) {
			$tagline_text = $this->sanitizeText( $tagline->textContent );
			if ( ! empty( $tagline_text ) ) {
				$event['description'] = $tagline_text;
			}
		}

		// Date — from div.date
		$date_node = $this->queryFirstElement( $xpath, ".//*[contains(@class, 'date')]", $node );
		if ( null !== $date_node ) {
			$this->parseDateText( $event, $this->sanitizeText( $date_node->textContent ) );
		}

		// Venue — from div.location
		$location_node = $this->queryFirstElement( $xpath, ".//*[contains(@class, 'location')]", $node );
		if ( null !== $location_node ) {
			$event['venue'] = $this->sanitizeText( $location_node->textContent );
		}

		// Ticket URL — from a.tickets
		$ticket_link = $this->queryFirstElement( $xpath, ".//a[contains(@class, 'tickets')]", $node );
		if ( null !== $ticket_link ) {
			$ticket_href = $ticket_link->getAttribute( 'href' );
			if ( ! empty( $ticket_href ) ) {
				$event['ticketUrl'] = esc_url_raw( $ticket_href );
			}
		}

		// Image — from div.thumb img
		$img = $this->queryFirstElement( $xpath, ".//div[contains(@class, 'thumb')]//img", $node );
		if ( null !== $img ) {
			$img_src = $img->getAttribute( 'src' );
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
