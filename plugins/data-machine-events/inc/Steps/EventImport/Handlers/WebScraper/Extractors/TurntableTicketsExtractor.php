<?php
/**
 * Turntable Tickets extractor.
 *
 * Extracts event data from venues using the Turntable Tickets platform
 * (e.g. https://forte-jazz-lounge.turntabletickets.com/). The listing page
 * is a Vue SPA that server-renders its Pinia state into a
 * `window.__pinia = {...}` script tag containing:
 *
 * - `setting`: venue identity (venue_name, timezone, primary_domain, address).
 * - `performancePaginate.performances`: the first page of upcoming
 *   performances (show name, UTC datetime, local date/time, pricing, images).
 *
 * The embedded state only carries the first page (~8 performances with
 * `hasMore: true`), so after parsing the venue settings this extractor
 * queries the public JSON API (`/api/performance/?start_date=...&page=N`)
 * to collect the full upcoming calendar, falling back to the embedded
 * performances when the API is unavailable.
 *
 * Regression coverage for data-machine-events#941 (Forte Jazz Lounge,
 * flow 52 — UniversalWebScraper extracted 0 events from a page that embeds
 * full show data).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.68.1
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TurntableTicketsExtractor extends BaseExtractor {

	/**
	 * Maximum API pages to fetch (pageSize is 10 → up to 100 performances).
	 */
	private const MAX_API_PAGES = 10;

	/**
	 * Check if this page is a Turntable Tickets venue site.
	 *
	 * Requires both the platform marker and the server-rendered Pinia state
	 * so pages that merely link to turntabletickets.com are not claimed.
	 *
	 * @param string $html HTML content to check.
	 * @return bool True if Turntable Tickets markers are detected.
	 */
	public function canExtract( string $html ): bool {
		return strpos( $html, 'turntabletickets.com' ) !== false
			&& strpos( $html, 'window.__pinia' ) !== false;
	}

	/**
	 * Extract events from the embedded Pinia state and the performance API.
	 *
	 * @param string $html       HTML content containing the Pinia state.
	 * @param string $source_url Source URL for context.
	 * @return array Array of normalized event arrays.
	 */
	public function extract( string $html, string $source_url ): array {
		$state = $this->parsePiniaState( $html );

		if ( empty( $state ) ) {
			return array();
		}

		$setting  = is_array( $state['setting'] ?? null ) ? $state['setting'] : array();
		$base_url = $this->resolveBaseUrl( $setting, $source_url );
		$timezone = $this->resolveTimezone( $setting );

		// Full upcoming calendar from the public API; the embedded state only
		// carries the first page of performances.
		$performances = $this->fetchAllPerformances( $base_url, $timezone );

		if ( empty( $performances ) ) {
			$performances = $state['performancePaginate']['performances'] ?? array();
		}

		if ( empty( $performances ) || ! is_array( $performances ) ) {
			return array();
		}

		$events = array();
		foreach ( $performances as $performance ) {
			if ( ! is_array( $performance ) ) {
				continue;
			}

			$normalized = $this->normalizePerformance( $performance, $setting, $timezone, $base_url );
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
		return 'turntable_tickets';
	}

	/**
	 * Parse the server-rendered `window.__pinia = {...};` state object.
	 *
	 * @param string $html Page HTML.
	 * @return array Decoded Pinia state, or empty array.
	 */
	private function parsePiniaState( string $html ): array {
		$pos = strpos( $html, 'window.__pinia' );
		if ( false === $pos ) {
			return array();
		}

		$assign = strpos( $html, '=', $pos );
		$end    = stripos( $html, '</script>', $pos );
		if ( false === $assign || false === $end || $assign > $end ) {
			return array();
		}

		$json = trim( substr( $html, $assign + 1, $end - $assign - 1 ) );
		$json = rtrim( $json, "; \t\n\r" );

		$data = json_decode( $json, true );

		return ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) ? $data : array();
	}

	/**
	 * Resolve the venue's canonical base URL for API calls and ticket links.
	 *
	 * @param array  $setting    Pinia `setting` store.
	 * @param string $source_url Configured source URL.
	 * @return string Base URL (no trailing slash), or empty string.
	 */
	private function resolveBaseUrl( array $setting, string $source_url ): string {
		$domain = is_string( $setting['primary_domain'] ?? null ) ? trim( $setting['primary_domain'] ) : '';

		if ( '' !== $domain && preg_match( '/^[a-z0-9.-]+$/i', $domain ) ) {
			return 'https://' . $domain;
		}

		$parts = wp_parse_url( $source_url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $scheme . '://' . $parts['host'];
	}

	/**
	 * Backward-compat "US/*" timezone aliases → canonical IANA identifiers.
	 *
	 * Turntable Tickets reports zones like "US/Eastern", which some tzdata
	 * builds omit, so normalize to the canonical name before validating.
	 */
	private const TIMEZONE_ALIASES = array(
		'US/Eastern'  => 'America/New_York',
		'US/Central'  => 'America/Chicago',
		'US/Mountain' => 'America/Denver',
		'US/Pacific'  => 'America/Los_Angeles',
		'US/Alaska'   => 'America/Anchorage',
		'US/Arizona'  => 'America/Phoenix',
		'US/Hawaii'   => 'Pacific/Honolulu',
	);

	/**
	 * Resolve the venue timezone from settings.
	 *
	 * @param array $setting Pinia `setting` store.
	 * @return string Valid canonical IANA timezone identifier, or empty string.
	 */
	private function resolveTimezone( array $setting ): string {
		$timezone = is_string( $setting['timezone'] ?? null ) ? trim( $setting['timezone'] ) : '';
		$timezone = self::TIMEZONE_ALIASES[ $timezone ] ?? $timezone;

		return $this->isValidTimezone( $timezone ) ? $timezone : '';
	}

	/**
	 * Fetch all upcoming performances from the public JSON API.
	 *
	 * GET {base}/api/performance/?start_date=Y-m-d&page=N returns
	 * {"count": int, "pageSize": int, "results": [...]}.
	 *
	 * @param string $base_url Venue base URL.
	 * @param string $timezone Venue timezone (for the venue-local "today").
	 * @return array Raw performance objects, or empty array on failure.
	 */
	private function fetchAllPerformances( string $base_url, string $timezone ): array {
		if ( empty( $base_url ) ) {
			return array();
		}

		$start_date = $this->venueToday( $timezone );

		$performances = array();
		$page         = 1;

		do {
			$url  = $base_url . '/api/performance/?start_date=' . rawurlencode( $start_date ) . '&page=' . $page;
			$body = $this->fetchUrl(
				$url,
				array(
					'timeout' => 20,
					'headers' => array( 'Accept' => 'application/json' ),
				),
				'Turntable Tickets performance API'
			);

			if ( empty( $body ) ) {
				break;
			}

			$data = json_decode( $body, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
				break;
			}

			$results = $data['results'] ?? array();
			if ( empty( $results ) || ! is_array( $results ) ) {
				break;
			}

			$performances = array_merge( $performances, $results );

			$count     = (int) ( $data['count'] ?? 0 );
			$page_size = (int) ( $data['pageSize'] ?? count( $results ) );
			if ( $page_size <= 0 || count( $performances ) >= $count ) {
				break;
			}

			++$page;
		} while ( $page <= self::MAX_API_PAGES );

		return $performances;
	}

	/**
	 * Today's date (Y-m-d) in the venue timezone.
	 *
	 * @param string $timezone Venue timezone identifier (may be empty).
	 * @return string Date in Y-m-d format.
	 */
	private function venueToday( string $timezone ): string {
		try {
			$tz = new \DateTimeZone( '' !== $timezone ? $timezone : 'UTC' );
			return ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return gmdate( 'Y-m-d' );
		}
	}

	/**
	 * Normalize a Turntable Tickets performance to the standard event format.
	 *
	 * @param array  $performance Raw performance object.
	 * @param array  $setting     Pinia `setting` store (venue identity).
	 * @param string $timezone    Venue timezone identifier.
	 * @param string $base_url    Venue base URL for ticket links.
	 * @return array Normalized event array.
	 */
	private function normalizePerformance( array $performance, array $setting, string $timezone, string $base_url ): array {
		$show = is_array( $performance['show'] ?? null ) ? $performance['show'] : array();

		// Prefer the stable show name; residencies carry series labels like
		// "Vocal Jazz Series - 2026" which describe the recurring slot, not
		// the event itself.
		$title = $this->sanitizeText( (string) ( $show['name'] ?? '' ) );
		if ( '' === $title && ! empty( $performance['residencies'][0]['name'] ) ) {
			$title = $this->sanitizeText( (string) $performance['residencies'][0]['name'] );
		}

		// SSR state carries pre-localized date/time; the API only carries the
		// UTC instant which we convert to the venue timezone.
		$date = (string) ( $performance['date'] ?? '' );
		$time = (string) ( $performance['time'] ?? '' );

		if ( '' !== $date && '' !== $time ) {
			$parsed = $this->parseLocalDatetime( $date, $time, $timezone );
		} else {
			$parsed = $this->parseUtcDatetime( (string) ( $performance['datetime'] ?? '' ), $timezone );
		}

		$event = array(
			'title'       => $title,
			'description' => $this->cleanHtml( (string) ( $show['description'] ?? '' ) ),
			'startDate'   => $parsed['date'],
			'endDate'     => '',
			'startTime'   => $parsed['time'],
			'endTime'     => '',
			'venue'       => $this->sanitizeText( (string) ( $setting['venue_name'] ?? '' ) ),
			'ticketUrl'   => $this->buildTicketUrl( $performance, $parsed['date'], $base_url ),
			'imageUrl'    => $this->extractImageUrl( $show ),
			'price'       => $this->formatPrice( $show ),
		);

		if ( '' !== $timezone ) {
			$event['venueTimezone'] = $timezone;
		}

		foreach ( array(
			'venueAddress' => 'street_address',
			'venueCity'    => 'city',
			'venueState'   => 'state',
			'venueZip'     => 'postal_code',
			'venueCountry' => 'country',
		) as $event_key => $setting_key ) {
			if ( ! empty( $setting[ $setting_key ] ) && is_string( $setting[ $setting_key ] ) ) {
				$event[ $event_key ] = $this->sanitizeText( $setting[ $setting_key ] );
			}
		}

		return $event;
	}

	/**
	 * Build the show page URL used as the ticket link.
	 *
	 * The guest app routes shows at /shows/:showId/:date (venue-local Y-m-d).
	 *
	 * @param array  $performance Raw performance object.
	 * @param string $local_date  Venue-local event date (Y-m-d).
	 * @param string $base_url    Venue base URL.
	 * @return string Ticket URL, or empty string.
	 */
	private function buildTicketUrl( array $performance, string $local_date, string $base_url ): string {
		$show_id = (int) ( $performance['show_id'] ?? ( $performance['show']['id'] ?? 0 ) );

		if ( empty( $base_url ) || $show_id <= 0 ) {
			return '';
		}

		$url = $base_url . '/shows/' . $show_id;
		if ( '' !== $local_date ) {
			$url .= '/' . $local_date;
		}

		return esc_url_raw( $url );
	}

	/**
	 * Extract the best image URL from the show object.
	 *
	 * Prefers the largest rectangular rendition, falls back to the original.
	 *
	 * @param array $show Show object.
	 * @return string Image URL or empty string.
	 */
	private function extractImageUrl( array $show ): string {
		$srcset = is_array( $show['srcset'] ?? null ) ? $show['srcset'] : array();

		foreach ( array( 'rect2Xl', 'rectXl', 'original' ) as $key ) {
			$src = $srcset[ $key ]['src'] ?? '';
			if ( ! empty( $src ) && is_string( $src ) ) {
				return esc_url_raw( $src );
			}
		}

		$image = $show['image'] ?? '';

		return ( ! empty( $image ) && is_string( $image ) ) ? esc_url_raw( $image ) : '';
	}

	/**
	 * Format the show's per-person price range.
	 *
	 * @param array $show Show object with price_per_person [min, max].
	 * @return string Formatted price or empty string.
	 */
	private function formatPrice( array $show ): string {
		$prices = $show['price_per_person'] ?? array();
		if ( ! is_array( $prices ) || empty( $prices ) ) {
			return '';
		}

		$numeric = array_values( array_filter( $prices, 'is_numeric' ) );
		if ( empty( $numeric ) ) {
			return '';
		}

		$min = (float) min( $numeric );
		$max = (float) max( $numeric );

		return $this->formatPriceRange( $min, $max > $min ? $max : null );
	}
}
