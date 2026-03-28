<?php
/**
 * SpotHopper extractor.
 *
 * Extracts event data from SpotHopper platform websites by detecting the spot_id
 * and calling their public API.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpotHopperExtractor extends BaseExtractor {

	const API_BASE = 'https://www.spothopperapp.com/api/spots/';

	public function canExtract( string $html ): bool {
		return strpos( $html, 'spotapps.co' ) !== false
			|| strpos( $html, 'spothopperapp.com' ) !== false
			|| strpos( $html, 'spot_id' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$spot_id = $this->extractSpotId( $html, $source_url );

		if ( empty( $spot_id ) ) {
			return array();
		}

		$api_response = $this->fetchEvents( $spot_id );
		if ( empty( $api_response ) ) {
			return array();
		}

		$raw_events = $api_response['events'] ?? array();
		$linked     = $api_response['linked'] ?? array();

		if ( empty( $raw_events ) ) {
			return array();
		}

		$events = array();
		foreach ( $raw_events as $raw_event ) {
			$normalized = $this->normalizeEvent( $raw_event, $linked );
			if ( ! empty( $normalized['title'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'spothopper';
	}

	/**
	 * Extract spot_id from HTML or URL.
	 *
	 * @param string $html Page HTML
	 * @param string $source_url Source URL
	 * @return string|null Spot ID or null
	 */
	private function extractSpotId( string $html, string $source_url ): ?string {
		// 1. Check URL parameters
		if ( preg_match( '/spot_id=(\d+)/', $source_url, $matches ) ) {
			return $matches[1];
		}

		// 2. Check HTML for common patterns
		$patterns = array(
			'/var\s+spot_id\s*=\s*(\d+)/',      // var spot_id = 101982;
			'/spot_id=(\d+)/',                   // spot_id=101982 (in links/scripts)
			'/ab_websites\/(\d+)_website/',      // ab_websites/101982_website (in static assets)
			'/api\/spots\/(\d+)/',               // api/spots/101982
			'/data-spot-id=["\'](\d+)["\']/',    // data-spot-id="101982"
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $html, $matches ) ) {
				return $matches[1];
			}
		}

		return null;
	}

	/**
	 * Fetch events from SpotHopper API.
	 */
	private function fetchEvents( string $spot_id ): array {
		$url = self::API_BASE . urlencode( $spot_id ) . '/events';

		$result = HttpClient::get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'context' => 'SpotHopper Extractor',
			)
		);

		if ( ! $result['success'] || 200 !== $result['status_code'] ) {
			return array();
		}

		$data = json_decode( $result['data'], true );
		return ( json_last_error() === JSON_ERROR_NONE ) ? $data : array();
	}

	/**
	 * Normalize SpotHopper event to standardized format.
	 */
	private function normalizeEvent( array $event, array $linked ): array {
		$title       = $event['name'] ?? '';
		$description = $event['text'] ?? '';

		$start_date = '';
		$start_time = '';
		$end_time   = '';

		if ( ! empty( $event['event_date'] ) ) {
			$parsed     = $this->parseDatetime( $event['event_date'] );
			$start_date = $parsed['date'];
		}

		if ( ! empty( $event['start_time'] ) ) {
			$start_time = $event['start_time'];

			if ( ! empty( $event['duration_minutes'] ) && is_numeric( $event['duration_minutes'] ) && ! empty( $start_date ) ) {
				$parsed = $this->parseLocalDatetime( $start_date, $start_time, '' );
				if ( ! empty( $parsed['date'] ) ) {
					try {
						$start_datetime = new \DateTime( $start_date . ' ' . $start_time );
						$start_datetime->modify( '+' . (int) $event['duration_minutes'] . ' minutes' );
						$end_time = $start_datetime->format( 'H:i' );
					} catch ( \Exception $e ) {
					}
				}
			}
		}

		$venue_data = $this->extractVenueData( $linked );
		$image_url  = $this->resolveImageUrl( $event, $linked );

		return array_merge(
			array(
				'title'       => $this->sanitizeText( $title ),
				'description' => $this->cleanHtml( $description ),
				'startDate'   => $start_date,
				'endDate'     => '',
				'startTime'   => $start_time,
				'endTime'     => $end_time,
				'imageUrl'    => $image_url,
				'price'       => '',
				'ticketUrl'   => '',
			),
			$venue_data
		);
	}

	/**
	 * Extract venue data from linked spots.
	 */
	private function extractVenueData( array $linked ): array {
		$spots = $linked['spots'] ?? array();
		if ( empty( $spots[0] ) ) {
			return array();
		}

		$spot              = $spots[0];
		$venue_coordinates = '';
		if ( ! empty( $spot['latitude'] ) && ! empty( $spot['longitude'] ) ) {
			$venue_coordinates = $spot['latitude'] . ',' . $spot['longitude'];
		}

		return array(
			'venue'            => $this->sanitizeText( $spot['name'] ?? '' ),
			'venueAddress'     => $this->sanitizeText( $spot['address'] ?? '' ),
			'venueCity'        => $this->sanitizeText( $spot['city'] ?? '' ),
			'venueState'       => $this->sanitizeText( $spot['state'] ?? '' ),
			'venueZip'         => $this->sanitizeText( $spot['zip'] ?? '' ),
			'venueCountry'     => $this->sanitizeText( $spot['country'] ?? 'US' ),
			'venuePhone'       => $this->sanitizeText( $spot['phone_number'] ?? '' ),
			'venueWebsite'     => esc_url_raw( $spot['website_url'] ?? '' ),
			'venueCoordinates' => $venue_coordinates,
		);
	}

	/**
	 * Resolve image URL from linked images.
	 */
	private function resolveImageUrl( array $event, array $linked ): string {
		$image_ids = $event['links']['images'] ?? array();
		if ( empty( $image_ids ) ) {
			return '';
		}

		$linked_images = $linked['images'] ?? array();
		if ( empty( $linked_images ) ) {
			return '';
		}

		$target_id = $image_ids[0];

		foreach ( $linked_images as $image ) {
			if ( ( $image['id'] ?? null ) == $target_id ) {
				$url = $image['urls']['full'] ?? ( $image['urls']['large'] ?? ( $image['url'] ?? '' ) );
				return esc_url_raw( $url );
			}
		}

		return '';
	}
}
