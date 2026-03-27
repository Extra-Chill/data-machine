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
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.15.5
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventbriteExtractor extends BaseExtractor {

	/**
	 * Check if this extractor can handle the given HTML content.
	 *
	 * Matches Eventbrite pages by checking for their organizer/event URL patterns
	 * in JSON-LD data or canonical link tags.
	 */
	public function canExtract( string $html ): bool {
		if ( strpos( $html, 'application/ld+json' ) === false ) {
			return false;
		}

		// Eventbrite organizer or event page markers.
		return strpos( $html, 'eventbrite.com/o/' ) !== false
			|| strpos( $html, 'eventbrite.com/e/' ) !== false
			|| strpos( $html, 'evbuc.com' ) !== false;
	}

	/**
	 * Extract all events from Eventbrite JSON-LD.
	 *
	 * Handles two patterns:
	 * 1. Organizer pages: ItemList > ListItem > Event (returns ALL events)
	 * 2. Single event pages: direct Event object
	 */
	public function extract( string $html, string $source_url ): array {
		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return array();
		}

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
						$parsed = $this->parseEventbriteEvent( $event_data );
						if ( null !== $parsed ) {
							$events[] = $parsed;
						}
					}
				}
			}

			// Pattern 2: Single Event object (individual event page).
			if ( isset( $data['@type'] ) && 'Event' === $data['@type'] ) {
				$parsed = $this->parseEventbriteEvent( $data );
				if ( null !== $parsed ) {
					$events[] = $parsed;
				}
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'eventbrite';
	}

	/**
	 * Parse an Eventbrite Event JSON-LD object to standardized format.
	 *
	 * @param array $event_data JSON-LD Event object.
	 * @return array|null Standardized event or null if invalid.
	 */
	private function parseEventbriteEvent( array $event_data ): ?array {
		$title = html_entity_decode( (string) ( $event_data['name'] ?? '' ) );

		if ( empty( $title ) ) {
			return null;
		}

		$event = array(
			'title'       => $title,
			'description' => $event_data['description'] ?? '',
		);

		$this->parseDates( $event, $event_data );

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
	 */
	private function parseDates( array &$event, array $event_data ): void {
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

		$event['venuePhone']   = $location['telephone'] ?? '';
		$event['venueWebsite'] = $location['url'] ?? '';

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
}
