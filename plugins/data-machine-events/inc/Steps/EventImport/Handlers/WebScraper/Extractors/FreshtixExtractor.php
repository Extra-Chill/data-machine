<?php
/**
 * Freshtix extractor.
 *
 * Extracts event data from Freshtix ticketing platform pages by parsing
 * the embedded JavaScript events object and Organization JSON-LD for venue data.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FreshtixExtractor extends BaseExtractor {

	public function canExtract( string $html ): bool {
		if ( strpos( $html, 'freshtix.com' ) === false ) {
			return false;
		}

		return preg_match( '/events\s*=\s*\{["\']?\d{4}-\d{2}-\d{2}/', $html ) === 1;
	}

	public function extract( string $html, string $source_url ): array {
		$events_data = $this->extractEventsObject( $html );
		if ( empty( $events_data ) ) {
			return array();
		}

		$venue_data = $this->extractVenueFromJsonLd( $html );
		$base_url   = $this->getBaseUrl( $source_url );

		$events = array();
		foreach ( $events_data as $date => $date_events ) {
			foreach ( $date_events as $raw_event ) {
				$normalized = $this->normalizeEvent( $raw_event, $venue_data, $base_url );
				if ( ! empty( $normalized['title'] ) ) {
					$events[] = $normalized;
				}
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'freshtix';
	}

	private function extractEventsObject( string $html ): ?array {
		if ( ! preg_match( '/events\s*=\s*(\{.+?\});/s', $html, $matches ) ) {
			return null;
		}

		$json = $matches[1];
		$json = preg_replace( '/\\\\u([0-9a-fA-F]{4})/', '\\\\u$1', $json );

		$data = json_decode( $json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	private function extractVenueFromJsonLd( string $html ): array {
		$venue = array();

		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return $venue;
		}

		foreach ( $matches[1] as $json_content ) {
			$data = json_decode( trim( $json_content ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || empty( $data ) ) {
				continue;
			}

			if ( isset( $data['@type'] ) && 'Organization' === $data['@type'] ) {
				if ( ! empty( $data['name'] ) ) {
					$venue['name'] = sanitize_text_field( $data['name'] );
				}

				if ( ! empty( $data['address'] ) ) {
					$address = $data['address'];
					if ( ! empty( $address['streetAddress'] ) ) {
						$venue['address'] = sanitize_text_field( $address['streetAddress'] );
					}
					if ( ! empty( $address['addressLocality'] ) ) {
						$venue['city'] = sanitize_text_field( trim( $address['addressLocality'] ) );
					}
					if ( ! empty( $address['addressRegion'] ) ) {
						$venue['state'] = sanitize_text_field( $address['addressRegion'] );
					}
					if ( ! empty( $address['postalCode'] ) ) {
						$venue['zip'] = sanitize_text_field( $address['postalCode'] );
					}
				}

				break;
			}
		}

		return $venue;
	}

	private function getBaseUrl( string $url ): string {
		$parsed = parse_url( $url );
		$scheme = $parsed['scheme'] ?? 'https';
		$host   = $parsed['host'] ?? '';

		return $scheme . '://' . $host;
	}

	private function normalizeEvent( array $raw, array $venue_data, string $base_url ): array {
		$event = array(
			'title'       => $this->sanitizeText( $raw['name'] ?? '' ),
			'description' => '',
		);

		$this->parseEventDateTime( $event, $raw );
		$this->parseVenue( $event, $raw, $venue_data );
		$this->parseTicketUrl( $event, $raw );
		$this->parseImage( $event, $raw, $base_url );

		return $event;
	}

	private function parseEventDateTime( array &$event, array $raw ): void {
		if ( ! empty( $raw['start_datetime'] ) ) {
			$parsed = $this->parseDatetime( $raw['start_datetime'] );
			if ( ! empty( $parsed['date'] ) ) {
				$event['startDate'] = $parsed['date'];
				$event['startTime'] = $parsed['time'];
				return;
			}
		}

		if ( ! empty( $raw['start_date'] ) ) {
			$event['startDate'] = $this->sanitizeText( $raw['start_date'] );
		}

		if ( ! empty( $raw['start_time'] ) ) {
			$event['startTime'] = $this->normalizeTime( $raw['start_time'] );
		}
	}

	private function normalizeTime( string $time ): string {
		$time = strtolower( trim( $time ) );

		if ( strpos( $time, ':' ) === false ) {
			$time = preg_replace( '/(\d+)\s*(am|pm)/i', '$1:00 $2', $time );
		}

		$timestamp = strtotime( $time );
		if ( false !== $timestamp ) {
			return date( 'H:i', $timestamp );
		}

		return '';
	}

	private function parseVenue( array &$event, array $raw, array $venue_data ): void {
		$event['venue'] = $venue_data['name'] ?? $this->sanitizeText( $raw['venue_name'] ?? '' );

		if ( ! empty( $venue_data['address'] ) ) {
			$event['venueAddress'] = $venue_data['address'];
		}
		if ( ! empty( $venue_data['city'] ) ) {
			$event['venueCity'] = $venue_data['city'];
		}
		if ( ! empty( $venue_data['state'] ) ) {
			$event['venueState'] = $venue_data['state'];
		}
		if ( ! empty( $venue_data['zip'] ) ) {
			$event['venueZip'] = $venue_data['zip'];
		}
	}

	private function parseTicketUrl( array &$event, array $raw ): void {
		if ( ! empty( $raw['event_url'] ) ) {
			$url                = $raw['event_url'];
			$url                = strtok( $url, '?' );
			$event['ticketUrl'] = esc_url_raw( $url );
		}
	}

	private function parseImage( array &$event, array $raw, string $base_url ): void {
		if ( empty( $raw['image_url'] ) ) {
			return;
		}

		$image_url = $raw['image_url'];

		if ( strpos( $image_url, 'http' ) !== 0 ) {
			$image_url = $base_url . '/' . ltrim( $image_url, '/' );
		}

		$event['imageUrl'] = esc_url_raw( $image_url );
	}
}
