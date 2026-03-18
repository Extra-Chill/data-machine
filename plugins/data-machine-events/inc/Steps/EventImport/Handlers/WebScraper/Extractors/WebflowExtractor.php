<?php
/**
 * Webflow CMS extractor.
 *
 * Extracts event data from Webflow CMS dynamic collection lists.
 * Webflow sites use custom class names per template, so this extractor
 * identifies dynamic items via `w-dyn-item` and uses heuristics to
 * find event fields (title, date, time, ticket URL) within each item.
 *
 * Detection: `w-dyn-list` or `w-dyn-item` classes in the HTML,
 * combined with `data-wf-site` attribute in the root HTML tag.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebflowExtractor extends BaseExtractor {

	/**
	 * Date pattern: month + day (e.g., "May 16", "Jun 24", "March 5").
	 */
	private const DATE_PATTERN = '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2})\b/i';

	/**
	 * Time pattern: 12-hour time (e.g., "7:00 pm", "8:30 PM", "9pm").
	 */
	private const TIME_PATTERN = '/\b(\d{1,2}(?::\d{2})?)\s*(am|pm|AM|PM)\b/';

	/**
	 * Year pattern for resolving ambiguous dates.
	 */
	private const YEAR_PATTERN = '/\b(202\d)\b/';

	public function canExtract( string $html ): bool {
		// Must be a Webflow site with dynamic collection items.
		$is_webflow = strpos( $html, 'data-wf-site' ) !== false
			|| strpos( $html, 'website-files.com' ) !== false;

		$has_dynamic_list = strpos( $html, 'w-dyn-item' ) !== false;

		return $is_webflow && $has_dynamic_list;
	}

	public function extract( string $html, string $source_url ): array {
		// Extract all w-dyn-item blocks.
		$items = $this->extractDynItems( $html );

		if ( empty( $items ) ) {
			return array();
		}

		$events       = array();
		$current_year = (int) gmdate( 'Y' );

		foreach ( $items as $item_html ) {
			$event = $this->parseItem( $item_html, $source_url, $current_year );
			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'webflow';
	}

	/**
	 * Extract all w-dyn-item HTML blocks from the page.
	 *
	 * @param string $html Full page HTML.
	 * @return array Array of HTML strings, one per dynamic item.
	 */
	private function extractDynItems( string $html ): array {
		$items = array();

		// Split by w-dyn-item occurrences and extract each block.
		// Webflow dynamic items are typically: <div role="listitem" class="... w-dyn-item">...</div>
		$pattern = '/<div[^>]*\bw-dyn-item\b[^>]*>(.*?)(?=<div[^>]*\bw-dyn-item\b|<\/div>\s*<\/div>\s*<\/div>\s*<\/div>)/si';

		if ( preg_match_all( $pattern, $html, $matches ) ) {
			return $matches[1];
		}

		// Fallback: simpler split on role="listitem"
		$pattern2 = '/<div[^>]*role="listitem"[^>]*class="[^"]*w-dyn-item[^"]*"[^>]*>(.*?)(?=<div[^>]*role="listitem"|$)/si';
		if ( preg_match_all( $pattern2, $html, $matches ) ) {
			return $matches[1];
		}

		return array();
	}

	/**
	 * Parse a single dynamic item's HTML to extract event fields.
	 *
	 * Uses heuristic field detection since Webflow class names vary per template.
	 *
	 * @param string $item_html HTML content of one w-dyn-item.
	 * @param string $source_url Source URL for resolving relative links.
	 * @param int    $current_year Current year for date resolution.
	 * @return array Normalized event data.
	 */
	private function parseItem( string $item_html, string $source_url, int $current_year ): array {
		$title     = $this->findTitle( $item_html );
		$date_info = $this->findDate( $item_html, $current_year );
		$time_info = $this->findTime( $item_html );
		$ticket    = $this->findTicketUrl( $item_html, $source_url );
		$image     = $this->findImage( $item_html, $source_url );

		return array(
			'title'     => $this->sanitizeText( $title ),
			'startDate' => $date_info['date'],
			'startTime' => $time_info['time'],
			'endDate'   => '',
			'endTime'   => '',
			'ticketUrl' => $ticket,
			'imageUrl'  => $image,
			'price'     => '',
		);
	}

	/**
	 * Find the event title using heuristics.
	 *
	 * Priority: headings (h1-h4) > elements with "title"/"show"/"artist"/"name" in class > largest text.
	 *
	 * @param string $html Item HTML.
	 * @return string Title text.
	 */
	private function findTitle( string $html ): string {
		// Try headings first.
		if ( preg_match( '/<h[1-4][^>]*>(.*?)<\/h[1-4]>/si', $html, $m ) ) {
			$text = strip_tags( $m[1] );
			if ( strlen( trim( $text ) ) >= 2 ) {
				return trim( $text );
			}
		}

		// Try elements with title/show/artist/name/act/performer in class name.
		$title_patterns = array(
			'/class="[^"]*(?:title|show|artist|name|act|performer|headliner|band)[^"]*"[^>]*>([^<]+)</i',
		);

		foreach ( $title_patterns as $pattern ) {
			if ( preg_match( $pattern, $html, $m ) ) {
				$text = trim( $m[1] );
				if ( strlen( $text ) >= 2 && ! preg_match( self::DATE_PATTERN, $text ) && ! preg_match( self::TIME_PATTERN, $text ) ) {
					return $text;
				}
			}
		}

		// Try the largest non-date, non-time text block.
		if ( preg_match_all( '/>([^<]{3,100})</', $html, $all_text ) ) {
			$candidates = array();
			foreach ( $all_text[1] as $text ) {
				$text = trim( $text );
				if ( empty( $text ) || strlen( $text ) < 3 ) {
					continue;
				}
				// Skip dates, times, day names, generic words.
				if ( preg_match( self::DATE_PATTERN, $text ) || preg_match( self::TIME_PATTERN, $text ) ) {
					continue;
				}
				if ( preg_match( '/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|DOORS|BUY|MORE|INFO|,|\|)$/i', $text ) ) {
					continue;
				}
				if ( preg_match( '/^(buy tickets|more info|learn more|view|details|lorem ipsum)$/i', $text ) ) {
					continue;
				}
				$candidates[] = $text;
			}

			if ( ! empty( $candidates ) ) {
				// Return the longest candidate as likely the title.
				usort( $candidates, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
				return $candidates[0];
			}
		}

		return '';
	}

	/**
	 * Find the event date.
	 *
	 * @param string $html Item HTML.
	 * @param int    $current_year Current year.
	 * @return array{date: string} With YYYY-MM-DD format.
	 */
	private function findDate( string $html, int $current_year ): array {
		// Look for month + day pattern.
		if ( ! preg_match( self::DATE_PATTERN, $html, $m ) ) {
			return array( 'date' => '' );
		}

		$month_str = $m[1];
		$day       = (int) $m[2];

		// Check if a year is present.
		$year = $current_year;
		if ( preg_match( self::YEAR_PATTERN, $html, $ym ) ) {
			$year = (int) $ym[1];
		}

		$month_map = array(
			'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2,
			'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4,
			'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
			'aug' => 8, 'august' => 8, 'sep' => 9, 'september' => 9,
			'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
			'dec' => 12, 'december' => 12,
		);

		$month = $month_map[ strtolower( $month_str ) ] ?? 0;
		if ( 0 === $month || $day < 1 || $day > 31 ) {
			return array( 'date' => '' );
		}

		// If the date is in the past (e.g., Jan event viewed in Nov), assume next year.
		$date_str = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		if ( strtotime( $date_str ) < strtotime( '-7 days' ) && $year === $current_year ) {
			$date_str = sprintf( '%04d-%02d-%02d', $year + 1, $month, $day );
		}

		return array( 'date' => $date_str );
	}

	/**
	 * Find the event start time.
	 *
	 * @param string $html Item HTML.
	 * @return array{time: string} With HH:MM 24-hour format.
	 */
	private function findTime( string $html ): array {
		if ( ! preg_match( self::TIME_PATTERN, $html, $m ) ) {
			return array( 'time' => '' );
		}

		$time_str = $m[1];
		$period   = strtolower( $m[2] );

		// Parse hour and minute.
		$parts  = explode( ':', $time_str );
		$hour   = (int) $parts[0];
		$minute = isset( $parts[1] ) ? (int) $parts[1] : 0;

		// Convert to 24-hour.
		if ( 'pm' === $period && $hour < 12 ) {
			$hour += 12;
		} elseif ( 'am' === $period && 12 === $hour ) {
			$hour = 0;
		}

		return array( 'time' => sprintf( '%02d:%02d', $hour, $minute ) );
	}

	/**
	 * Find the ticket URL from the item.
	 *
	 * @param string $html Item HTML.
	 * @param string $source_url Source URL for resolving relative links.
	 * @return string Ticket URL.
	 */
	private function findTicketUrl( string $html, string $source_url ): string {
		// Look for external ticket links (tixr, etix, eventbrite, dice, etc.).
		$ticket_domains = array( 'tixr', 'etix', 'eventbrite', 'dice.fm', 'ticketmaster', 'seetickets', 'ticketweb', 'aftontickets', 'prekindle', 'showclix' );

		if ( preg_match_all( '/<a[^>]*href="([^"]+)"[^>]*>/i', $html, $links ) ) {
			foreach ( $links[1] as $href ) {
				foreach ( $ticket_domains as $domain ) {
					if ( stripos( $href, $domain ) !== false ) {
						return $this->resolveUrl( $href, $source_url );
					}
				}
			}

			// Fallback: any external link that looks like a ticket.
			foreach ( $links[1] as $href ) {
				if ( preg_match( '#^https?://#', $href ) && stripos( $href, 'ticket' ) !== false ) {
					return $this->resolveUrl( $href, $source_url );
				}
			}

			// Last resort: the first external link.
			foreach ( $links[1] as $href ) {
				if ( preg_match( '#^https?://#', $href ) ) {
					$link_host   = wp_parse_url( $href, PHP_URL_HOST );
					$source_host = wp_parse_url( $source_url, PHP_URL_HOST );
					if ( $link_host && $source_host && $link_host !== $source_host ) {
						return $href;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Find an event image.
	 *
	 * @param string $html Item HTML.
	 * @param string $source_url Source URL for resolving relative links.
	 * @return string Image URL.
	 */
	private function findImage( string $html, string $source_url ): string {
		// Check for background-image in inline styles (common Webflow pattern).
		if ( preg_match( '/background-image:\s*url\(["\']?([^"\')\s]+)["\']?\)/', $html, $m ) ) {
			return $this->resolveUrl( $m[1], $source_url );
		}

		// Check for img tags.
		if ( preg_match( '/<img[^>]*src="([^"]+)"[^>]*>/i', $html, $m ) ) {
			$src = $m[1];
			// Skip placeholder images.
			if ( stripos( $src, 'placeholder' ) === false ) {
				return $this->resolveUrl( $src, $source_url );
			}
		}

		return '';
	}
}
