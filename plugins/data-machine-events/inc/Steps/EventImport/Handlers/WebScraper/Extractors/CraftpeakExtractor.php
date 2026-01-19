<?php
/**
 * Craftpeak extractor.
 *
 * Extracts event data from Craftpeak/Arryved platform websites commonly used
 * by craft breweries. These are WordPress-based sites using the "Label" theme
 * with Beaver Builder.
 *
 * Detection signatures:
 * - craftpeak-cooler-images.imgix.net (image CDN)
 * - /app/themes/label/ (theme path)
 * - /event/{slug}-{YYYY-MM-DD}/ URL pattern
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CraftpeakExtractor extends BaseExtractor {

	/**
	 * Check if HTML contains Craftpeak platform signatures.
	 *
	 * @param string $html Page HTML content
	 * @return bool True if Craftpeak signatures detected
	 */
	public function canExtract( string $html ): bool {
		// Craftpeak image CDN
		if ( strpos( $html, 'craftpeak-cooler-images.imgix.net' ) !== false ) {
			return true;
		}

		// Label theme stylesheet
		if ( strpos( $html, '/app/themes/label/' ) !== false ) {
			return true;
		}

		// Event URL pattern: /event/{slug}-{YYYY-MM-DD}/
		if ( preg_match( '/href=[\'"](?:https?:\/\/[^\'"]+)?\/event\/[a-z0-9-]+-\d{4}-\d{2}-\d{2}\/[\'"]/', $html ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Extract events from Craftpeak page.
	 *
	 * @param string $html Page HTML content
	 * @param string $source_url Source URL for context
	 * @return array Array of normalized event data
	 */
	public function extract( string $html, string $source_url ): array {
		$page_venue = PageVenueExtractor::extract( $html, $source_url );

		$raw_events = $this->parseEventCards( $html, $source_url );

		if ( empty( $raw_events ) ) {
			return array();
		}

		$events = array();
		foreach ( $raw_events as $raw ) {
			$normalized = $this->normalizeEvent( $raw, $page_venue );
			if ( ! empty( $normalized['title'] ) && ! empty( $normalized['startDate'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	/**
	 * Get extractor method identifier.
	 *
	 * @return string
	 */
	public function getMethod(): string {
		return 'craftpeak';
	}

	/**
	 * Parse event cards from HTML.
	 *
	 * Craftpeak event listings use anchor elements linking to /event/{slug}/
	 * with nested content including image, date/time, and title.
	 *
	 * @param string $html Page HTML content
	 * @param string $source_url Base URL for resolving relative links
	 * @return array Array of raw event data
	 */
	private function parseEventCards( string $html, string $source_url ): array {
		$events     = array();
		$parsed_url = parse_url( $source_url );
		$base_url   = ( $parsed_url['scheme'] ?? 'https' ) . '://' . ( $parsed_url['host'] ?? '' );

		// Find all anchor elements linking to /event/ pages
		// Pattern captures the full anchor including nested content
		// Note: Craftpeak uses single quotes for attributes, so match both quote styles
		if ( ! preg_match_all( '/<a[^>]+href=[\'"]([^\'"]*\/event\/[^\'"]+)[\'"][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$seen_urls = array();

		foreach ( $matches as $match ) {
			$event_url = $match[1];
			$card_html = $match[2];

			// Make URL absolute
			if ( strpos( $event_url, 'http' ) !== 0 ) {
				$event_url = $base_url . $event_url;
			}

			// Skip duplicate URLs (same event linked multiple times)
			if ( isset( $seen_urls[ $event_url ] ) ) {
				continue;
			}
			$seen_urls[ $event_url ] = true;

			$event_data = $this->parseEventCard( $card_html, $event_url );
			if ( $event_data ) {
				$events[] = $event_data;
			}
		}

		return $events;
	}

	/**
	 * Parse a single event card's HTML content.
	 *
	 * @param string $card_html HTML content within the anchor tag
	 * @param string $event_url Full URL to the event page
	 * @return array|null Parsed event data or null if parsing failed
	 */
	private function parseEventCard( string $card_html, string $event_url ): ?array {
		$event = array(
			'title'          => '',
			'event_url'      => $event_url,
			'image_url'      => '',
			'date_time_text' => '',
		);

		// Extract title from heading (h3, h4, or similar)
		if ( preg_match( '/<h[2-6][^>]*>([^<]+)<\/h[2-6]>/i', $card_html, $title_match ) ) {
			$event['title'] = trim( html_entity_decode( $title_match[1], ENT_QUOTES, 'UTF-8' ) );
		}

		// Extract image URL
		if ( preg_match( '/<img[^>]+src="([^"]+)"/i', $card_html, $img_match ) ) {
			$event['image_url'] = $img_match[1];
		}

		// Extract date/time text - look for pattern like "Live Music January 16 7:00 pm - 9:00 pm"
		// Strip HTML and look for the date pattern
		$text_content = wp_strip_all_tags( $card_html );

		// Pattern: [Event Type] Month Day Time - Time
		if ( preg_match( '/([A-Za-z]+)\s+(\d{1,2})\s+(\d{1,2}:\d{2}\s*[ap]m)\s*[-–]\s*(\d{1,2}:\d{2}\s*[ap]m)/i', $text_content, $dt_match ) ) {
			$event['date_time_text'] = $dt_match[0];
			$event['month']          = $dt_match[1];
			$event['day']            = $dt_match[2];
			$event['start_time']     = $dt_match[3];
			$event['end_time']       = $dt_match[4];
		}

		// Fallback: try to extract year from URL pattern /event/slug-YYYY-MM-DD/
		if ( preg_match( '/\/event\/[a-z0-9-]+-(\d{4})-(\d{2})-(\d{2})\/?$/i', $event_url, $url_date ) ) {
			$event['url_year']  = $url_date[1];
			$event['url_month'] = $url_date[2];
			$event['url_day']   = $url_date[3];
		}

		// Must have either a title or be able to derive one
		if ( empty( $event['title'] ) && empty( $event['date_time_text'] ) ) {
			return null;
		}

		return $event;
	}

	/**
	 * Normalize raw event data to standard format.
	 *
	 * @param array $raw Raw event data from parseEventCard
	 * @param array $page_venue Venue data from PageVenueExtractor
	 * @return array Normalized event data
	 */
	private function normalizeEvent( array $raw, array $page_venue ): array {
		$event = array(
			'title'         => $this->sanitizeText( $raw['title'] ?? '' ),
			'description'   => '',
			'startDate'     => '',
			'endDate'       => '',
			'startTime'     => '',
			'endTime'       => '',
			'venue'         => $page_venue['venue'] ?? '',
			'venueAddress'  => $page_venue['venueAddress'] ?? '',
			'venueCity'     => $page_venue['venueCity'] ?? '',
			'venueState'    => $page_venue['venueState'] ?? '',
			'venueZip'      => $page_venue['venueZip'] ?? '',
			'venueCountry'  => $page_venue['venueCountry'] ?? 'US',
			'venueTimezone' => $page_venue['venueTimezone'] ?? '',
			'imageUrl'      => '',
			'ticketUrl'     => '',
			'source_url'    => $raw['event_url'] ?? '',
		);

		// Parse date from URL if available (most reliable - includes year)
		if ( ! empty( $raw['url_year'] ) && ! empty( $raw['url_month'] ) && ! empty( $raw['url_day'] ) ) {
			$event['startDate'] = $raw['url_year'] . '-' . $raw['url_month'] . '-' . $raw['url_day'];
		} elseif ( ! empty( $raw['month'] ) && ! empty( $raw['day'] ) ) {
			// Fallback: infer year from month/day
			$event['startDate'] = $this->inferDate( $raw['month'], $raw['day'] );
		}

		// Parse times
		if ( ! empty( $raw['start_time'] ) ) {
			$event['startTime'] = $this->parseTimeString( $raw['start_time'] );
		}
		if ( ! empty( $raw['end_time'] ) ) {
			$event['endTime'] = $this->parseTimeString( $raw['end_time'] );
		}

		// Image URL
		if ( ! empty( $raw['image_url'] ) ) {
			$event['imageUrl'] = esc_url_raw( $raw['image_url'] );
		}

		return $event;
	}

	/**
	 * Infer full date from month and day, adding year.
	 *
	 * If the date has already passed this year, assumes next year.
	 *
	 * @param string $month Month name (e.g., "January")
	 * @param string $day Day number
	 * @return string Date in Y-m-d format
	 */
	private function inferDate( string $month, string $day ): string {
		$year     = (int) date( 'Y' );
		$date_str = "$month $day $year";

		try {
			$dt    = new \DateTime( $date_str );
			$today = new \DateTime( 'today' );

			// If date is in the past, assume next year
			if ( $dt < $today ) {
				$dt->modify( '+1 year' );
			}

			return $dt->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

}
