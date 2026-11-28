<?php
/**
 * Charles Towne Fermentory extractor.
 *
 * chsfermentory.com migrated from Wix to a custom React SPA that ships an
 * empty `<div id="root">` shell — static HTML contains no event markup, so
 * every generic extractor sees zero events. The site exposes its calendar
 * as clean JSON at /api/events/upcoming (titles, descriptions, UTC start/end
 * instants, location names, vendor details).
 *
 * The site renders all dates in America/New_York (hardcoded in its bundle),
 * so UTC instants are converted to that timezone.
 *
 * Regression coverage for data-machine-events#941 (Charles Towne Fermentory,
 * flow 20 — daily runs returned completed_no_items while the calendar had
 * upcoming events).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.68.1
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChsFermentoryExtractor extends BaseExtractor {

	private const SITE_HOST = 'chsfermentory.com';

	private const VENUE_NAME = 'Charles Towne Fermentory';

	private const TIMEZONE = 'America/New_York';

	/**
	 * Check if this is the Charles Towne Fermentory SPA shell.
	 *
	 * @param string $html HTML content to check.
	 * @return bool True when the chsfermentory.com shell is detected.
	 */
	public function canExtract( string $html ): bool {
		return strpos( $html, self::SITE_HOST ) !== false
			&& strpos( $html, 'data-managed-canonical' ) !== false;
	}

	/**
	 * Extract events from the site's upcoming-events JSON API.
	 *
	 * @param string $html       Page HTML (SPA shell; carries no event data).
	 * @param string $source_url Source URL for origin resolution.
	 * @return array Array of normalized event arrays.
	 */
	public function extract( string $html, string $source_url ): array {
		$base_url = $this->resolveBaseUrl( $source_url );
		if ( empty( $base_url ) ) {
			return array();
		}

		$body = $this->fetchUrl(
			$base_url . '/api/events/upcoming',
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
			),
			'CHS Fermentory events API'
		);

		if ( empty( $body ) ) {
			return array();
		}

		$raw_events = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $raw_events ) ) {
			return array();
		}

		$events = array();
		foreach ( $raw_events as $raw_event ) {
			if ( ! is_array( $raw_event ) ) {
				continue;
			}

			$normalized = $this->normalizeEvent( $raw_event, $base_url );
			if ( ! empty( $normalized['title'] ) && ! empty( $normalized['startDate'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	/**
	 * Get the extraction method identifier.
	 *
	 * @return string Method identifier.
	 */
	public function getMethod(): string {
		return 'chs_fermentory';
	}

	/**
	 * Resolve the site origin, constrained to the known host.
	 *
	 * @param string $source_url Configured source URL.
	 * @return string Base URL (no trailing slash), or empty string.
	 */
	private function resolveBaseUrl( string $source_url ): string {
		$host = wp_parse_url( $source_url, PHP_URL_HOST );

		if ( empty( $host ) || ! str_contains( strtolower( $host ), self::SITE_HOST ) ) {
			return '';
		}

		return 'https://' . strtolower( $host );
	}

	/**
	 * Normalize a Fermentory event to the standard event format.
	 *
	 * @param array  $raw      Raw event object from /api/events/upcoming.
	 * @param string $base_url Site origin for relative image URLs.
	 * @return array Normalized event array.
	 */
	private function normalizeEvent( array $raw, string $base_url ): array {
		$start = $this->parseUtcDatetime( (string) ( $raw['eventDate'] ?? '' ), self::TIMEZONE );
		$end   = $this->parseUtcDatetime( (string) ( $raw['endDate'] ?? '' ), self::TIMEZONE );

		$description_parts = array();

		$description = $this->cleanHtml( (string) ( $raw['description'] ?? '' ) );
		if ( '' !== $description ) {
			$description_parts[] = $description;
		}

		$location_name = $this->sanitizeText( (string) ( $raw['locationName'] ?? '' ) );
		if ( '' !== $location_name ) {
			$description_parts[] = 'Location: ' . $location_name;
		}

		// End time on the same local day only; multi-day spans are unreliable.
		$end_time = ( '' !== $end['date'] && $end['date'] === $start['date'] ) ? $end['time'] : '';

		return array(
			'title'         => $this->sanitizeText( (string) ( $raw['title'] ?? '' ) ),
			'description'   => implode( "\n\n", $description_parts ),
			'startDate'     => $start['date'],
			'endDate'       => '',
			'startTime'     => $start['time'],
			'endTime'       => $end_time,
			'venue'         => self::VENUE_NAME,
			'venueTimezone' => self::TIMEZONE,
			'ticketUrl'     => '',
			'imageUrl'      => $this->resolveImageUrl( $raw, $base_url ),
			'price'         => '',
		);
	}

	/**
	 * Resolve the event image, falling back to the vendor's first photo.
	 *
	 * @param array  $raw      Raw event object.
	 * @param string $base_url Site origin for relative upload paths.
	 * @return string Image URL or empty string.
	 */
	private function resolveImageUrl( array $raw, string $base_url ): string {
		$image = $raw['imageUrl'] ?? '';

		if ( empty( $image ) || ! is_string( $image ) ) {
			$photos = $raw['vendor']['photos'] ?? array();
			if ( is_array( $photos ) && ! empty( $photos[0]['url'] ) && is_string( $photos[0]['url'] ) ) {
				$image = $photos[0]['url'];
			}
		}

		if ( empty( $image ) || ! is_string( $image ) ) {
			return '';
		}

		return esc_url_raw( $this->resolveUrl( $image, $base_url ) );
	}
}
