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
		// Cactus Club / custom WP themes — uses <!-- eof eventEntryInner --> comment as delimiter.
		'eventEntryInner' => '/<div[^>]+class="[^"]*eventEntryInner[^"]*"[^>]*>(.*?)<!-- eof eventEntryInner -->/is',
		// Generic event-entry patterns — use </article> or next opening tag as delimiter.
		'event-entry'     => '/<(?:div|article|li)[^>]+class="[^"]*event-entry[^"]*"[^>]*>(.*?)<\/(?:article|li)>/is',
		'event-item'      => '/<(?:div|article|li)[^>]+class="[^"]*event-item[^"]*"[^>]*>(.*?)<\/(?:article|li)>/is',
		'event-card'      => '/<(?:div|article|li)[^>]+class="[^"]*event-card[^"]*"[^>]*>(.*?)<\/(?:article|li)>/is',
		'event-listing'   => '/<(?:div|article|li)[^>]+class="[^"]*event-listing[^"]*"[^>]*>(.*?)<\/(?:article|li)>/is',
	);

	/**
	 * Class patterns for extracting fields within a container.
	 * Each key maps to a regex that captures the text content.
	 */
	private const FIELD_PATTERNS = array(
		'title' => array(
			'/<h[1-6][^>]+class=["\'][^"\']*image-with-text__heading[^"\']*["\'][^>]*>(.*?)<\/h[1-6]>/is',
			'/<[^>]+class=["\'][^"\']*resentitem-title[^"\']*["\'][^>]*>.*?<a[^>]*>(.*?)<\/a>/is',
			'/<(?:div|span|h[1-6])[^>]+class="[^"]*eventTitle[^"]*"[^>]*>.*?<a[^>]+title="([^"]+)"/is',
			'/<(?:div|span|p|h[1-6])[^>]+class="[^"]*eventTitle[^"]*"[^>]*>.*?<a[^>]*>(.*?)<\/a>/is',
			'/<(?:div|span|p|h[1-6])[^>]+class="[^"]*event-title[^"]*"[^>]*>(.*?)<\/(?:div|span|p|h[1-6])>/is',
			'/<[^>]+class="[^"]*event-link[^"]*"[^>]*>.*?<a[^>]*>(.*?)<\/a>/is',
			'/<h[1-6][^>]*>\s*<a[^>]+href="[^"]*event[^"]*"[^>]*>(.*?)<\/a>/is',
		),
		'date'  => array(
			'/<[^>]+class=["\'][^"\']*date-event[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
			'/Date:\s*([^<]+)/iu',
			'/<(?:div|span|p|time)[^>]+class="[^"]*eventDate[^"]*"[^>]*>(.*?)<\/(?:div|span|p|time)>/is',
			'/<(?:div|span|p|time)[^>]+class="[^"]*event-date[^"]*"[^>]*>(.*?)<\/(?:div|span|p|time)>/is',
			'/<[^>]+class="[^"]*when[^"]*"[^>]*>(.*?)<\/[^>]+>/is',
			'/<time[^>]+datetime="([^"]+)"/i',
		),
		'time'  => array(
			'/<[^>]+class=["\'][^"\']*time-event[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
			'/Time:\s*([^<]+)/iu',
			'/<span[^>]+class="[^"]*event-time[^"]*"[^>]*>(.*?)<\/span>/is',
			'/<(?:div|span|p)[^>]+class="[^"]*eventTime[^"]*"[^>]*>(.*?)<\/(?:div|span|p)>/is',
		),
		'price' => array(
			'/Entry Fee:\s*([^<]+)/iu',
			'/<(?:div|span)[^>]+class="[^"]*eventPrice[^"]*"[^>]*>(.*?)<\/(?:div|span)>/is',
			'/<(?:div|span)[^>]+class="[^"]*event-price[^"]*"[^>]*>(.*?)<\/(?:div|span)>/is',
		),
		'link'  => array(
			'/<[^>]+class=["\'][^"\']*resentitem-title[^"\']*["\'][^>]*>.*?<a[^>]+href=["\']([^"\']+)["\']/is',
			'/<[^>]+class="[^"]*(?:event-title|event-link)[^"]*"[^>]*>.*?<a[^>]+href="([^"]+)"/is',
			'/<a[^>]+href="(https?:\/\/[^"]*\/events?\/[^"]+)"/i',
			'/<a[^>]+href="(\/events?\/[^"]+)"/i',
		),
		'image' => array(
			'/background-image:\s*url\(([^)]+)\)/i',
			'/<img[^>]+src=["\']([^"\']+)["\']/i',
		),
	);

	/**
	 * Minimum containers required to consider this a valid event listing.
	 */
	private const MIN_CONTAINERS = 3;

	public function canExtract( string $html ): bool {
		// Quick string check before running regexes.
		$has_event_classes = (
			substr_count( $html, 'eventEntryInner' ) >= self::MIN_CONTAINERS
			|| substr_count( $html, 'event-entry' ) >= self::MIN_CONTAINERS
			|| substr_count( $html, 'event-item' ) >= self::MIN_CONTAINERS
			|| substr_count( $html, 'event-card' ) >= self::MIN_CONTAINERS
			|| substr_count( $html, 'event-listing' ) >= self::MIN_CONTAINERS
		);

		if ( $has_event_classes ) {
			// Confirm at least one container pattern actually matches.
			foreach ( self::CONTAINER_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, $html ) ) {
					return true;
				}
			}
		}

		return count( $this->findSemanticContainers( $html ) ) >= self::MIN_CONTAINERS;
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
			$containers = $this->findSemanticContainers( $html );
		}

		if ( empty( $containers ) ) {
			return array();
		}

		$parsed   = wp_parse_url( $source_url );
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

	/**
	 * Find repeated event containers by paired semantic field classes.
	 *
	 * Some WordPress plugins and themes do not name the card itself, but do
	 * consistently expose title/date fields. The nearest ancestor containing
	 * both fields is the event boundary.
	 *
	 * @return string[] Serialized event container HTML.
	 */
	private function findSemanticContainers( string $html ): array {
		if ( '' === $html ) {
			return array();
		}

		$loaded = $this->loadDom( $html );
		$dom    = $loaded['dom'];
		$xpath  = $loaded['xpath'];

		$explicit_containers = $this->findExplicitContainers( $dom, $xpath );
		if ( count( $explicit_containers ) >= self::MIN_CONTAINERS ) {
			return $explicit_containers;
		}

		$pairs = array(
			array( 'event-title', 'event-date' ),
			array( 'event-link', 'when' ),
		);

		foreach ( $pairs as [ $title_class, $date_class ] ) {
			$title_query = sprintf(
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]",
				$title_class
			);
			$title_nodes = $xpath->query( $title_query );
			$containers  = array();

			if ( false === $title_nodes ) {
				continue;
			}

			foreach ( $title_nodes as $title_node ) {
				$container = $title_node->parentNode;

				for ( $depth = 0; $depth < 4 && $container instanceof \DOMElement; ++$depth ) {
					$date_query = sprintf(
						".//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]",
						$date_class
					);
					$date_nodes = $xpath->query( $date_query, $container );

					if ( false !== $date_nodes && $date_nodes->length > 0 ) {
						$path = $container->getNodePath();
						if ( is_string( $path ) ) {
							$containers[ $path ] = $dom->saveHTML( $container );
						}
						break;
					}

					$container = $container->parentNode;
				}
			}

			if ( count( $containers ) >= self::MIN_CONTAINERS ) {
				return array_values( $containers );
			}
		}

		return array();
	}

	/**
	 * Find repeated server-rendered cards whose container classes do not use
	 * generic event names but whose internal fields are unambiguous.
	 *
	 * @return string[] Serialized event container HTML.
	 */
	private function findExplicitContainers( \DOMDocument $dom, \DOMXPath $xpath ): array {
		$containers = array();
		$queries    = array(
			"//*[contains(concat(' ', normalize-space(@class), ' '), ' resentitem ')]",
			"//*[contains(concat(' ', normalize-space(@class), ' '), ' image-with-text ')]",
		);

		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query );
			if ( false === $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				$html = $dom->saveHTML( $node );
				if ( ! is_string( $html ) || ! $this->hasExplicitEventFields( $html ) ) {
					continue;
				}
				$containers[] = $html;
			}

			if ( count( $containers ) >= self::MIN_CONTAINERS ) {
				return $containers;
			}
			$containers = array();
		}

		return array();
	}

	private function hasExplicitEventFields( string $html ): bool {
		$is_events_manager_row = false !== strpos( $html, 'resentitem-title' )
			&& false !== strpos( $html, 'date-event' );
		$is_shopify_event_card = false !== strpos( $html, 'image-with-text__heading' )
			&& false !== strpos( $html, 'Time:' )
			&& false !== strpos( $html, 'Date:' );

		return $is_events_manager_row || $is_shopify_event_card;
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
							$event['startTime'] = $this->parseExplicitTime( $value, $block );
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
							if ( str_starts_with( $url, '//' ) ) {
								$parsed_scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
								$scheme        = $parsed_scheme ? $parsed_scheme : 'https';
								$url           = $scheme . ':' . $url;
							} elseif ( strpos( $url, '/' ) === 0 ) {
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
	 * Parse the first time in a listing field without changing global handling
	 * of ambiguous clock values. Events Manager rows omit meridiem but represent
	 * evening entertainment, while Shopify cards include it explicitly.
	 */
	private function parseExplicitTime( string $value, string $block ): string {
		if ( ! preg_match( '/\b(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $value, $matches ) ) {
			return '';
		}

		$time = trim( $matches[1] );
		if ( false !== strpos( $block, 'resentitem-title' ) && ! preg_match( '/\b(?:am|pm)\b/i', $time ) ) {
			$time .= ' pm';
		}

		return $this->parseTimeString( $time );
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

		$has_year = (bool) preg_match( '/\b(?:19|20)\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', $date_str );
		$ts       = strtotime( $date_str );
		if ( $ts ) {
			$now_year = (int) gmdate( 'Y' );
			$year     = $has_year
				? (int) gmdate( 'Y', $ts )
				: $this->inferYearForMonth( (int) gmdate( 'n', $ts ), (int) gmdate( 'n' ), $now_year );

			// Two-digit year fix: strtotime('03/28/26') → 2026 on most systems,
			// but verify it's reasonable (within 2 years of now).
			if ( $year < 100 ) {
				$year += 2000;
			}
			if ( $year < $now_year - 1 ) {
				$year = $now_year;
			}

			$event['startDate'] = sprintf( '%04d-%02d-%02d', $year, (int) gmdate( 'm', $ts ), (int) gmdate( 'd', $ts ) );
		}
	}

	/**
	 * Treat January-March listings viewed in October-December as the next year.
	 * All other omitted-year dates remain in the current calendar year.
	 */
	private function inferYearForMonth( int $month, int $current_month, int $current_year ): int {
		return $current_month >= 10 && $month <= 3 ? $current_year + 1 : $current_year;
	}
}
