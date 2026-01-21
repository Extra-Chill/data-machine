<?php
/**
 * JSON-LD extractor.
 *
 * Extracts event data from Schema.org JSON-LD structured data
 * embedded in script tags with type="application/ld+json".
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsonLdExtractor extends BaseExtractor {

	public function canExtract( string $html ): bool {
		return strpos( $html, 'application/ld+json' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return array();
		}

		foreach ( $matches[1] as $json_content ) {
			$data = json_decode( trim( $json_content ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || empty( $data ) ) {
				continue;
			}

			$event = $this->findAndParseEvent( $data, $source_url );
			if ( null !== $event ) {
				return array( $event );
			}
		}

		return array();
	}

	public function getMethod(): string {
		return 'jsonld';
	}

	/**
	 * Find and parse Event object from JSON-LD data.
	 *
	 * @param array $data JSON-LD data
	 * @param string $source_url Source URL
	 * @return array|null Parsed event or null
	 */
	private function findAndParseEvent( array $data, string $source_url ): ?array {
		if ( isset( $data['@type'] ) && 'Event' === $data['@type'] ) {
			return $this->parseEvent( $data, $source_url );
		}

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $item ) {
				if ( isset( $item['@type'] ) && 'Event' === $item['@type'] ) {
					return $this->parseEvent( $item, $source_url );
				}
			}
		}

		// Handle ItemList with ListItem elements (Eventbrite pattern)
		if ( isset( $data['@type'] ) && 'ItemList' === $data['@type'] && isset( $data['itemListElement'] ) ) {
			foreach ( $data['itemListElement'] as $list_item ) {
				if ( ! is_array( $list_item ) ) {
					continue;
				}
				// ListItem wraps the actual item
				if ( isset( $list_item['@type'] ) && 'ListItem' === $list_item['@type'] && isset( $list_item['item'] ) ) {
					$nested = $list_item['item'];
					if ( isset( $nested['@type'] ) && 'Event' === $nested['@type'] ) {
						return $this->parseEvent( $nested, $source_url );
					}
				}
				// Direct Event in itemListElement (fallback)
				if ( isset( $list_item['@type'] ) && 'Event' === $list_item['@type'] ) {
					return $this->parseEvent( $list_item, $source_url );
				}
			}
		}

		if ( $this->isList( $data ) ) {
			foreach ( $data as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( isset( $item['@type'] ) && 'Event' === $item['@type'] ) {
					return $this->parseEvent( $item, $source_url );
				}

				if ( isset( $item['@graph'] ) && is_array( $item['@graph'] ) ) {
					foreach ( $item['@graph'] as $graph_item ) {
						if ( isset( $graph_item['@type'] ) && 'Event' === $graph_item['@type'] ) {
							return $this->parseEvent( $graph_item, $source_url );
						}
					}
				}
			}
		}

		return null;
	}

	private function isList( array $data ): bool {
		if ( empty( $data ) ) {
			return false;
		}

		return array_keys( $data ) === range( 0, count( $data ) - 1 );
	}

	/**
	 * Parse JSON-LD Event object to standardized format.
	 *
	 * @param array $event_data JSON-LD Event object
	 * @param string $source_url Source URL
	 * @return array|null Standardized event or null if invalid
	 */
	private function parseEvent( array $event_data, string $source_url ): ?array {
		$event = array(
			'title'       => html_entity_decode( (string) ( $event_data['name'] ?? '' ) ),
			'description' => $event_data['description'] ?? '',
		);

		$this->parseDates( $event, $event_data );
		$this->parsePerformerAndOrganizer( $event, $event_data );
		$this->parseLocation( $event, $event_data );
		$this->parseOffers( $event, $event_data );
		$this->parseImage( $event, $event_data );

		if ( empty( $event['title'] ) || empty( $event['startDate'] ) ) {
			return null;
		}

		return $event;
	}

	/**
	 * Parse date/time from JSON-LD event.
	 *
	 * JSON-LD dates typically include timezone offset (ISO 8601 format).
	 */
	private function parseDates( array &$event, array $event_data ): void {
		if ( ! empty( $event_data['startDate'] ) ) {
			$parsed             = $this->parseDatetime( $event_data['startDate'] );
			$event['startDate'] = $parsed['date'];
			$event['startTime'] = '00:00' !== $parsed['time'] ? $parsed['time'] : '';
		}

		if ( ! empty( $event_data['endDate'] ) ) {
			$parsed           = $this->parseDatetime( $event_data['endDate'] );
			$event['endDate'] = $parsed['date'];
			$event['endTime'] = $parsed['time'];
		}
	}

	/**
	 * Parse performer and organizer from JSON-LD event.
	 */
	private function parsePerformerAndOrganizer( array &$event, array $event_data ): void {
		if ( ! empty( $event_data['performer'] ) ) {
			$performer = $event_data['performer'];
			if ( is_array( $performer ) ) {
				$event['performer'] = $performer['name'] ?? $performer[0]['name'] ?? '';
			} else {
				$event['performer'] = $performer;
			}
		}

		if ( ! empty( $event_data['organizer'] ) ) {
			$organizer = $event_data['organizer'];
			if ( is_array( $organizer ) ) {
				$event['organizer'] = $organizer['name'] ?? $organizer[0]['name'] ?? '';
			} else {
				$event['organizer'] = $organizer;
			}
		}
	}

	/**
	 * Parse location from JSON-LD event.
	 */
	private function parseLocation( array &$event, array $event_data ): void {
		if ( empty( $event_data['location'] ) ) {
			return;
		}

		$location       = $event_data['location'];
		$event['venue'] = html_entity_decode( (string) ( $location['name'] ?? '' ) );

		if ( ! empty( $location['address'] ) ) {
			$address               = $location['address'];
			$event['venueAddress'] = $address['streetAddress'] ?? '';
			$event['venueCity']    = $address['addressLocality'] ?? '';
			$event['venueState']   = $address['addressRegion'] ?? '';
			$event['venueZip']     = $address['postalCode'] ?? '';
			$event['venueCountry'] = $address['addressCountry'] ?? '';
		}

		if ( ! empty( $event['venueAddress'] ) ) {
			$event['venueAddress'] = html_entity_decode( $event['venueAddress'] );
		}
		if ( ! empty( $event['venueCity'] ) ) {
			$event['venueCity'] = html_entity_decode( $event['venueCity'] );
		}
		if ( ! empty( $event['venueState'] ) ) {
			$event['venueState'] = html_entity_decode( $event['venueState'] );
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
	 * Parse offers/pricing from JSON-LD event.
	 */
	private function parseOffers( array &$event, array $event_data ): void {
		if ( empty( $event_data['offers'] ) ) {
			return;
		}

		$offers = $event_data['offers'];
		if ( is_array( $offers ) && isset( $offers[0] ) ) {
			$offers = $offers[0];
		}

		$event['price']     = $offers['price'] ?? '';
		$event['ticketUrl'] = $offers['url'] ?? '';
	}

	/**
	 * Parse image from JSON-LD event.
	 */
	private function parseImage( array &$event, array $event_data ): void {
		if ( empty( $event_data['image'] ) ) {
			return;
		}

		$image = $event_data['image'];
		if ( is_array( $image ) ) {
			$event['imageUrl'] = $image[0] ?? '';
		} else {
			$event['imageUrl'] = $image;
		}
	}
}
