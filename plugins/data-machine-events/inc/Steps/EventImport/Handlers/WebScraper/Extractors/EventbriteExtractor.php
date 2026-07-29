<?php
/**
 * Eventbrite extractor.
 *
 * Extracts all events from Eventbrite organizer pages by parsing
 * the Schema.org ItemList JSON-LD that Eventbrite embeds server-side.
 *
 * Unlike the generic JsonLdExtractor (which returns only the first event),
 * this extractor returns ALL events from the ItemList, allowing the
 * StructuredDataProcessor to filter past events and find eligible ones.
 *
 * Also handles individual Eventbrite event pages that contain a single
 * Event JSON-LD object.
 *
 * For recurring/series events, the JSON-LD startDate is the *first* occurrence
 * (often in the past). The extractor detects series events via the embedded
 * `isSeries`/`nextAvailableSession` fields and uses the next upcoming session
 * as the effective start date so the pipeline does not skip them as past events.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.15.5
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Core\DateTimeParser;
use DataMachineEvents\Core\VenueService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventbriteExtractor extends BaseExtractor {

	private const MAX_DISCOVERED_SOURCES = 25;

	/**
	 * Check if this extractor can handle the given HTML content.
	 *
	 * Matches Eventbrite pages by checking for their organizer/event URL patterns
	 * in JSON-LD data or canonical link tags.
	 */
	public function canExtract( string $html ): bool {
		return ( false !== strpos( $html, 'id="__NEXT_DATA__"' ) && false !== strpos( $html, 'organizer-profile' ) )
			|| ( false !== strpos( $html, 'application/ld+json' ) && $this->containsEventbriteEventUrl( $html ) )
			|| ! empty( $this->discoverEventbriteSources( $html ) );
	}

	/**
	 * Extract all events from Eventbrite JSON-LD.
	 *
	 * Handles three patterns:
	 * 1. Organizer pages: ItemList > ListItem > Event (returns ALL events)
	 * 2. Single event pages: direct Event object
	 * 3. Series/recurring events: detects isSeries flag and uses nextAvailableSession
	 */
	public function extract( string $html, string $source_url ): array {
		$events = array_merge(
			$this->extractJsonLdEvents( $html ),
			$this->extractOrganizerListingEvents( $html )
		);

		if ( empty( $events ) && ! $this->isEventbriteUrl( $source_url ) ) {
			foreach ( $this->discoverEventbriteSources( $html ) as $eventbrite_url ) {
				$eventbrite_html = $this->fetchUrl(
					$eventbrite_url,
					array(
						'timeout' => 30,
						'headers' => array( 'Accept' => 'text/html,application/xhtml+xml' ),
					),
					'Eventbrite public page'
				);

				if ( empty( $eventbrite_html ) ) {
					continue;
				}

				$events = array_merge(
					$events,
					$this->extractJsonLdEvents( $eventbrite_html ),
					$this->extractOrganizerListingEvents( $eventbrite_html )
				);
			}
		}

		return $this->deduplicateEvents( $events );
	}

	/**
	 * Extract Eventbrite Event objects from public JSON-LD.
	 */
	private function extractJsonLdEvents( string $html ): array {
		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return array();
		}

		$series_meta = $this->extractSeriesMeta( $html );

		$events = array();

		foreach ( $matches[1] as $json_content ) {
			$data = json_decode( trim( $json_content ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || empty( $data ) ) {
				continue;
			}

			// Pattern 1: ItemList with ListItem elements (organizer page).
			if ( isset( $data['@type'] ) && 'ItemList' === $data['@type'] && isset( $data['itemListElement'] ) ) {
				foreach ( $data['itemListElement'] as $list_item ) {
					if ( ! is_array( $list_item ) ) {
						continue;
					}

					$event_data = null;

					// ListItem wraps the actual Event.
					if ( isset( $list_item['@type'] ) && 'ListItem' === $list_item['@type'] && isset( $list_item['item'] ) ) {
						$nested = $list_item['item'];
						if ( isset( $nested['@type'] ) && 'Event' === $nested['@type'] ) {
							$event_data = $nested;
						}
					}

					// Direct Event in itemListElement (fallback).
					if ( null === $event_data && isset( $list_item['@type'] ) && 'Event' === $list_item['@type'] ) {
						$event_data = $list_item;
					}

					if ( null !== $event_data ) {
						$parsed = $this->parseEventbriteEvent( $event_data, $series_meta );
						if ( null !== $parsed ) {
							$events[] = $parsed;
						}
					}
				}
			}

			// Pattern 2: Single Event object (individual event page).
			if ( isset( $data['@type'] ) && 'Event' === $data['@type'] ) {
				$parsed = $this->parseEventbriteEvent( $data, $series_meta );
				if ( null !== $parsed ) {
					$events[] = $parsed;
				}
			}
		}

		return $events;
	}

	/**
	 * Extract events from the public server-rendered organizer profile payload.
	 */
	private function extractOrganizerListingEvents( string $html ): array {
		if ( ! preg_match( '/<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $match ) ) {
			return array();
		}

		$data = json_decode( trim( $match[1] ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return array();
		}

		$page_props = $data['props']['pageProps'] ?? array();
		$organizer  = $page_props['organizer'] ?? array();
		$raw_events = $page_props['upcomingEvents'] ?? array();

		if ( empty( $organizer['id'] ) || ! is_array( $raw_events ) ) {
			return array();
		}

		$events = array();
		foreach ( $raw_events as $raw_event ) {
			if ( ! is_array( $raw_event ) || ! empty( $raw_event['is_cancelled'] ) || ! empty( $raw_event['is_protected_event'] ) ) {
				continue;
			}

			$event = $this->parseOrganizerListingEvent( $raw_event, $organizer );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Map an organizer profile event card to the standard event shape.
	 */
	private function parseOrganizerListingEvent( array $raw_event, array $organizer ): ?array {
		$title      = html_entity_decode( (string) ( $raw_event['name'] ?? '' ) );
		$start_date = (string) ( $raw_event['start_date'] ?? '' );

		if ( '' === $title || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return null;
		}

		$venue   = $raw_event['primary_venue'] ?? array();
		$address = is_array( $venue['address'] ?? null ) ? $venue['address'] : array();
		$image   = is_array( $raw_event['image'] ?? null ) ? $raw_event['image'] : array();
		$event   = array(
			'title'         => $title,
			'description'   => $raw_event['summary'] ?? '',
			'startDate'     => $start_date,
			'startTime'     => $this->normalizeListingTime( $raw_event['start_time'] ?? '' ),
			'endDate'       => (string) ( $raw_event['end_date'] ?? '' ),
			'endTime'       => $this->normalizeListingTime( $raw_event['end_time'] ?? '' ),
			'venueTimezone' => $raw_event['timezone'] ?? '',
			'organizer'     => $organizer['name'] ?? '',
			'organizerUrl'  => $this->canonicalizeEventbriteUrl( $organizer['profilePageUrl'] ?? '' ),
			'ticketUrl'     => $this->canonicalizeEventbriteUrl( $raw_event['url'] ?? '' ),
			'imageUrl'      => $image['url'] ?? '',
			'venue'         => html_entity_decode( (string) ( $venue['name'] ?? '' ) ),
			'venueAddress'  => html_entity_decode( (string) ( $address['address_1'] ?? '' ) ),
			'venueCity'     => html_entity_decode( (string) ( $address['city'] ?? '' ) ),
			'venueState'    => html_entity_decode( (string) ( $address['region'] ?? '' ) ),
			'venueZip'      => $address['postal_code'] ?? '',
			'venueCountry'  => $address['country'] ?? '',
		);

		if ( ! empty( $address['latitude'] ) && ! empty( $address['longitude'] ) ) {
			$event['venueCoordinates'] = $address['latitude'] . ',' . $address['longitude'];
		}

		$availability   = $raw_event['ticket_availability'] ?? array();
		$minimum        = $availability['minimum_ticket_price']['major_value'] ?? null;
		$maximum        = $availability['maximum_ticket_price']['major_value'] ?? null;
		$currency       = $availability['minimum_ticket_price']['currency'] ?? 'USD';
		$is_free        = isset( $availability['is_free'] ) ? (bool) $availability['is_free'] : null;
		$event['price'] = $this->formatStructuredPrice(
			null !== $minimum ? (float) $minimum : null,
			null !== $maximum ? (float) $maximum : null,
			(string) $currency,
			$is_free
		);
		$this->normalizeEnd( $event );

		return $event;
	}

	/**
	 * Discover canonical organizer/event pages and documented checkout widgets.
	 */
	private function discoverEventbriteSources( string $html ): array {
		$sources = array();

		if ( preg_match_all( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $matches ) ) {
			foreach ( $matches[2] as $href ) {
				$url = $this->canonicalizeEventbriteUrl( html_entity_decode( $href ) );
				if ( '' !== $url ) {
					$sources[ $url ] = true;
				}
			}
		}

		if ( preg_match_all( '/EBWidgets\.createWidget\s*\(\s*\{.*?\beventId\s*:\s*(["\']?)(\d{6,})\1.*?\}\s*\)/is', $html, $matches ) ) {
			foreach ( $matches[2] as $event_id ) {
				$sources[ 'https://www.eventbrite.com/e/' . $event_id ] = true;
			}
		}

		return array_slice( array_keys( $sources ), 0, self::MAX_DISCOVERED_SOURCES );
	}

	/**
	 * Normalize a public Eventbrite organizer/event URL and remove tracking data.
	 */
	private function canonicalizeEventbriteUrl( string $url ): string {
		$url   = esc_url_raw( trim( $url ) );
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		if ( 'eventbrite.com' !== $host && ! str_ends_with( $host, '.eventbrite.com' ) ) {
			return '';
		}

		if ( ! preg_match( '#^/(?:o|e)/[^/]+(?:/)?$#i', $parts['path'] ) ) {
			return '';
		}

		return 'https://www.eventbrite.com' . rtrim( $parts['path'], '/' );
	}

	private function isEventbriteUrl( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'eventbrite.com' === $host || str_ends_with( $host, '.eventbrite.com' );
	}

	private function containsEventbriteEventUrl( string $html ): bool {
		return (bool) preg_match( '#https?://(?:[^/]+\.)?eventbrite\.com/(?:o|e)/#i', $html );
	}

	private function normalizeListingTime( string $time ): string {
		return preg_match( '/^(\d{2}:\d{2})(?::\d{2})?$/', $time, $match ) ? $match[1] : '';
	}

	private function deduplicateEvents( array $events ): array {
		$deduplicated = array();
		foreach ( $events as $event ) {
			$key = $event['ticketUrl'] ?? ( ( $event['title'] ?? '' ) . '|' . ( $event['startDate'] ?? '' ) );
			if ( '' !== $key ) {
				$deduplicated[ $key ] = $event;
			}
		}

		return array_values( $deduplicated );
	}

	public function getMethod(): string {
		return 'eventbrite';
	}

	/**
	 * Parse an Eventbrite Event JSON-LD object to standardized format.
	 *
	 * @param array $event_data JSON-LD Event object.
	 * @param array $series_meta Series metadata extracted from page (isSeries, nextAvailableSession).
	 * @return array|null Standardized event or null if invalid.
	 */
	private function parseEventbriteEvent( array $event_data, array $series_meta = array() ): ?array {
		$title = html_entity_decode( (string) ( $event_data['name'] ?? '' ) );

		if ( empty( $title ) ) {
			return null;
		}

		$event = array(
			'title'       => $title,
			'description' => $event_data['description'] ?? '',
		);

		$this->parseDates( $event, $event_data, $series_meta );

		if ( empty( $event['startDate'] ) ) {
			return null;
		}

		$this->parsePerformer( $event, $event_data );
		$this->parseOrganizer( $event, $event_data );
		$this->parseLocation( $event, $event_data );
		$this->parseOffers( $event, $event_data );
		$this->parseImage( $event, $event_data );

		return $event;
	}

	/**
	 * Parse date/time from Eventbrite ISO 8601 datetime strings.
	 *
	 * For series events where the JSON-LD startDate is in the past,
	 * uses nextAvailableSession from the page's embedded data as the
	 * effective start date.
	 *
	 * @param array $event      Event array to populate (passed by reference).
	 * @param array $event_data JSON-LD Event object.
	 * @param array $series_meta Series metadata from extractSeriesMeta().
	 */
	private function parseDates( array &$event, array $event_data, array $series_meta = array() ): void {
		if ( ! empty( $event_data['startDate'] ) ) {
			$parsed             = $this->parseIsoDatetime( $event_data['startDate'] );
			$event['startDate'] = $parsed['date'];
			$event['startTime'] = '00:00' !== $parsed['time'] ? $parsed['time'] : '';

			if ( ! empty( $parsed['timezone'] ) ) {
				$event['venueTimezone'] = $parsed['timezone'];
			}
		}

		if ( ! empty( $event_data['endDate'] ) ) {
			$parsed           = $this->parseIsoDatetime( $event_data['endDate'] );
			$event['endDate'] = $parsed['date'];
			$event['endTime'] = $parsed['time'];
		}

		if ( ! empty( $series_meta['nextAvailableSession'] ) ) {
			$next_session = $series_meta['nextAvailableSession'];
			$parsed       = $this->parseIsoDatetime( $next_session );

			$event['startDate'] = $parsed['date'];
			$event['startTime'] = '00:00' !== $parsed['time'] ? $parsed['time'] : '';

			if ( ! empty( $parsed['timezone'] ) ) {
				$event['venueTimezone'] = $parsed['timezone'];
			}
		}

		$this->normalizeEnd( $event );
	}

	/**
	 * Omit unusable Eventbrite ends so canonical storage can represent unknown duration.
	 */
	private function normalizeEnd( array &$event ): void {
		$start_date = trim( (string) ( $event['startDate'] ?? '' ) );
		$start_time = trim( (string) ( $event['startTime'] ?? '' ) );
		$end_date   = trim( (string) ( $event['endDate'] ?? '' ) );
		$end_time   = trim( (string) ( $event['endTime'] ?? '' ) );
		$time_regex = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

		$valid_date = DateTimeParser::isValidYmd( $start_date ) && DateTimeParser::isValidYmd( $end_date );
		$valid_time = ( '' === $start_time || preg_match( $time_regex, $start_time ) )
			&& preg_match( $time_regex, $end_time );
		$positive   = $valid_date && $valid_time;
		if ( $positive ) {
			$start_datetime = $start_date . ' ' . ( '' !== $start_time ? $start_time : '00:00' );
			$end_datetime   = $end_date . ' ' . $end_time;
			$positive       = $end_datetime > $start_datetime;
		}

		if ( ! $positive ) {
			unset( $event['endDate'], $event['endTime'] );
		}
	}

	/**
	 * Parse performer from Eventbrite event.
	 */
	private function parsePerformer( array &$event, array $event_data ): void {
		if ( empty( $event_data['performer'] ) ) {
			return;
		}

		$performer = $event_data['performer'];
		if ( is_array( $performer ) ) {
			$event['performer'] = $performer['name'] ?? $performer[0]['name'] ?? '';
		} else {
			$event['performer'] = $performer;
		}
	}

	/**
	 * Parse organizer from Eventbrite event.
	 */
	private function parseOrganizer( array &$event, array $event_data ): void {
		if ( empty( $event_data['organizer'] ) ) {
			return;
		}

		$organizer = $event_data['organizer'];
		if ( is_array( $organizer ) ) {
			$event['organizer']    = $organizer['name'] ?? '';
			$event['organizerUrl'] = $organizer['url'] ?? '';
		} else {
			$event['organizer'] = $organizer;
		}
	}

	/**
	 * Parse location from Eventbrite event.
	 */
	private function parseLocation( array &$event, array $event_data ): void {
		if ( empty( $event_data['location'] ) ) {
			return;
		}

		$location       = $event_data['location'];
		$event['venue'] = html_entity_decode( (string) ( $location['name'] ?? '' ) );

		if ( ! empty( $location['address'] ) ) {
			$address               = $location['address'];
			$event['venueAddress'] = html_entity_decode( (string) ( $address['streetAddress'] ?? '' ) );
			$event['venueCity']    = html_entity_decode( (string) ( $address['addressLocality'] ?? '' ) );
			$event['venueState']   = html_entity_decode( (string) ( $address['addressRegion'] ?? '' ) );
			$event['venueZip']     = $address['postalCode'] ?? '';
			$event['venueCountry'] = $address['addressCountry'] ?? '';
		}

		$event['venuePhone'] = $location['telephone'] ?? '';
		$location_url        = esc_url_raw( $location['url'] ?? '' );
		if ( VenueService::is_ticketing_url( $location_url ) ) {
			$event['venueTicketingUrl'] = $location_url;
		} elseif ( '' !== $location_url ) {
			$event['venueWebsite'] = $location_url;
		}

		if ( ! empty( $location['geo'] ) ) {
			$geo = $location['geo'];
			$lat = $geo['latitude'] ?? '';
			$lng = $geo['longitude'] ?? '';
			if ( $lat && $lng ) {
				$event['venueCoordinates'] = $lat . ',' . $lng;
			}
		}
	}

	/**
	 * Parse offers/pricing from Eventbrite event.
	 *
	 * Eventbrite uses AggregateOffer with lowPrice/highPrice.
	 */
	private function parseOffers( array &$event, array $event_data ): void {
		$offers = $event_data['offers'] ?? array();

		if ( empty( $offers ) ) {
			$event['ticketUrl'] = $event_data['url'] ?? '';
			return;
		}

		// Normalize: Eventbrite uses a single AggregateOffer, not an array.
		if ( isset( $offers[0] ) ) {
			$offers = $offers[0];
		}

		$low_price  = $offers['lowPrice'] ?? $offers['price'] ?? null;
		$high_price = $offers['highPrice'] ?? null;
		$currency   = $offers['priceCurrency'] ?? 'USD';

		$event['price'] = $this->formatStructuredPrice(
			null !== $low_price ? (float) $low_price : null,
			null !== $high_price ? (float) $high_price : null,
			$currency
		);

		// Ticket URL: offers.url first, then fall back to event-level url.
		$event['ticketUrl'] = $offers['url'] ?? $event_data['url'] ?? '';
	}

	/**
	 * Parse image from Eventbrite event.
	 */
	private function parseImage( array &$event, array $event_data ): void {
		if ( empty( $event_data['image'] ) ) {
			return;
		}

		$image = $event_data['image'];
		if ( is_array( $image ) ) {
			$event['imageUrl'] = $image['url'] ?? $image[0] ?? '';
		} else {
			$event['imageUrl'] = $image;
		}
	}

	/**
	 * Extract series/recurring event metadata from the Eventbrite page HTML.
	 *
	 * Eventbrite embeds series information outside of JSON-LD in the page's
	 * server-rendered data. This method parses:
	 * - `isSeries`: Whether the event is a recurring series
	 * - `nextAvailableSession`: The next upcoming occurrence datetime
	 *
	 * The data is embedded in a JSON-like structure near `goodToKnow.highlights`.
	 *
	 * @param string $html Full page HTML.
	 * @return array{isSeries: bool, nextAvailableSession: string}
	 */
	private function extractSeriesMeta( string $html ): array {
		$meta = array(
			'isSeries'             => false,
			'nextAvailableSession' => '',
		);

		if ( false === strpos( $html, '"isSeries":true' ) ) {
			return $meta;
		}

		$meta['isSeries'] = true;

		if ( preg_match( '/"nextAvailableSession":"([^"]+)"/', $html, $match ) ) {
			$meta['nextAvailableSession'] = $match[1];
		}

		return $meta;
	}
}
