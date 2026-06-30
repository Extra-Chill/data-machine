<?php
/**
 * Seated tour-widget extractor.
 *
 * Seated (https://seated.com) is a tour-date widget embedded by many
 * touring artists on their official websites. The widget renders entirely
 * client-side: the page ships an empty `<div id="seated-..." data-artist-id="UUID">`
 * placeholder plus `widget.seated.com/app.js`, then the script fetches the
 * artist's tour dates from Seated's CDN API and injects them into the DOM.
 *
 * Because the events are loaded client-side, none of the structured-data or
 * HTML-section extractors can see them — the server-fetched HTML contains no
 * event markup. This caused a real artist tour page (easyhoneymusic.com/tour/)
 * to fail the artist-URL tour-import flow with "couldn't extract events"
 * (extrachill-events#403), even though the page works fine in a browser.
 *
 * This extractor reproduces the widget's own API call:
 *
 *   GET https://cdn.seated.com/api/tour/{artistId}?include=tour-events
 *       header: X-Client-Version: HEAD
 *
 * The response is JSON:API — `data` is the tour (artist name + image) and
 * `included[]` holds the `tour-events` records with venue name, formatted
 * "City, ST" address, UTC start time, and an event id used to build the
 * Seated ticket/RSVP link.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeatedExtractor extends BaseExtractor {

	/**
	 * Seated CDN API host (the widget's `apiHost`).
	 */
	private const API_HOST = 'https://cdn.seated.com';

	/**
	 * Host that serves the per-event RSVP/ticket landing page (the widget's
	 * `buttonLinkHost`). Combined with the event id to produce ticketUrl.
	 */
	private const LINK_HOST = 'https://link.seated.com';

	/**
	 * Client version header the widget sends. A literal "HEAD" mirrors the
	 * published widget; the API accepts it.
	 */
	private const CLIENT_VERSION = 'HEAD';

	public function canExtract( string $html ): bool {
		if ( '' === $html ) {
			return false;
		}

		// The placeholder div carries the artist id, and the loader script is
		// always present. Either fingerprint is sufficient; the extract step
		// re-confirms it can find a usable artist id before any HTTP call.
		return false !== stripos( $html, 'widget.seated.com' )
			|| (bool) preg_match( '/id=["\']seated-[0-9a-f]+["\']/i', $html );
	}

	public function extract( string $html, string $source_url ): array {
		$artist_id = $this->findArtistId( $html );
		if ( '' === $artist_id ) {
			return array();
		}

		$tour = $this->fetchTour( $artist_id );
		if ( empty( $tour ) ) {
			return array();
		}

		return $this->normalizeTour( $tour );
	}

	public function getMethod(): string {
		return 'seated';
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Detection helpers
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Extract the Seated artist id (a UUID) from the embedded widget markup.
	 *
	 * The widget div looks like:
	 *   <div id="seated-55fdf2c0" data-artist-id="a4121ec3-4318-4372-9889-098c7cdf5f41">
	 *
	 * @param string $html Page HTML.
	 * @return string Artist UUID, or empty string if not found.
	 */
	private function findArtistId( string $html ): string {
		if ( preg_match( '/data-artist-id=["\']([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})["\']/i', $html, $m ) ) {
			return strtolower( $m[1] );
		}

		return '';
	}

	// ────────────────────────────────────────────────────────────────────────────
	// API
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Fetch the tour payload from the Seated CDN API.
	 *
	 * @param string $artist_id Seated artist UUID.
	 * @return array Decoded JSON:API response, or empty array on failure.
	 */
	private function fetchTour( string $artist_id ): array {
		$url = self::API_HOST . '/api/tour/' . rawurlencode( $artist_id ) . '?include=tour-events';

		// NOTE: do NOT send `Accept: application/json` — the Seated CDN returns
		// HTTP 406 for that. It only answers to a permissive Accept (the
		// HttpClient default), plus the widget's X-Client-Version header.
		$result = HttpClient::get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'X-Client-Version' => self::CLIENT_VERSION,
				),
				'context' => 'Seated Extractor — tour API',
			)
		);

		if ( empty( $result['success'] ) || ( $result['status_code'] ?? 0 ) !== 200 ) {
			return array();
		}

		$data = json_decode( $result['data'] ?? '', true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) || empty( $data['data'] ) ) {
			return array();
		}

		return $data;
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Normalization
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Normalize a Seated tour payload into standardized event records.
	 *
	 * The artist name (tour `name`) becomes the event title, since Seated tour
	 * events have no per-show title of their own — the artist IS the headliner.
	 *
	 * @param array $tour Decoded JSON:API tour payload.
	 * @return array Normalized events.
	 */
	private function normalizeTour( array $tour ): array {
		$artist_name = $this->sanitizeText( (string) ( $tour['data']['attributes']['name'] ?? '' ) );

		$included = $tour['included'] ?? array();
		if ( empty( $included ) || ! is_array( $included ) ) {
			return array();
		}

		$events = array();
		foreach ( $included as $record ) {
			if ( ! is_array( $record ) || ( $record['type'] ?? '' ) !== 'tour-events' ) {
				continue;
			}

			$normalized = $this->normalizeTourEvent( $record, $artist_name );
			if ( ! empty( $normalized['title'] ) && ! empty( $normalized['startDate'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	/**
	 * Normalize a single Seated tour-event record.
	 *
	 * @param array  $record      JSON:API tour-event record.
	 * @param string $artist_name Artist/tour name (used as the event title).
	 * @return array Standardized event data.
	 */
	private function normalizeTourEvent( array $record, string $artist_name ): array {
		$attr = $record['attributes'] ?? array();

		$event = array(
			'title' => $artist_name,
		);

		// Scheduling. Seated exposes a UTC `starts-at` instant plus a
		// pre-localized calendar date; we key off the local date (see below).
		$starts_at      = (string) ( $attr['starts-at'] ?? '' );
		$starts_at_date = (string) ( $attr['starts-at-date-local'] ?? '' );

		// Seated returns `starts-at` as a UTC instant ("...Z") and separately a
		// pre-localized calendar date (`starts-at-date-local`). We trust the
		// local date — it is already correct for the venue's region and avoids
		// drifting a late-evening show onto the next UTC day. We deliberately do
		// NOT emit a startTime or venueTimezone: the only time Seated gives is
		// in UTC with no venue zone, so labeling "23:00" as a local show time
		// would be wrong. The downstream AI/upsert step localizes timing from
		// the venue/city we provide.
		$local_date = ( '' !== $starts_at_date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $starts_at_date ) )
			? $starts_at_date
			: '';

		if ( '' !== $local_date ) {
			$event['startDate'] = $local_date;
		} elseif ( '' !== $starts_at ) {
			// No usable local date — fall back to the UTC date component only.
			$parsed             = $this->parseIsoDatetime( $starts_at );
			$event['startDate'] = $parsed['date'];
		}

		$event['startTime'] = '';

		// Venue + location. Seated gives a venue name and a "City, ST"
		// formatted address; split the latter into city/state when possible.
		$event['venue'] = $this->sanitizeText( (string) ( $attr['venue-name'] ?? '' ) );

		$formatted_address = trim( (string) ( $attr['formatted-address'] ?? '' ) );
		if ( '' !== $formatted_address ) {
			$event['venueAddress'] = $this->sanitizeText( $formatted_address );
			$city_state            = $this->splitCityState( $formatted_address );
			if ( '' !== $city_state['city'] ) {
				$event['venueCity'] = $city_state['city'];
			}
			if ( '' !== $city_state['state'] ) {
				$event['venueState'] = $city_state['state'];
			}
		}

		// Ticket / RSVP link is built from the event id, mirroring the widget.
		$event_id = (string) ( $record['id'] ?? '' );
		if ( '' !== $event_id ) {
			$event['ticketUrl'] = esc_url_raw( self::LINK_HOST . '/' . rawurlencode( $event_id ) );
		}

		return $event;
	}

	/**
	 * Split a "City, ST" / "City, State, Country" formatted address into
	 * city and state parts. Best-effort — returns empty strings when the
	 * shape is unexpected.
	 *
	 * @param string $formatted Address like "Philadelphia, PA".
	 * @return array{city:string,state:string}
	 */
	private function splitCityState( string $formatted ): array {
		$parts = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $formatted ) ),
				static fn ( string $p ): bool => '' !== $p
			)
		);

		$city  = $parts[0] ?? '';
		$state = $parts[1] ?? '';

		return array(
			'city'  => $this->sanitizeText( $city ),
			'state' => $this->sanitizeText( $state ),
		);
	}
}
