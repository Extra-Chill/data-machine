<?php
/**
 * Dusk.fm / BeatGig extractor.
 *
 * Extracts event data from Dusk.fm venue calendar pages by parsing the
 * __NEXT_DATA__ JSON which contains MusicEvent JSON-LD strings.
 *
 * Handles two scenarios:
 * 1. Direct dusk.fm URL - parses __NEXT_DATA__ directly
 * 2. Embedded beatgig script - detects data-beatgig-embed, fetches dusk.fm calendar
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DuskFmExtractor extends BaseExtractor {

	const DUSK_EMBED_BASE = 'https://dusk.fm/embed/venue-calendar/';

	public function canExtract( string $html ): bool {
		if ( $this->hasBeatGigEmbed( $html ) ) {
			return true;
		}

		if ( strpos( $html, '__NEXT_DATA__' ) === false ) {
			return false;
		}

		return strpos( $html, 'dusk.fm' ) !== false
			|| strpos( $html, 'site_name" content="Dusk"' ) !== false
			|| strpos( $html, 'bookingsJsonLdString' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		if ( $this->hasBeatGigEmbed( $html ) ) {
			return $this->extractFromEmbed( $html );
		}

		return $this->extractFromNextData( $html );
	}

	public function getMethod(): string {
		return 'duskfm';
	}

	/**
	 * Check if HTML contains BeatGig embed script.
	 */
	private function hasBeatGigEmbed( string $html ): bool {
		return strpos( $html, 'data-beatgig-embed="venue-calendar"' ) !== false
			|| strpos( $html, "data-beatgig-embed='venue-calendar'" ) !== false;
	}

	/**
	 * Extract events by following BeatGig embed to dusk.fm.
	 */
	private function extractFromEmbed( string $html ): array {
		$venue_slug = $this->extractVenueSlug( $html );
		if ( empty( $venue_slug ) ) {
			return array();
		}

		$dusk_url = self::DUSK_EMBED_BASE . rawurlencode( $venue_slug );
		$result   = HttpClient::get(
			$dusk_url,
			array(
				'timeout' => 30,
				'context' => 'DuskFm Extractor',
			)
		);

		if ( ! $result['success'] || empty( $result['data'] ) ) {
			return array();
		}

		return $this->extractFromNextData( $result['data'] );
	}

	/**
	 * Extract venue slug from BeatGig embed script.
	 */
	private function extractVenueSlug( string $html ): string {
		if ( preg_match( '/data-beatgig-venue-slug=["\']([^"\']+)["\']/', $html, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Extract events from __NEXT_DATA__ JSON.
	 */
	private function extractFromNextData( string $html ): array {
		$next_data = $this->extractNextDataJson( $html );
		if ( empty( $next_data ) ) {
			return array();
		}

		$page_props = $next_data['props']['pageProps'] ?? array();
		$bookings   = $page_props['bookingsJsonLdString'] ?? array();

		if ( empty( $bookings ) ) {
			return array();
		}

		if ( is_string( $bookings ) ) {
			$bookings = json_decode( $bookings, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $bookings ) ) {
				return array();
			}
		}

		$venue_data = $this->extractVenueData( $page_props );

		$events = array();
		foreach ( $bookings as $booking ) {
			$booking_data = is_string( $booking ) ? json_decode( $booking, true ) : $booking;
			if ( ! is_array( $booking_data ) ) {
				continue;
			}

			$event = $this->parseBooking( $booking_data, $venue_data );
			if ( ! empty( $event['title'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Extract __NEXT_DATA__ JSON from HTML.
	 */
	private function extractNextDataJson( string $html ): array {
		if ( ! preg_match( '/<script\s+id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return array();
		}

		$data = json_decode( $matches[1], true );
		return ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) ? $data : array();
	}

	/**
	 * Extract venue data from pageProps.venueJsonLdString.
	 */
	private function extractVenueData( array $page_props ): array {
		$venue_json = $page_props['venueJsonLdString'] ?? '';
		if ( empty( $venue_json ) ) {
			return array();
		}

		$venue = json_decode( $venue_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $venue ) ) {
			return array();
		}

		$address  = $venue['address'] ?? array();
		$geo      = $venue['geo'] ?? array();
		$timezone = $page_props['icannTz'] ?? '';

		if ( empty( $timezone ) ) {
			$schedule_tz = $venue['eventSchedule']['scheduleTimezone'] ?? '';
			if ( $this->isValidTimezone( $schedule_tz ) ) {
				$timezone = $schedule_tz;
			}
		}

		$venue_data = array(
			'venue'        => $this->sanitizeText( $venue['name'] ?? '' ),
			'venueAddress' => $this->sanitizeText( $address['streetAddress'] ?? '' ),
			'venueCity'    => $this->sanitizeText( $address['addressLocality'] ?? '' ),
			'venueState'   => $this->sanitizeText( $address['addressRegion'] ?? '' ),
			'venueZip'     => $this->sanitizeText( $address['postalCode'] ?? '' ),
			'venueCountry' => $this->sanitizeText( $address['addressCountry'] ?? 'US' ),
			'timezone'     => $this->isValidTimezone( $timezone ) ? $timezone : '',
		);

		$lat = $venue['latitude'] ?? ( $geo['latitude'] ?? '' );
		$lng = $venue['longitude'] ?? ( $geo['longitude'] ?? '' );
		if ( ! empty( $lat ) && ! empty( $lng ) ) {
			$venue_data['venueCoordinates'] = $lat . ',' . $lng;
		}

		return $venue_data;
	}

	/**
	 * Parse a single booking from JSON-LD.
	 */
	private function parseBooking( array $event, array $venue_data ): array {
		$title       = $event['name'] ?? '';
		$description = $event['description'] ?? '';
		$ticket_url  = $event['url'] ?? '';

		$image_url = '';
		$performer = $event['performer'] ?? array();
		if ( ! empty( $performer ) ) {
			if ( isset( $performer['logo']['contentUrl'] ) ) {
				$image_url = $performer['logo']['contentUrl'];
			}
			$performer = $this->sanitizeText( $performer['name'] ?? '' );
		}

		$start_date = '';
		$start_time = '';
		$end_date   = '';
		$end_time   = '';

		$schedule = $event['eventSchedule'] ?? array();
		$timezone = $schedule['scheduleTimezone'] ?? ( $venue_data['timezone'] ?? '' );

		// Dusk.fm returns times with "Z" suffix claiming UTC, but they're actually
		// local times in scheduleTimezone. Strip the fake "Z" and parse as local.
		if ( ! empty( $event['startDate'] ) ) {
			$datetime_str = rtrim( $event['startDate'], 'Z' );
			$date_part    = substr( $datetime_str, 0, 10 );
			$time_part    = substr( $datetime_str, 11, 5 );
			$parsed       = $this->parseLocalDatetime( $date_part, $time_part, $timezone );
			$start_date   = $parsed['date'];
			$start_time   = $parsed['time'];
			if ( ! empty( $parsed['timezone'] ) ) {
				$timezone = $parsed['timezone'];
			}
		}

		if ( ! empty( $event['endDate'] ) ) {
			$datetime_str = rtrim( $event['endDate'], 'Z' );
			$date_part    = substr( $datetime_str, 0, 10 );
			$time_part    = substr( $datetime_str, 11, 5 );
			$parsed       = $this->parseLocalDatetime( $date_part, $time_part, $timezone );
			$end_date     = $parsed['date'];
			$end_time     = $parsed['time'];
		}

		$price  = '';
		$offers = $event['offers'] ?? array();
		if ( ! empty( $offers ) ) {
			$offer = is_array( $offers ) && isset( $offers[0] ) ? $offers[0] : $offers;
			if ( isset( $offer['price'] ) ) {
				$price = $this->formatPriceRange( (float) $offer['price'] );
			}
		}

		if ( empty( $price ) && ! empty( $event['isAccessibleForFree'] ) ) {
			$price = 'Free';
		}

		return array_merge(
			$venue_data,
			array(
				'title'       => $this->sanitizeText( $title ),
				'description' => $this->cleanHtml( $description ),
				'startDate'   => $start_date,
				'endDate'     => $end_date,
				'startTime'   => $start_time,
				'endTime'     => $end_time,
				'imageUrl'    => esc_url_raw( $image_url ),
				'price'       => $price,
				'ticketUrl'   => esc_url_raw( $ticket_url ),
				'performer'   => $performer,
				'timezone'    => $timezone,
			)
		);
	}
}
