<?php
/**
 * Generic HTML events extractor.
 *
 * Detects and parses event listings from WordPress sites (and similar)
 * that use semantic class names like eventTitle, eventDate, eventTime,
 * eventPrice on repeating container elements.
 *
 * Common patterns:
 *   <div class="eventEntryInner">         (Cactus Club theme)
 *   <div class="event-entry">             (generic WP theme)
 *   <article class="event-item">          (some theme frameworks)
 *
 * Detection requires at least 3 repeating containers that each contain
 * elements with event-related class names (title + date minimum).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenericHtmlEventsExtractor extends BaseExtractor {

	/**
	 * Container patterns that hold individual events.
	 * Matched in order — first match wins.
	 */
	private const CONTAINER_PATTERNS = array(
		// Cactus Club / custom WP themes.
		'eventEntryInner'   => '/<div[^>]+class="[^"]*eventEntryInner[^"]*"[^>]*>(.*?)<\/(?:div|article)>\s*<\/(?:div|article)>/is',
		// Generic event-entry patterns.
		'event-entry'       => '/<(?:div|article|li)[^>]+class="[^"]*event-entry[^"]*"[^>]*>(.*?)<\/(?:div|article|li)>/is',
		'event-item'        => '/<(?:div|article|li)[^>]+class="[^"]*event-item[^"]*"[^>]*>(.*?)<\/(?:div|article|li)>/is',
		'event-card'        => '/<(?:div|article|li)[^>]+class="[^"]*event-card[^"]*"[^>]*>(.*?)<\/(?:div|article|li)>/is',
		'event-listing'     => '/<(?:div|article|li)[^>]+class="[^"]*event-listing[^"]*"[^>]*>(.*?)<\/(?:div|article|li)>/is',
	);

	/**
	 * Class patterns for extracting fields within a container.
	 * Each key maps to a regex that captures the text content.
	 */
	private const FIELD_PATTERNS = array(
		'title'   => array(
			'/<(?:div|span|h[1-6])[^>]+class="[^"]*eventTitle[^"]*"[^>]*>.*?<a[^>]+title="([^"]+)"/is',
			'/<(?:div|span|h[1-6])[^>]+class="[^"]*eventTitle[^"]*"[^>]*>.*?<a[^>]*>(.*?)<\/a>/is',
			'/<(?:div|span|h[1-6])[^>]+class="[^"]*event-title[^"]*"[^>]*>(.*?)<\/(?:div|span|h[1-6])>/is',
			'/<h[1-6][^>]*>\s*<a[^>]+href="[^"]*event[^"]*"[^>]*>(.*?)<\/a>/is',
		),
		'date'    => array(
			'/<(?:div|span|time)[^>]+class="[^"]*eventDate[^"]*"[^>]*>(.*?)<\/(?:div|span|time)>/is',
			'/<(?:div|span|time)[^>]+class="[^"]*event-date[^"]*"[^>]*>(.*?)<\/(?:div|span|time)>/is',
			'/<time[^>]+datetime="([^"]+)"/i',
		),
		'time'    => array(
			'/<(?:div|span)[^>]+class="[^"]*eventTime[^"]*"[^>]*>(.*?)<\/(?:div|span)>/is',
			'/<(?:div|span)[^>]+class="[^"]*event-time[^"]*"[^>]*>(.*?)<\/(?:div|span)>/is',
		),
		'price'   => array(
			'/<(?:div|span)[^>]+class="[^"]*eventPrice[^"]*"[^>]*>(.*?)<\/(?:div|span)>/is',
			'/<(?:div|span)[^>]+class="[^"]*event-price[^"]*"[^>]*>(.*?)<\/(?:div|span)>/is',
		),
		'link'    => array(
			'/<a[^>]+href="(https?:\/\/[^"]*\/events?\/[^"]+)"/i',
			'/<a[^>]+href="(\/events?\/[^"]+)"/i',
		),
		'image'   => array(
			'/background-image:\s*url\(([^)]+)\)/i',
			'/<img[^>]+src="([^"]+)"/i',
		),
	);

	/**
	 * Minimum containers required to consider this a valid event listing.
	 */
	private const MIN_CONTAINERS = 3;

	public function canExtract( string $html ): bool {
		foreach ( self::CONTAINER_PATTERNS as $pattern ) {
			if ( preg_match_all( $pattern, $html, $matches ) && count( $matches[1] ) >= self::MIN_CONTAINERS ) {
				return true;
			}
		}

		return false;
	}

	public function extract( string $html, string $source_url ): array {
		$page_venue = \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor::extract( $html, $source_url );

		// Find the container pattern that matches.
		$containers = array();
		foreach ( self::CONTAINER_PATTERNS as $pattern ) {
			if ( preg_match_all( $pattern, $html, $matches ) && count( $matches[1] ) >= self::MIN_CONTAINERS ) {
				$containers = $matches[1];
				break;
			}
		}

		if ( empty( $containers ) ) {
			return array();
		}

		$parsed  = wp_parse_url( $source_url );
		$base_url = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		$events = array();
		foreach ( $containers as $block ) {
			$event = $this->parseContainer( $block, $base_url, $page_venue );

			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'generic_html_events';
	}

	/**
	 * Parse a single event container block.
	 *
	 * @param string $block     HTML of the container.
	 * @param string $base_url  Base URL for resolving relative links.
	 * @param array  $page_venue Venue info from page context.
	 * @return array Normalized event data.
	 */
	private function parseContainer( string $block, string $base_url, array $page_venue ): array {
		$event = array(
			'title'        => '',
			'startDate'    => '',
			'startTime'    => '',
			'endDate'      => '',
			'source_url'   => '',
			'imageUrl'     => '',
			'ticketUrl'    => '',
			'venue'        => $page_venue['venue'] ?? '',
			'venueAddress' => $page_venue['venueAddress'] ?? '',
			'venueCity'    => $page_venue['venueCity'] ?? '',
			'venueState'   => $page_venue['venueState'] ?? '',
			'venueCountry' => $page_venue['venueCountry'] ?? 'US',
		);

		// Extract each field using the pattern list.
		foreach ( self::FIELD_PATTERNS as $field => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $block, $m ) ) {
					$value = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
					if ( empty( $value ) ) {
						continue;
					}

					switch ( $field ) {
						case 'title':
							$event['title'] = $this->sanitizeText( $value );
							break 2;

						case 'date':
							$this->parseDateField( $event, $value );
							break 2;

						case 'time':
							$event['startTime'] = $this->parseTimeString( $value );
							break 2;

						case 'price':
							$event['ticketPrice'] = $value;
							break 2;

						case 'link':
							$url = $value;
							if ( strpos( $url, '/' ) === 0 ) {
								$url = $base_url . $url;
							}
							$event['source_url'] = esc_url_raw( $url );
							break 2;

						case 'image':
							$url = $value;
							if ( strpos( $url, '/' ) === 0 ) {
								$url = $base_url . $url;
							}
							$event['imageUrl'] = esc_url_raw( $url );
							break 2;
					}
				}
			}
		}

		return $event;
	}

	/**
	 * Parse a date string from various formats.
	 *
	 * Handles:
	 *   "Sat 03/28/26"
	 *   "Tue 03/17/26 — Sun 04/12/26" (date ranges, takes start)
	 *   "March 28, 2026"
	 *   "2026-03-28"
	 *   "03/28/2026"
	 *
	 * @param array  $event Event array to update.
	 * @param string $value Raw date text.
	 */
	private function parseDateField( array &$event, string $value ): void {
		// Handle date ranges — take the start date.
		$range_sep = preg_split( '/\s*[—–\-]\s*(?=[A-Z])/', $value, 2 );
		$date_str  = trim( $range_sep[0] );

		if ( count( $range_sep ) > 1 ) {
			$end_str = trim( $range_sep[1] );
			$end_ts  = strtotime( $end_str );
			if ( $end_ts ) {
				$event['endDate'] = gmdate( 'Y-m-d', $end_ts );
			}
		}

		// Strip day name prefix (Mon, Tue, etc.).
		$date_str = preg_replace( '/^[A-Za-z]{2,3}\s+/', '', $date_str );

		// Try parsing.
		$ts = strtotime( $date_str );
		if ( $ts ) {
			$year = (int) gmdate( 'Y', $ts );

			// Two-digit year fix: strtotime('03/28/26') → 2026 on most systems,
			// but verify it's reasonable (within 2 years of now).
			$now_year = (int) gmdate( 'Y' );
			if ( $year < 100 ) {
				$year += 2000;
			}
			if ( $year < $now_year - 1 ) {
				$year = $now_year;
			}

			$event['startDate'] = sprintf( '%04d-%02d-%02d', $year, (int) gmdate( 'm', $ts ), (int) gmdate( 'd', $ts ) );
		}
	}
}
