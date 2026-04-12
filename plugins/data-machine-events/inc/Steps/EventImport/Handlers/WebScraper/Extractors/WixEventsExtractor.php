<?php
/**
 * Wix Events extractor.
 *
 * Extracts event data from Wix platform websites. Uses two strategies:
 *
 * 1. Warmup data — parse the embedded wix-warmup-data JSON (fast, no extra HTTP call).
 * 2. Wix Events API — query /_api/wix-events-web/v1/events with an instance token
 *    obtained from /_api/v1/access-tokens. Used when warmup data contains no events.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WixEventsExtractor extends BaseExtractor {

	/**
	 * Wix Events app definition ID prefix.
	 *
	 * The full ID varies per site (e.g. 140603ad-af8d-84a5-2c80-a0f60cb47351)
	 * but always starts with 140603ad.
	 */
	private const WIX_EVENTS_APP_PREFIX = '140603ad';

	/**
	 * Events to request per API call.
	 */
	private const API_PAGE_LIMIT = 100;

	/**
	 * Statuses that indicate a usable (non-canceled) event.
	 */
	private const ACTIVE_STATUSES = array( 'ACTIVE', 'SCHEDULED', 'OPEN' );

	public function canExtract( string $html ): bool {
		return strpos( $html, 'id="wix-warmup-data"' ) !== false
			|| strpos( $html, "id='wix-warmup-data'" ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		// Strategy 1: warmup data (fast, no extra HTTP call).
		$events = $this->extractFromWarmupData( $html );

		if ( ! empty( $events ) ) {
			return $events;
		}

		// Strategy 2: Wix Events API (handles sites that load events client-side).
		return $this->extractFromApi( $source_url );
	}

	public function getMethod(): string {
		return 'wix_events';
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Strategy 1: Warmup Data
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Extract events from embedded wix-warmup-data JSON.
	 *
	 * @param string $html Page HTML.
	 * @return array Normalized events.
	 */
	private function extractFromWarmupData( string $html ): array {
		if ( ! preg_match( '/<script[^>]+id=["\']wix-warmup-data["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return array();
		}

		$json_content = trim( $matches[1] );
		$data         = json_decode( $json_content, true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $data ) ) {
			return array();
		}

		$raw_events = $this->findEventsRecursive( $data );
		if ( empty( $raw_events ) ) {
			return array();
		}

		return $this->normalizeRawEvents( $raw_events );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Strategy 2: Wix Events API
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Fetch events via the Wix Events internal API.
	 *
	 * Flow:
	 *   1. GET /_api/v1/access-tokens → retrieve app instance tokens.
	 *   2. Find the Wix Events app instance (app ID starting with 140603ad).
	 *   3. GET /_api/wix-events-web/v1/events?offset=0&limit=N with the
	 *      instance as the Authorization header.
	 *   4. Paginate until all events are collected.
	 *
	 * @param string $source_url The venue's homepage URL.
	 * @return array Normalized events.
	 */
	private function extractFromApi( string $source_url ): array {
		$base_url = $this->getBaseUrl( $source_url );
		if ( empty( $base_url ) ) {
			return array();
		}

		$instance_token = $this->getInstanceToken( $base_url );
		if ( empty( $instance_token ) ) {
			return array();
		}

		$raw_events = $this->fetchAllEvents( $base_url, $instance_token );

		return $this->normalizeRawEvents( $raw_events );
	}

	/**
	 * Retrieve the Wix Events app instance token from the access-tokens endpoint.
	 *
	 * @param string $base_url Site base URL (e.g. https://www.theescondite.com).
	 * @return string Instance token, or empty string on failure.
	 */
	private function getInstanceToken( string $base_url ): string {
		$result = HttpClient::get(
			$base_url . '/_api/v1/access-tokens',
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'context' => 'Wix Extractor — access tokens',
			)
		);

		if ( empty( $result['success'] ) || empty( $result['data'] ) ) {
			return '';
		}

		$data = json_decode( $result['data'], true );
		if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['apps'] ) ) {
			return '';
		}

		foreach ( $data['apps'] as $app_id => $app_data ) {
			if ( str_starts_with( $app_id, self::WIX_EVENTS_APP_PREFIX ) && ! empty( $app_data['instance'] ) ) {
				return $app_data['instance'];
			}
		}

		return '';
	}

	/**
	 * Fetch all events from the Wix Events API with pagination.
	 *
	 * @param string $base_url       Site base URL.
	 * @param string $instance_token Wix Events app instance token.
	 * @return array Raw event objects.
	 */
	private function fetchAllEvents( string $base_url, string $instance_token ): array {
		$all_events = array();
		$offset     = 0;

		while ( true ) {
			$response = $this->fetchEventsPage( $base_url, $instance_token, $offset );
			if ( empty( $response ) ) {
				break;
			}

			$page_events = $response['events'] ?? array();
			if ( ! empty( $page_events ) ) {
				$all_events = array_merge( $all_events, $page_events );
			}

			$total = (int) ( $response['total'] ?? 0 );
			if ( $total <= 0 || ( $offset + self::API_PAGE_LIMIT ) >= $total ) {
				break;
			}

			$offset += self::API_PAGE_LIMIT;
		}

		return $all_events;
	}

	/**
	 * Fetch a single page of events from the Wix Events API.
	 *
	 * @param string $base_url       Site base URL.
	 * @param string $instance_token Wix Events app instance token.
	 * @param int    $offset         Pagination offset.
	 * @return array|null Decoded API response, or null on failure.
	 */
	private function fetchEventsPage( string $base_url, string $instance_token, int $offset ): ?array {
		$url = $base_url . '/_api/wix-events-web/v1/events?offset=' . $offset . '&limit=' . self::API_PAGE_LIMIT;

		$result = HttpClient::get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => $instance_token,
				),
				'context' => 'Wix Extractor — events API',
			)
		);

		if ( empty( $result['success'] ) || ( $result['status_code'] ?? 0 ) !== 200 ) {
			return null;
		}

		$data = json_decode( $result['data'] ?? '', true );
		return ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) ? $data : null;
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Shared normalization (used by both strategies)
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Normalize an array of raw Wix event objects, filtering out canceled events.
	 *
	 * @param array $raw_events Array of raw event objects from warmup data or API.
	 * @return array Normalized event data.
	 */
	private function normalizeRawEvents( array $raw_events ): array {
		$events = array();

		foreach ( $raw_events as $raw_event ) {
			// Skip canceled events.
			$status = $raw_event['status'] ?? '';
			if ( 'CANCELED' === $status ) {
				continue;
			}

			$normalized = $this->normalizeEvent( $raw_event );
			if ( ! empty( $normalized['title'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Warmup data helpers
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Recursively search for Wix events array in JSON structure.
	 *
	 * @param array $data JSON data structure.
	 * @return array Events array or empty array.
	 */
	private function findEventsRecursive( array $data ): array {
		if ( isset( $data['events'] ) && isset( $data['events']['events'] ) && is_array( $data['events']['events'] ) ) {
			return $data['events']['events'];
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$result = $this->findEventsRecursive( $value );
				if ( ! empty( $result ) ) {
					return $result;
				}
			}
		}

		return array();
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Event normalization
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Normalize Wix event to standardized format.
	 *
	 * @param array $wix_event Raw Wix event object.
	 * @return array Standardized event data.
	 */
	private function normalizeEvent( array $wix_event ): array {
		$event = array(
			'title'       => $this->sanitizeText( $wix_event['title'] ?? '' ),
			'description' => $this->cleanHtml( $wix_event['description'] ?? $wix_event['about'] ?? '' ),
		);

		$this->parseScheduling( $event, $wix_event );
		$this->parseLocation( $event, $wix_event );
		$this->parseTicketing( $event, $wix_event );
		$this->parseImage( $event, $wix_event );

		return $event;
	}

	/**
	 * Parse scheduling data from Wix event.
	 *
	 * @param array $event     Event array to update (by reference).
	 * @param array $wix_event Raw Wix event.
	 */
	private function parseScheduling( array &$event, array $wix_event ): void {
		$scheduling  = $wix_event['scheduling']['config'] ?? array();
		$timezone_id = $scheduling['timeZoneId'] ?? '';

		if ( ! empty( $scheduling['startDate'] ) ) {
			$start_parsed       = $this->parseUtcDatetime( $scheduling['startDate'], $timezone_id );
			$event['startDate'] = $start_parsed['date'];
			$event['startTime'] = $start_parsed['time'];
		}

		if ( ! empty( $scheduling['endDate'] ) ) {
			$end_parsed       = $this->parseUtcDatetime( $scheduling['endDate'], $timezone_id );
			$event['endDate'] = $end_parsed['date'];
			$event['endTime'] = $end_parsed['time'];
		}

		if ( ! empty( $timezone_id ) && $this->isValidTimezone( $timezone_id ) ) {
			$event['venueTimezone'] = $timezone_id;
		}
	}

	/**
	 * Parse location data from Wix event.
	 *
	 * @param array $event     Event array to update (by reference).
	 * @param array $wix_event Raw Wix event.
	 */
	private function parseLocation( array &$event, array $wix_event ): void {
		$location = $wix_event['location'] ?? array();
		if ( empty( $location ) ) {
			return;
		}

		$event['venue']        = $this->sanitizeText( $location['name'] ?? '' );
		$event['venueAddress'] = $this->sanitizeText( $location['address'] ?? '' );

		$full_address = $location['fullAddress'] ?? array();
		if ( ! empty( $full_address ) ) {
			$event['venueCity']    = $this->sanitizeText( $full_address['city'] ?? '' );
			$event['venueState']   = $this->sanitizeText( $full_address['subdivision'] ?? '' );
			$event['venueZip']     = $this->sanitizeText( $full_address['postalCode'] ?? '' );
			$event['venueCountry'] = $this->sanitizeText( $full_address['country'] ?? '' );

			$street = $full_address['streetAddress'] ?? array();
			if ( ! empty( $street ) && is_array( $street ) ) {
				$street_parts = array_filter(
					array(
						$street['number'] ?? '',
						$street['name'] ?? '',
					)
				);
				if ( ! empty( $street_parts ) ) {
					$event['venueAddress'] = $this->sanitizeText( implode( ' ', $street_parts ) );
				}
			}
		}

		$coords = $location['coordinates'] ?? array();
		if ( ! empty( $coords['lat'] ) && ! empty( $coords['lng'] ) ) {
			$event['venueCoordinates'] = $coords['lat'] . ',' . $coords['lng'];
		}
	}

	/**
	 * Parse ticketing data from Wix event.
	 *
	 * @param array $event     Event array to update (by reference).
	 * @param array $wix_event Raw Wix event.
	 */
	private function parseTicketing( array &$event, array $wix_event ): void {
		$registration = $wix_event['registration'] ?? array();
		$external_url = $registration['external']['registration'] ?? '';
		if ( ! empty( $external_url ) ) {
			$event['ticketUrl'] = esc_url_raw( $external_url );
		}
	}

	/**
	 * Parse image data from Wix event.
	 *
	 * @param array $event     Event array to update (by reference).
	 * @param array $wix_event Raw Wix event.
	 */
	private function parseImage( array &$event, array $wix_event ): void {
		$main_image = $wix_event['mainImage'] ?? array();
		if ( ! empty( $main_image['url'] ) ) {
			$event['imageUrl'] = esc_url_raw( $main_image['url'] );
		}
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Utility
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Extract scheme + host from a URL.
	 *
	 * @param string $url Full URL.
	 * @return string Base URL (e.g. https://www.example.com), or empty string.
	 */
	private function getBaseUrl( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
	}
}
