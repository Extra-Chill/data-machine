<?php
/**
 * Weebly Events extractor.
 *
 * Extracts event data from Weebly sites that use repeating image+text block
 * patterns for event listings. Common with small bar/venue sites on Weebly.
 *
 * Detection: looks for Weebly-specific CSS classes (.wsite-) or the
 * "Site powered by Weebly" meta tag.
 *
 * Event blocks are .paragraph divs containing structured text:
 *   - Line 1: "Friday April 10th" (day + date)
 *   - Middle lines: artist names / event description
 *   - Price line: "$15 Cover"
 *   - Time line: "Doors at 8pm"
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.28.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeeblyExtractor extends BaseExtractor {

	/**
	 * Minimum .paragraph blocks that look like events to confirm extraction.
	 */
	private const MIN_EVENT_BLOCKS = 3;

	public function canExtract( string $html ): bool {
		// Weebly fingerprint: CSS classes or powered-by text.
		$is_weebly = strpos( $html, 'wsite-' ) !== false
			|| stripos( $html, 'powered by Weebly' ) !== false
			|| stripos( $html, 'powered by weebly' ) !== false;

		if ( ! $is_weebly ) {
			return false;
		}

		// Must have .paragraph divs — they hold the event text.
		$paragraph_count = substr_count( $html, 'class="paragraph"' )
			+ substr_count( $html, "class='paragraph'" );

		return $paragraph_count >= self::MIN_EVENT_BLOCKS;
	}

	public function extract( string $html, string $source_url ): array {
		$blocks = $this->extractParagraphBlocks( $html );
		if ( empty( $blocks ) ) {
			return array();
		}

		// Filter to only blocks that start with a date line.
		$event_blocks = array_filter( $blocks, array( $this, 'isEventBlock' ) );

		if ( count( $event_blocks ) < self::MIN_EVENT_BLOCKS ) {
			return array();
		}

		$page_venue = \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor::extract( $html, $source_url );

		$parsed   = wp_parse_url( $source_url );
		$base_url = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		$events = array();
		foreach ( $event_blocks as $lines ) {
			$event = $this->parseEventBlock( $lines, $base_url, $page_venue );
			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'weebly';
	}

	// ────────────────────────────────────────────────────────────────────────────
	// HTML Parsing
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Extract all .paragraph divs as arrays of text lines.
	 *
	 * @param string $html Page HTML.
	 * @return array Array of string arrays (one per paragraph block).
	 */
	private function extractParagraphBlocks( string $html ): array {
		if ( ! preg_match_all( '/<div[^>]+class=["\'][^"\']*paragraph[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $matches ) ) {
			return array();
		}

		$blocks = array();
		foreach ( $matches[1] as $raw ) {
			$lines = $this->htmlToLines( $raw );
			if ( ! empty( $lines ) ) {
				$blocks[] = $lines;
			}
		}

		return $blocks;
	}

	/**
	 * Convert raw HTML inside a .paragraph div to an array of trimmed text lines.
	 *
	 * Handles Weebly's common patterns: <br> tags, &#8203; zero-width spaces,
	 * &nbsp; entities, and nested font/span/a tags.
	 *
	 * @param string $html Raw HTML content.
	 * @return array Non-empty text lines.
	 */
	private function htmlToLines( string $html ): array {
		// Replace <br> tags with newlines.
		$text = preg_replace( '/<br\s*\/?>/i', "\n", $html );

		// Remove zero-width spaces (HTML entity and raw Unicode).
		$text = str_replace( array( '&#8203;', '&#x200b;', "\u{200B}" ), '', $text );

		// Strip all HTML tags.
		$text = wp_strip_all_tags( $text );

		// Decode HTML entities (&amp; → &, &nbsp; → space, etc.).
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalize whitespace within lines but preserve newlines.
		$lines = explode( "\n", $text );
		$lines = array_map(
			function ( $line ) {
				return trim( preg_replace( '/\s+/', ' ', $line ) );
			},
			$lines
		);

		return array_values( array_filter( $lines ) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Event Block Parsing
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Check if a block of text lines starts with a date pattern.
	 *
	 * Matches patterns like "Friday April 10th", "Saturday April 11th",
	 * "Wednesday April 15th", etc.
	 *
	 * @param array $lines Text lines from a paragraph block.
	 * @return bool True if the block starts with a date line.
	 */
	private function isEventBlock( array $lines ): bool {
		if ( empty( $lines ) ) {
			return false;
		}

		return (bool) preg_match(
			'/^(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s+(?:January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}(?:st|nd|rd|th)?/i',
			$lines[0]
		);
	}

	/**
	 * Parse a single event block into a normalized event array.
	 *
	 * Block structure:
	 *   Line 0: "Friday April 10th"
	 *   Lines 1..N-2: Artist names / event description
	 *   Line N-1 or N: "$15 Cover" or "Doors at 8pm"
	 *   Line N: "Doors at 8pm"
	 *
	 * @param array  $lines     Text lines from the paragraph block.
	 * @param string $base_url  Site base URL for resolving images.
	 * @param array  $page_venue Venue info from page context.
	 * @return array Normalized event data.
	 */
	private function parseEventBlock( array $lines, string $base_url, array $page_venue ): array {
		$event = array(
			'title'        => '',
			'description'  => '',
			'startDate'    => '',
			'startTime'    => '',
			'venue'        => $page_venue['venue'] ?? '',
			'venueAddress' => $page_venue['venueAddress'] ?? '',
			'venueCity'    => $page_venue['venueCity'] ?? '',
			'venueState'   => $page_venue['venueState'] ?? '',
			'venueCountry' => $page_venue['venueCountry'] ?? 'US',
		);

		// Parse date from first line.
		$this->parseDateLine( $event, $lines[0] );

		// Collect the remaining lines (everything after date).
		$body_lines = array_slice( $lines, 1 );

		// Extract time and price from body, then the rest is artists/description.
		$time       = '';
		$price      = '';
		$artist_lines = array();

		foreach ( $body_lines as $line ) {
			if ( preg_match( '/^Doors\s+(?:at\s+)?(\d{1,2}(?::\d{2})?\s*(?:am|pm))/i', $line, $m ) ) {
				$time = $this->parseTimeString( $m[1] );
				continue;
			}

			if ( preg_match( '/^\$(\d+(?:\.\d{2})?)\s+Cover/i', $line, $m ) ) {
				$price = '$' . $m[1];
				continue;
			}

			// Everything else is artist names or event description.
			$artist_lines[] = $line;
		}

		// Build title from first artist line, rest goes to description.
		if ( ! empty( $artist_lines ) ) {
			$event['title'] = $this->sanitizeText( $artist_lines[0] );

			if ( count( $artist_lines ) > 1 ) {
				$event['description'] = $this->sanitizeText( implode( ', ', array_slice( $artist_lines, 1 ) ) );
			}
		}

		// Apply time and price.
		if ( ! empty( $time ) ) {
			$event['startTime'] = $time;
		}
		if ( ! empty( $price ) ) {
			$event['ticketPrice'] = $price;
		}

		return $event;
	}

	/**
	 * Parse a date line like "Friday April 10th" into Y-m-d format.
	 *
	 * Uses the day-of-week prefix to determine the correct year.
	 * Tries the current year first; if the resulting weekday doesn't
	 * match, tries the next year. Falls back to inferDateFromMonthDay().
	 *
	 * @param array  $event Event array to update.
	 * @param string $line  Date line text.
	 */
	private function parseDateLine( array &$event, string $line ): void {
		// Strip ordinal suffix for cleaner parsing.
		$clean = preg_replace( '/(\d+)(st|nd|rd|th)/i', '$1', $line );

		// Extract day-of-week name if present.
		$day_name = '';
		if ( preg_match( '/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s+/i', $clean, $m ) ) {
			$day_name = strtolower( $m[1] );
			$clean    = substr( $clean, strlen( $m[0] ) );
		}

		$parts = explode( ' ', trim( $clean ), 2 );
		$month = $parts[0] ?? '';
		$day   = $parts[1] ?? '';

		if ( empty( $month ) || empty( $day ) ) {
			return;
		}

		// If we have a day name, use it to pick the correct year.
		if ( ! empty( $day_name ) ) {
			$date = $this->inferDateWithDayOfWeek( $month, $day, $day_name );
			if ( ! empty( $date ) ) {
				$event['startDate'] = $date;
				return;
			}
		}

		// Fallback without day-of-week validation.
		$date = $this->inferDateFromMonthDay( $month, $day );
		if ( ! empty( $date ) ) {
			$event['startDate'] = $date;
		}
	}

	/**
	 * Infer a date using month, day, and day-of-week for year disambiguation.
	 *
	 * Tries the current year; if the weekday doesn't match, tries next year.
	 *
	 * @param string $month    Month name (e.g. "April", "Jan").
	 * @param string $day      Day number (e.g. "10").
	 * @param string $day_name Lowercase day name (e.g. "friday").
	 * @return string Y-m-d date or empty string.
	 */
	private function inferDateWithDayOfWeek( string $month, string $day, string $day_name ): string {
		$year = (int) gmdate( 'Y' );

		for ( $i = 0; $i < 2; $i++ ) {
			$try_year = $year + $i;
			try {
				$dt = new \DateTime( "{$month} {$day} {$try_year}" );
				if ( strtolower( $dt->format( 'l' ) ) === $day_name ) {
					return $dt->format( 'Y-m-d' );
				}
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return '';
	}
}
