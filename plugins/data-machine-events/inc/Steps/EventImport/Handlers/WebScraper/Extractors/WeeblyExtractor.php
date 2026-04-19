<?php
/**
 * Weebly Events extractor.
 *
 * Supports two Weebly listing patterns:
 *
 * Pattern A — per-block events (original):
 *   Separate .paragraph divs, each containing one event with a date header
 *   like "Friday April 10th", followed by artist names, price, and time.
 *
 * Pattern B — multi-artist showcase schedule (new):
 *   A single .paragraph div containing ALL events, separated by date headers
 *   like "Barn Jam April 22" or "May 6 Barn Jam". Each date header is followed
 *   by time+artist lines such as "5:50 Eliza Grace". This pattern is common
 *   for weekly showcase series at small venues (e.g. Awendaw Green Barn Jams).
 *
 * Detection: looks for Weebly-specific CSS classes (.wsite-) or the
 * "Site powered by Weebly" meta tag.
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
	 * Minimum .paragraph blocks that look like events to confirm Pattern A extraction.
	 */
	private const MIN_EVENT_BLOCKS = 3;

	/**
	 * Minimum showcase date headers to confirm Pattern B extraction.
	 */
	private const MIN_SHOWCASE_HEADERS = 2;

	/**
	 * Regex for month names (full and abbreviated).
	 */
	private const MONTH_PATTERN = '(?:January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';

	/**
	 * Regex for day-of-week names.
	 */
	private const DOW_PATTERN = '(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)';

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

		if ( $paragraph_count < 1 ) {
			return false;
		}

		// Check for Pattern A: multiple paragraph blocks with date headers.
		$blocks = $this->extractParagraphBlocks( $html );
		$event_blocks = array_filter( $blocks, array( $this, 'isPatternABlock' ) );
		if ( count( $event_blocks ) >= self::MIN_EVENT_BLOCKS ) {
			return true;
		}

		// Check for Pattern B: a single block with multiple showcase date headers.
		foreach ( $blocks as $lines ) {
			if ( $this->countShowcaseDateHeaders( $lines ) >= self::MIN_SHOWCASE_HEADERS ) {
				return true;
			}
		}

		return false;
	}

	public function extract( string $html, string $source_url ): array {
		$blocks = $this->extractParagraphBlocks( $html );
		if ( empty( $blocks ) ) {
			return array();
		}

		$page_venue = \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor::extract( $html, $source_url );

		$parsed   = wp_parse_url( $source_url );
		$base_url = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		// Try Pattern A first: separate blocks with individual date headers.
		$event_blocks = array_filter( $blocks, array( $this, 'isPatternABlock' ) );
		if ( count( $event_blocks ) >= self::MIN_EVENT_BLOCKS ) {
			$events = array();
			foreach ( $event_blocks as $lines ) {
				$event = $this->parsePatternABlock( $lines, $base_url, $page_venue );
				if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
					$events[] = $event;
				}
			}
			return $events;
		}

		// Try Pattern B: single block with multi-artist showcase schedule.
		foreach ( $blocks as $lines ) {
			if ( $this->countShowcaseDateHeaders( $lines ) >= self::MIN_SHOWCASE_HEADERS ) {
				return $this->parseShowcaseBlock( $lines, $page_venue );
			}
		}

		return array();
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
	// Pattern A: Per-Block Events
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Check if a block of text lines starts with a Pattern A date header.
	 *
	 * Matches "Friday April 10th", "Saturday April 11th", etc.
	 *
	 * @param array $lines Text lines from a paragraph block.
	 * @return bool True if the block starts with a date line.
	 */
	private function isPatternABlock( array $lines ): bool {
		if ( empty( $lines ) ) {
			return false;
		}

		return (bool) preg_match(
			'/^' . self::DOW_PATTERN . '\s+' . self::MONTH_PATTERN . '\s+\d{1,2}(?:st|nd|rd|th)?/i',
			$lines[0]
		);
	}

	/**
	 * Parse a Pattern A event block into a normalized event array.
	 *
	 * Block structure:
	 *   Line 0: "Friday April 10th"
	 *   Lines 1..N-2: Artist names / event description
	 *   Price line: "$15 Cover"
	 *   Time line: "Doors at 8pm"
	 *
	 * @param array  $lines      Text lines from the paragraph block.
	 * @param string $base_url   Site base URL for resolving images.
	 * @param array  $page_venue Venue info from page context.
	 * @return array Normalized event data.
	 */
	private function parsePatternABlock( array $lines, string $base_url, array $page_venue ): array {
		$event = $this->newEventArray( $page_venue );

		// Parse date from first line.
		$this->parseDateLine( $event, $lines[0] );

		// Collect the remaining lines (everything after date).
		$body_lines = array_slice( $lines, 1 );

		// Extract time and price from body, then the rest is artists/description.
		$time        = '';
		$price       = '';
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

	// ────────────────────────────────────────────────────────────────────────────
	// Pattern B: Multi-Artist Showcase Schedule
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Count showcase date headers in a block of lines.
	 *
	 * Matches headers like:
	 *   "Barn Jam April 22"
	 *   "May 6 Barn Jam"
	 *   "Open Mic June 15"
	 *   "April 22"
	 *   "April 22 Barn Jam"
	 *
	 * Must contain a month name + day number to be a date header.
	 *
	 * @param array $lines Text lines from a paragraph block.
	 * @return int Number of showcase date headers found.
	 */
	private function countShowcaseDateHeaders( array $lines ): int {
		$count = 0;
		foreach ( $lines as $line ) {
			if ( $this->isShowcaseDateHeader( $line ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Check if a line is a showcase date header.
	 *
	 * Matches patterns where a month+day appear alongside optional label text
	 * like "Barn Jam", but NOT lines that start with a time+artist pattern
	 * (e.g. "5:50 Eliza Grace") or are just URLs.
	 *
	 * @param string $line Text line to check.
	 * @return bool True if this looks like a showcase date header.
	 */
	private function isShowcaseDateHeader( string $line ): bool {
		// Skip lines that start with a time pattern (artist slots).
		if ( preg_match( '/^\d{1,2}:\d{2}\s/', $line ) ) {
			return false;
		}

		// Skip lines that look like URLs or are very short.
		if ( preg_match( '#^https?://#i', $line ) ) {
			return false;
		}

		// Must contain a month name followed by a day number.
		// Matches: "Barn Jam April 22", "May 6 Barn Jam", "April 22", etc.
		$month = self::MONTH_PATTERN;
		return (bool) preg_match(
			'/\b' . $month . '\s+\d{1,2}\b/i',
			$line
		);
	}

	/**
	 * Parse a showcase schedule block into multiple events.
	 *
	 * Splits the block at date header lines, then parses each sub-event
	 * for time+artist slots.
	 *
	 * @param array $lines      All text lines from the paragraph block.
	 * @param array $page_venue Venue info from page context.
	 * @return array Array of normalized event arrays.
	 */
	private function parseShowcaseBlock( array $lines, array $page_venue ): array {
		// Split into sub-events at date header boundaries.
		$sub_events = $this->splitShowcaseBlock( $lines );

		$events = array();
		foreach ( $sub_events as $sub_lines ) {
			$event = $this->parseShowcaseSubEvent( $sub_lines, $page_venue );
			if ( ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Split a showcase block into sub-event line groups at date headers.
	 *
	 * @param array $lines All lines in the block.
	 * @return array Array of line arrays, one per sub-event.
	 */
	private function splitShowcaseBlock( array $lines ): array {
		$sub_events  = array();
		$current     = array();

		foreach ( $lines as $line ) {
			if ( $this->isShowcaseDateHeader( $line ) && ! empty( $current ) ) {
				$sub_events[] = $current;
				$current      = array();
			}
			$current[] = $line;
		}

		if ( ! empty( $current ) ) {
			$sub_events[] = $current;
		}

		return $sub_events;
	}

	/**
	 * Parse a single showcase sub-event (date header + artist slots).
	 *
	 * Input format:
	 *   "Barn Jam April 22"
	 *   "5:50 Eliza Grace"
	 *   "https://instagram.com/eliza_grace_music"
	 *   "6:40 Jarret Forrester"
	 *   "https://instagram.com/jarretforrestermusic/"
	 *   ...
	 *
	 * Output: one event with all artists listed in the description with times.
	 *
	 * @param array $lines      Lines for this sub-event (header + slots).
	 * @param array $page_venue Venue info from page context.
	 * @return array Normalized event data.
	 */
	private function parseShowcaseSubEvent( array $lines, array $page_venue ): array {
		$event = $this->newEventArray( $page_venue );

		if ( empty( $lines ) ) {
			return $event;
		}

		// First line is the date header.
		$this->parseShowcaseDateHeader( $event, $lines[0] );

		// Remaining lines: extract time+artist slots, skip URLs.
		$artists    = array();
		$first_time = '';

		for ( $i = 1; $i < count( $lines ); $i++ ) {
			$line = $lines[ $i ];

			// Skip lines that are only a URL (artist links on separate lines).
			if ( preg_match( '#^https?://#i', $line ) ) {
				continue;
			}

			// Match time+artist pattern: "5:50 Eliza Grace" or "5:00 The Band Name"
			if ( preg_match( '/^(\d{1,2}:\d{2})\s+(.+)$/', $line, $m ) ) {
				$display_time = $m[1];
				$artist_name  = $this->stripTrailingUrls( trim( $m[2] ) );

				if ( ! empty( $artist_name ) ) {
					// Assume PM for evening events (typical showcase hours 5pm-11pm).
					$hour = (int) explode( ':', $display_time )[0];
					$parse_time = $display_time;
					if ( $hour >= 1 && $hour <= 11 ) {
						$parse_time .= 'pm';
					}
					$parsed_time = $this->parseTimeString( $parse_time );

					if ( empty( $first_time ) && ! empty( $parsed_time ) ) {
						$first_time = $parsed_time;
					}

					$artists[] = array(
						'time' => $display_time,
						'name' => $artist_name,
					);
				}
			}
		}

		// Build event from extracted data.
		if ( ! empty( $artists ) ) {
			// Use a generic title derived from the series name, or first artist.
			$series_name    = $this->extractSeriesName( $lines[0] );
			$event['title'] = $series_name ?: $artists[0]['name'];

			// Build description with time+artist lineup.
			$lineup = array();
			foreach ( $artists as $artist ) {
				$lineup[] = $artist['time'] . ' ' . $artist['name'];
			}
			$event['description'] = implode( "\n", $lineup );
		}

		if ( ! empty( $first_time ) ) {
			$event['startTime'] = $first_time;
		}

		return $event;
	}

	/**
	 * Strip trailing URLs from a text string.
	 *
	 * Some Weebly pages have URLs appended to the same line as artist names,
	 * e.g. "Run River Run https://www.runriverrunband.com/".
	 *
	 * @param string $text Text that may contain trailing URLs.
	 * @return string Text with trailing URLs removed.
	 */
	private function stripTrailingUrls( string $text ): string {
		// Remove URLs that appear after the artist name on the same line.
		// The /u flag enables UTF-8 mode so \s matches non-breaking spaces (U+00A0).
		$text = preg_replace( '#\s+https?://\S+#iu', '', $text );
		return $this->sanitizeText( $text );
	}

	/**
	 * Extract the series/show name from a date header line.
	 *
	 * "Barn Jam April 22" → "Barn Jam"
	 * "May 6 Barn Jam" → "Barn Jam"
	 * "April 22" → "" (no series name)
	 *
	 * @param string $header Date header line.
	 * @return string Series name or empty string.
	 */
	private function extractSeriesName( string $header ): string {
		$month = self::MONTH_PATTERN;

		// Try "Label Month Day" — extract everything before the month.
		if ( preg_match( '/^(.+?)\s+' . $month . '\s+\d{1,2}/i', $header, $m ) ) {
			$name = trim( $m[1] );
			// Filter out things that aren't series names (e.g. year numbers).
			if ( ! preg_match( '/^\d{4}$/', $name ) ) {
				return $this->sanitizeText( $name );
			}
		}

		// Try "Month Day Label" — extract everything after the day number.
		if ( preg_match( '/' . $month . '\s+\d{1,2}\s+(.+)$/i', $header, $m ) ) {
			return $this->sanitizeText( $m[1] );
		}

		return '';
	}

	/**
	 * Parse a showcase date header into startDate.
	 *
	 * Handles:
	 *   "Barn Jam April 22" → 2026-04-22
	 *   "May 6 Barn Jam" → 2026-05-06
	 *   "April 22" → 2026-04-22
	 *
	 * @param array  $event Event array to update.
	 * @param string $line  Date header text.
	 */
	private function parseShowcaseDateHeader( array &$event, string $line ): void {
		$month = self::MONTH_PATTERN;

		// Extract month and day from anywhere in the line.
		if ( ! preg_match( '/(' . $month . ')\s+(\d{1,2})/i', $line, $m ) ) {
			return;
		}

		$month_name = $m[1];
		$day        = $m[2];

		// Use inferDateFromMonthDay which handles year disambiguation.
		$date = $this->inferDateFromMonthDay( $month_name, $day );
		if ( ! empty( $date ) ) {
			$event['startDate'] = $date;
		}
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Shared Utilities
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Create a new event array with venue defaults.
	 *
	 * @param array $page_venue Page-level venue data.
	 * @return array Event array with defaults populated.
	 */
	private function newEventArray( array $page_venue ): array {
		return array(
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
	}

	/**
	 * Parse a Pattern A date line like "Friday April 10th" into Y-m-d format.
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
		if ( preg_match( '/^(' . self::DOW_PATTERN . ')\s+/i', $clean, $m ) ) {
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
