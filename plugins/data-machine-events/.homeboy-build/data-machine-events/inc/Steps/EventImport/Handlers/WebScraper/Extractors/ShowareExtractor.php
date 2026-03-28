<?php
/**
 * Showare/accesso extractor.
 *
 * Extracts event data from Showare (now accesso) ticketing platform websites.
 * These are ASP Classic ticketing systems where all event data is loaded via
 * JavaScript. The page contains no event data in static HTML.
 *
 * Extraction approach:
 * 1. Detect Showare platform via `swApi.js` or `apiproxy.asp` in the HTML.
 * 2. Fetch the page to establish session cookies (the API proxy requires them).
 * 3. Call `/admin/json/apiproxy.asp?forwardingURL=/v1/performances&method=GET`
 *    with session cookies to get JSON event data.
 * 4. Parse performances, venues, and pricing codes from the response.
 *
 * The API returns structured data including:
 * - performances: id, name, startDate, endDate, status, image, venue ID
 * - venues: id, name, address, city, state, zip, country, timezone
 * - pricingCodes: per-performance pricing with ticket prices
 *
 * Detection: `swApi.js` or `apiproxy.asp` or `Showare` in the HTML.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShowareExtractor extends BaseExtractor {

	/**
	 * API proxy endpoint path.
	 */
	private const API_PROXY_PATH = '/admin/json/apiproxy.asp';

	/**
	 * Performances API forwarding URL.
	 */
	private const PERFORMANCES_ENDPOINT = '/v1/performances';

	public function canExtract( string $html ): bool {
		return strpos( $html, 'swApi.js' ) !== false
			|| strpos( $html, 'apiproxy.asp' ) !== false
			|| ( strpos( $html, 'Showare' ) !== false && strpos( $html, 'swDatepicker' ) !== false );
	}

	public function extract( string $html, string $source_url ): array {
		$base_url = $this->getBaseUrl( $source_url );

		if ( empty( $base_url ) ) {
			return array();
		}

		// Step 1: Fetch the page to get session cookies.
		$session_cookies = $this->getSessionCookies( $base_url );

		if ( empty( $session_cookies ) ) {
			return array();
		}

		// Step 2: Call the API proxy with session cookies.
		$api_data = $this->fetchPerformances( $base_url, $session_cookies );

		if ( empty( $api_data ) ) {
			return array();
		}

		$performances = $api_data['performances'] ?? array();
		$venues       = $api_data['venues'] ?? array();
		$pricing      = $api_data['pricingCodes'] ?? array();

		if ( empty( $performances ) ) {
			return array();
		}

		$events = array();

		foreach ( $performances as $perf ) {
			$event = $this->normalizePerformance( $perf, $venues, $pricing, $base_url );
			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'showare';
	}

	/**
	 * Extract the base URL (scheme + host) from the source URL.
	 *
	 * @param string $source_url Source URL.
	 * @return string Base URL or empty string.
	 */
	private function getBaseUrl( string $source_url ): string {
		$parts = wp_parse_url( $source_url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = $parts['scheme'] ?? 'https';
		return $scheme . '://' . $parts['host'];
	}

	/**
	 * Fetch the main page to establish session cookies.
	 *
	 * Showare's API proxy requires session cookies from the initial page load.
	 * We make a GET request and capture the response cookies via wp_remote_retrieve_cookies().
	 *
	 * @param string $base_url Base URL of the Showare site.
	 * @return array WP_Http_Cookie objects for use in subsequent requests.
	 */
	private function getSessionCookies( string $base_url ): array {
		if ( ! class_exists( '\\DataMachine\\Core\\HttpClient' ) ) {
			return array();
		}

		$result = \DataMachine\Core\HttpClient::get(
			$base_url . '/',
			array(
				'timeout' => 15,
				'context' => 'Showare session',
			)
		);

		if ( empty( $result['success'] ) || empty( $result['response'] ) ) {
			return array();
		}

		// Extract cookies from the raw WP HTTP response.
		return wp_remote_retrieve_cookies( $result['response'] );
	}

	/**
	 * Fetch performances from the Showare API proxy.
	 *
	 * @param string $base_url        Base URL.
	 * @param array  $session_cookies WP_Http_Cookie objects from initial page load.
	 * @return array Parsed API response data or empty array.
	 */
	private function fetchPerformances( string $base_url, array $session_cookies ): array {
		if ( ! class_exists( '\\DataMachine\\Core\\HttpClient' ) ) {
			return array();
		}

		$api_url = $base_url . self::API_PROXY_PATH
			. '?forwardingURL=' . rawurlencode( self::PERFORMANCES_ENDPOINT )
			. '&method=GET';

		$result = \DataMachine\Core\HttpClient::get(
			$api_url,
			array(
				'timeout' => 30,
				'cookies' => $session_cookies,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'context' => 'Showare performances API',
			)
		);

		if ( empty( $result['success'] ) ) {
			return array();
		}

		$body = $result['data'] ?? $result['body'] ?? '';
		if ( empty( $body ) ) {
			return array();
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array();
		}

		// The response wraps everything under a "data" key.
		return $data['data'] ?? $data;
	}

	/**
	 * Normalize a single performance into the standard event format.
	 *
	 * @param array  $perf     Performance data from the API.
	 * @param array  $venues   Venue lookup keyed by venue ID.
	 * @param array  $pricing  Pricing codes keyed by performance code.
	 * @param string $base_url Base URL for building ticket/image URLs.
	 * @return array Normalized event data.
	 */
	private function normalizePerformance( array $perf, array $venues, array $pricing, string $base_url ): array {
		$name = $perf['name'] ?? '';

		// Parse start/end dates — Showare uses ISO 8601 with offset.
		// e.g. "2026-07-10T20:00:00.0000000-05:00"
		$start_parsed = $this->parseShowareDatetime( $perf['startDate'] ?? '' );
		$end_parsed   = $this->parseShowareDatetime( $perf['endDate'] ?? '' );

		// Build ticket URL from performance code.
		$perf_code  = $perf['performanceCode'] ?? '';
		$ticket_url = ! empty( $perf_code ) ? $base_url . '/eventperformances.asp?evt=' . rawurlencode( (string) ( $perf['eventId'] ?? '' ) ) : '';

		// Build image URL.
		$image_url = '';
		$image     = $perf['image'] ?? '';
		if ( ! empty( $image ) ) {
			$image_url = $base_url . '/eventimages/' . $image;
		}

		// Extract price from pricing codes.
		$price = $this->extractPrice( $perf_code, $pricing );

		// Resolve venue data.
		$venue_id   = (string) ( $perf['venue'] ?? '' );
		$venue_data = $venues[ $venue_id ] ?? array();

		// Map Showare timezone abbreviations to IANA.
		$timezone = $this->mapTimezone( $venue_data['timeZone'] ?? '' );

		return array(
			'title'         => $this->sanitizeText( $name ),
			'startDate'     => $start_parsed['date'],
			'startTime'     => $start_parsed['time'],
			'endDate'       => $end_parsed['date'],
			'endTime'       => $end_parsed['time'],
			'ticketUrl'     => $ticket_url,
			'imageUrl'      => $image_url,
			'price'         => $price,
			'venue'         => $this->sanitizeText( $venue_data['name'] ?? '' ),
			'venueAddress'  => $this->sanitizeText( $venue_data['address1'] ?? '' ),
			'venueCity'     => $this->sanitizeText( $venue_data['city'] ?? '' ),
			'venueState'    => $this->sanitizeText( $venue_data['state'] ?? '' ),
			'venueZip'      => $this->sanitizeText( $venue_data['zip'] ?? '' ),
			'venueCountry'  => $this->sanitizeText( $venue_data['country'] ?? '' ),
			'venueTimezone' => $timezone,
		);
	}

	/**
	 * Parse a Showare datetime string.
	 *
	 * Showare returns dates like "2026-07-10T20:00:00.0000000-05:00".
	 * The fractional seconds (7 digits) are non-standard and need stripping.
	 *
	 * @param string $datetime Showare datetime string.
	 * @return array{date: string, time: string}
	 */
	private function parseShowareDatetime( string $datetime ): array {
		if ( empty( $datetime ) ) {
			return array(
				'date' => '',
				'time' => '',
			);
		}

		// Strip the non-standard 7-digit fractional seconds.
		// "2026-07-10T20:00:00.0000000-05:00" → "2026-07-10T20:00:00-05:00"
		$cleaned = preg_replace( '/\.\d{1,7}/', '', $datetime );

		$parsed = $this->parseIsoDatetime( $cleaned );

		return array(
			'date' => $parsed['date'] ?? '',
			'time' => $parsed['time'] ?? '',
		);
	}

	/**
	 * Extract the lowest ticket price for a performance.
	 *
	 * @param string $perf_code  Performance code.
	 * @param array  $pricing    Pricing codes array.
	 * @return string Formatted price or empty string.
	 */
	private function extractPrice( string $perf_code, array $pricing ): string {
		if ( empty( $perf_code ) || empty( $pricing[ $perf_code ] ) ) {
			return '';
		}

		$perf_pricing = $pricing[ $perf_code ];
		$min_price    = null;
		$max_price    = null;

		foreach ( $perf_pricing as $seat_map_pricing ) {
			if ( ! is_array( $seat_map_pricing ) ) {
				continue;
			}

			foreach ( $seat_map_pricing as $code_data ) {
				if ( ! is_array( $code_data ) || ! isset( $code_data['price'] ) ) {
					continue;
				}

				$price = (float) $code_data['price'];
				if ( $price <= 0 ) {
					continue;
				}

				if ( null === $min_price || $price < $min_price ) {
					$min_price = $price;
				}
				if ( null === $max_price || $price > $max_price ) {
					$max_price = $price;
				}
			}
		}

		if ( null === $min_price ) {
			return '';
		}

		return $this->formatPriceRange( $min_price, $max_price );
	}

	/**
	 * Map common timezone abbreviations to IANA identifiers.
	 *
	 * Showare returns abbreviations like "CST", "EST", "PST".
	 *
	 * @param string $abbrev Timezone abbreviation.
	 * @return string IANA timezone or empty string.
	 */
	private function mapTimezone( string $abbrev ): string {
		$map = array(
			'EST'  => 'America/New_York',
			'EDT'  => 'America/New_York',
			'CST'  => 'America/Chicago',
			'CDT'  => 'America/Chicago',
			'MST'  => 'America/Denver',
			'MDT'  => 'America/Denver',
			'PST'  => 'America/Los_Angeles',
			'PDT'  => 'America/Los_Angeles',
			'AKST' => 'America/Anchorage',
			'AKDT' => 'America/Anchorage',
			'HST'  => 'Pacific/Honolulu',
		);

		$key = strtoupper( trim( $abbrev ) );
		return $map[ $key ] ?? '';
	}
}
